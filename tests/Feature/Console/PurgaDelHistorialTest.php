<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El historial de cambios no se purgaba nunca, y guardaba copias de personas.
 *
 * `audit_logs` es append-only y no tiene `deleted_at`, así que
 * `app:purge-soft-deleted` —que recorre los módulos de `config/purge.php`— ni
 * la miraba. La consecuencia no es que la tabla crezca: es que en
 * `old_values`/`new_values` hay **copias literales de las filas auditadas**,
 * con el nombre, el documento y el teléfono de cada persona, y ahí siguen años
 * después de que esa persona se borrara del padrón. Se purgaba la ficha y se
 * dejaba intacta la copia.
 *
 * Lo tentador —borrar todo lo viejo y listo— rompe la otra mitad: un historial
 * vacío no sirve para auditar nada, y la pregunta que se le hace a esta tabla
 * («¿quién tocó esto y cuándo?») no necesita los datos de nadie para
 * contestarse. De ahí que la política tenga dos fases y tres plazos, y de ahí
 * estas pruebas, que son las cuatro cosas que se pueden hacer mal:
 *
 *   1. no borrar lo que ya pasó su plazo (el agujero original),
 *   2. borrar lo que todavía no lo pasó,
 *   3. tirar el rastro junto con el contenido, dejando el historial inútil,
 *   4. medir los eventos de seguridad con el reloj de un catálogo — que es lo
 *      que hace que a los dos años no se pueda contestar quién entró.
 */
class PurgaDelHistorialTest extends TestCase
{
    use RefreshDatabase;

    /** Una fila del historial con la edad y la forma que pida cada prueba. */
    protected function anotar(array $atributos = []): AuditLog
    {
        $diasDeAntiguedad = $atributos['dias'] ?? 0;
        unset($atributos['dias']);

        $fila = AuditLog::create(array_merge([
            'user_id'        => null,
            'event'          => 'updated',
            'auditable_type' => \App\Models\WorkType::class,
            'auditable_id'   => 1,
            'module'         => 'work_types',
            'old_values'     => ['name' => 'Trabajo en altura'],
            'new_values'     => ['name' => 'Trabajo en altura con arnés'],
            'ip_address'     => '10.0.0.9',
        ], $atributos));

        // `created_at` no está en `$fillable`, así que `create()` lo ignora y
        // pone la hora actual. Se envejece la fila después, por debajo del
        // modelo: es la columna sobre la que corre toda la política.
        DB::table('audit_logs')->where('id', $fila->id)->update([
            'created_at' => now()->subDays($diasDeAntiguedad),
        ]);

        return $fila->refresh();
    }

    /** Fija un plazo desde Ajustes, que es como se tocan en producción. */
    protected function ajustar(string $clave, int $dias): void
    {
        DB::table('settings')->insertOrIgnore([[
            'key' => $clave, 'slug' => Str::random(22), 'name' => $clave, 'type' => 'int',
            'value' => (string) $dias, 'group' => 'audit', 'description' => '',
            'is_secret' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);

        \App\Models\Setting::flushCache();
    }

    /**
     * El agujero, en una línea: lo viejo se va, lo reciente se queda.
     *
     * El plazo corriente por defecto es de 365 días. Una fila de hace dos años
     * llevaba desde siempre en la tabla; ahora se borra. Y la de anteayer no,
     * que es la otra mitad del acierto.
     */
    public function test_borra_el_rastro_corriente_pasado_su_plazo_y_respeta_el_reciente(): void
    {
        $vieja   = $this->anotar(['dias' => 800]);
        $reciente = $this->anotar(['dias' => 2]);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $vieja->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $reciente->id]);
    }

    /**
     * La fase que de verdad cierra el agujero de privacidad.
     *
     * A los 180 días se vacían las dos columnas JSON y **la fila se queda**. Se
     * comprueban las dos cosas a la vez porque cada una sin la otra sería un
     * fallo distinto: si sobrevive el contenido no se ha protegido a nadie, y
     * si desaparece la fila se ha tirado el rastro medio año antes de tiempo.
     */
    public function test_poda_el_contenido_a_los_180_dias_y_deja_la_fila_en_pie(): void
    {
        $fila = $this->anotar(['dias' => 200]);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $podada = AuditLog::find($fila->id);

        $this->assertNotNull($podada, 'la fila no debía borrarse, solo vaciarse');
        $this->assertNull($podada->old_values);
        $this->assertNull($podada->new_values);

        // Lo que tiene que sobrevivir: quién, qué, cuándo y desde dónde.
        $this->assertSame('updated', $podada->event);
        $this->assertSame('work_types', $podada->module);
        $this->assertSame(1, $podada->auditable_id);
        $this->assertSame('10.0.0.9', $podada->ip_address);

        // Y queda dicho que se podó: una fila antigua con los valores vacíos y
        // sin explicación se lee como «no cambió nada», que es lo contrario.
        $this->assertStringContainsString('podado', (string) $podada->note);
    }

    /**
     * La poda no pisa lo que escribió una persona.
     *
     * `note` lleva a veces el motivo de un borrado, tecleado por alguien. La
     * marca de la poda se añade detrás; machacarla sería destruir justo el
     * texto que más explica de toda la fila.
     */
    public function test_la_poda_conserva_la_nota_que_ya_traia_la_fila(): void
    {
        $fila = $this->anotar(['dias' => 200, 'note' => 'Se corrigió a pedido del supervisor.']);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $nota = AuditLog::find($fila->id)->note;

        $this->assertStringContainsString('Se corrigió a pedido del supervisor.', $nota);
        $this->assertStringContainsString('podado', $nota);
    }

    /**
     * Idempotencia: la segunda noche no vuelve a marcar la misma fila.
     *
     * Sin el filtro por contenido no nulo, cada corrida le pegaría otra marca a
     * la misma fila y en un año la nota sería una tira de trescientas marcas.
     */
    public function test_una_fila_ya_podada_no_se_vuelve_a_marcar(): void
    {
        $fila = $this->anotar(['dias' => 200]);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();
        $primera = AuditLog::find($fila->id)->note;

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertSame($primera, AuditLog::find($fila->id)->note);
    }

    /**
     * Un acceso no se mide con el reloj de un catálogo.
     *
     * A los dos años, el `updated` de un tipo de trabajo ya no le importa a
     * nadie y el `login_lockout` de esa misma noche es exactamente lo que se
     * quiere poder mirar. Con un solo plazo los dos se van juntos, y la
     * revisión de accesos se queda sin nada que revisar.
     */
    public function test_los_eventos_de_seguridad_sobreviven_al_plazo_corriente(): void
    {
        $catalogo = $this->anotar(['dias' => 500]);
        $bloqueo  = $this->anotar([
            'dias' => 500, 'event' => 'login_lockout', 'module' => 'users',
            'old_values' => null, 'new_values' => ['intento' => 'jefe@obra.pe'],
        ]);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $catalogo->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $bloqueo->id]);

        // Y su contenido tampoco se podó: en un intento contra una cuenta, el
        // correo con el que se probó ES el hallazgo.
        $this->assertSame(['intento' => 'jefe@obra.pe'], AuditLog::find($bloqueo->id)->new_values);
    }

    /**
     * Una firma en obra es un evento de seguridad aunque el evento se llame
     * `created`.
     *
     * La clasificación no puede ir solo por nombre de evento: en
     * `person_signatures` y en `work_plan_approvals` un `created` significa
     * «alguien firmó» y «alguien aprobó un plan». Si eso se mide como un alta
     * corriente, la prueba de quién firmó se va al año.
     */
    public function test_las_firmas_y_aprobaciones_se_clasifican_por_modulo(): void
    {
        $firma     = $this->anotar(['dias' => 500, 'event' => 'created', 'module' => 'person_signatures']);
        $aprobacion = $this->anotar(['dias' => 500, 'event' => 'created', 'module' => 'work_plan_approvals']);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $firma->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $aprobacion->id]);
    }

    /**
     * Pasado SU plazo, la de seguridad también se va.
     *
     * «Más tiempo» no es «para siempre»: si nada se borrase nunca, la política
     * no sería una política. A los 1095 días le toca.
     */
    public function test_el_evento_de_seguridad_se_borra_pasado_su_propio_plazo(): void
    {
        $antiguo = $this->anotar(['dias' => 1200, 'event' => 'login', 'module' => 'users']);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $antiguo->id]);
    }

    /**
     * Ninguna fila se queda entre las dos fases.
     *
     * Las filas de acceso llevan `module` puesto, pero una fila con `module`
     * nulo es perfectamente posible. En SQL un `NOT IN` contra NULL no da
     * verdadero, así que sin el `orWhereNull` esa fila no la reclamaría ni la
     * fase corriente ni la de seguridad: se quedaría en la tabla para siempre,
     * en silencio y con su contenido dentro.
     */
    public function test_una_fila_sin_modulo_no_se_escapa_de_las_dos_fases(): void
    {
        $huerfana = $this->anotar(['dias' => 800, 'module' => null]);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $huerfana->id]);
    }

    /**
     * `--dry-run` no toca nada. Es la opción con la que se comprueba una
     * política nueva antes de dejarla suelta contra la tabla de producción, y
     * si borrase algo no serviría para eso.
     */
    public function test_dry_run_no_borra_ni_poda(): void
    {
        $vieja = $this->anotar(['dias' => 800]);
        $poda  = $this->anotar(['dias' => 200]);

        $this->artisan('app:purge-audit-logs', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $vieja->id]);
        $this->assertNotNull(AuditLog::find($poda->id)->new_values);

        // Y tampoco escribe el resumen: un simulacro que deja rastro de purga
        // en el historial estaría contando una purga que no ocurrió.
        $this->assertDatabaseMissing('audit_logs', ['event' => 'purged']);
    }

    /**
     * El simulacro no cuenta dos veces la misma fila.
     *
     * Una fila de 800 días pasa los dos cortes: le tocaría poda y le toca
     * borrado. En la corrida de verdad no hay confusión posible —se borra
     * primero, y cuando llega la poda ya no está—, pero en `--dry-run` no se
     * borra nada y sin cuidado saldría contada en las dos cifras. Sería
     * inflarle el número justo al modo cuyo único trabajo es dar uno fiable
     * antes de soltar el comando contra la tabla de producción.
     */
    public function test_el_simulacro_no_cuenta_la_misma_fila_como_podada_y_borrada(): void
    {
        $this->anotar(['dias' => 800]);

        $this->artisan('app:purge-audit-logs', ['--dry-run' => true])
            ->expectsOutputToContain('Se podarían 0 filas y se borrarían 1.')
            ->assertSuccessful();
    }

    /**
     * Los plazos se mueven desde Ajustes, sin redeploy.
     *
     * Es la mitad configurable de la política: el número de `config/purge.php`
     * es un defecto de fábrica, y el cliente que tenga un plazo propio lo pone
     * desde la pantalla. Se comprueba con el caso que más se va a dar —bajarlo—
     * porque es el que borra datos que el defecto habría conservado.
     */
    public function test_el_ajuste_de_la_base_manda_sobre_el_defecto_del_config(): void
    {
        $fila = $this->anotar(['dias' => 100]);

        $this->ajustar('audit.retention_days', 60);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $fila->id]);
    }

    /**
     * Subir la retención general nunca acorta la de seguridad.
     *
     * El caso: alguien pone la retención general en 5 años y deja la de
     * seguridad en los 3 de fábrica. Lo que quiso decir es «guarda más»; leído
     * al pie de la letra, los accesos se seguirían tirando a los 3 años
     * mientras el `updated` de un catálogo aguanta 5. El comando aplica el
     * mayor de los dos.
     */
    public function test_la_retencion_de_seguridad_nunca_queda_por_debajo_de_la_general(): void
    {
        $acceso = $this->anotar(['dias' => 1500, 'event' => 'login', 'module' => 'users']);

        $this->ajustar('audit.retention_days', 1825);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $acceso->id]);
    }

    /**
     * Un plazo en 0 desactiva SU fase, no las otras.
     *
     * Sirve para el cliente que quiera conservar el rastro entero y solo podar
     * el contenido, o al revés. Y protege del error tonto: un ajuste sin
     * sembrar vale 0, y si el comando lo leyera como «plazo cero» borraría el
     * historial completo la primera noche.
     */
    public function test_un_plazo_en_cero_desactiva_solo_su_fase(): void
    {
        config(['purge.audit_logs.days' => 0, 'purge.audit_logs.security_days' => 0]);

        $vieja = $this->anotar(['dias' => 2000]);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $sobrevive = AuditLog::find($vieja->id);

        $this->assertNotNull($sobrevive, 'con el borrado desactivado la fila se queda');
        $this->assertNull($sobrevive->new_values, 'pero la poda del contenido sigue corriendo');
    }

    /**
     * La purga se anota a sí misma, y se anota donde no se va a borrar pronto.
     *
     * Destruir datos sin dejar constancia es lo único de esta tabla que después
     * no se puede reconstruir con nada. El resumen es un `purged`, así que le
     * toca el plazo de seguridad y su contenido no se poda: los números de lo
     * que se destruyó tienen que seguir legibles dentro de tres años.
     */
    public function test_deja_constancia_de_lo_que_destruyo(): void
    {
        $this->anotar(['dias' => 800]);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $resumen = AuditLog::where('event', 'purged')->where('module', 'audit_logs')->first();

        $this->assertNotNull($resumen);
        $this->assertSame(1, $resumen->new_values['borradas']);
        $this->assertSame(365, $resumen->new_values['dias_corrientes']);
        $this->assertSame(1095, $resumen->new_values['dias_seguridad']);

        // Y el `purged` está en la lista de contenido intocable.
        $this->assertContains('purged', config('purge.audit_logs.keep_payload_events'));
    }

    /**
     * Sin nada elegible no escribe un resumen de cero.
     *
     * Corre todas las noches; anotar «0 borradas» cada madrugada llenaría de
     * ruido justo la tabla que se está intentando mantener legible.
     */
    public function test_una_corrida_sin_nada_que_hacer_no_ensucia_el_historial(): void
    {
        $this->anotar(['dias' => 3]);

        $this->artisan('app:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'purged']);
    }
}
