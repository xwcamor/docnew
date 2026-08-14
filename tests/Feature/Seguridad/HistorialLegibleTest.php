<?php

namespace Tests\Feature\Seguridad;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Support\ValoresAuditados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El historial sigue siendo legible despues de cifrar las columnas.
 *
 * LA REGRESION QUE CIERRA
 * -----------------------
 * `Auditable` escribe en `audit_logs` los valores **tal y como van a la base**,
 * y eso es lo correcto: seria absurdo cifrar una columna y dejar una copia en
 * claro en la tabla de al lado, que ademas guarda cada version anterior de cada
 * fila. La consecuencia, que no vimos hasta despues, es que desde que el
 * documento va cifrado el historial enseñaba
 *
 *     Documento: eyJpdiI6Ik5q… → eyJpdiI6IlR4…
 *
 * donde antes ponia el DNI. En la ficha de cada persona y, peor, en el modulo
 * de Auditoria, que es el que abre precisamente quien viene a auditar.
 *
 * El sobre se abre al PINTAR. Y con la MISMA regla de quien ve que en el resto
 * del sistema: el enmascarado del listado de personas no serviria de nada si el
 * mismo DNI saliera entero en la pestaña de al lado.
 */
class HistorialLegibleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Con permiso de datos privados, el documento se lee entero. */
    public function test_con_permiso_el_documento_del_historial_se_lee(): void
    {
        $legibles = ValoresAuditados::legibles(
            ['num_doc' => \Illuminate\Support\Facades\Crypt::encryptString('44556677')],
            $this->usuarioCon(['people.view_private_info']),
        );

        $this->assertSame('44556677', $legibles['num_doc']);
    }

    /**
     * Sin ese permiso sale enmascarado, igual que en el resto del sistema.
     *
     * Es lo que hace que cifrar no abra una puerta lateral: quien no puede ver
     * un DNI en el listado tampoco lo ve en el historial de la misma persona.
     */
    public function test_sin_permiso_el_documento_del_historial_va_enmascarado(): void
    {
        $legibles = ValoresAuditados::legibles(
            ['num_doc' => \Illuminate\Support\Facades\Crypt::encryptString('44556677')],
            $this->usuarioCon([]),
        );

        $this->assertNotSame('44556677', $legibles['num_doc']);
        $this->assertMatchesRegularExpression('/^\*+77$/', $legibles['num_doc']);
    }

    /** El texto del consentimiento sale entero: es lo que hay que poder leer. */
    public function test_el_consentimiento_se_lee_entero(): void
    {
        $texto = 'Juan, para firmar necesitamos registrar tu cara una vez.';

        $legibles = ValoresAuditados::legibles(
            ['consent_text' => \Illuminate\Support\Facades\Crypt::encryptString($texto)],
            $this->usuarioCon([]),
        );

        $this->assertSame($texto, $legibles['consent_text']);
    }

    /**
     * El indice ciego no se pinta.
     *
     * Es un HMAC derivado del documento: cambia solo cuando cambia el documento
     * —que si sale, en la linea de al lado— asi que enseñarlo seria contar el
     * mismo cambio dos veces, una de ellas con 64 caracteres de hexadecimal.
     */
    public function test_el_indice_ciego_no_sale_en_el_historial(): void
    {
        $legibles = ValoresAuditados::legibles(
            ['num_doc_hash' => str_repeat('a', 64), 'name' => 'Ana'],
            $this->usuarioCon(['people.view_private_info']),
        );

        $this->assertArrayNotHasKey('num_doc_hash', $legibles);
        $this->assertArrayHasKey('name', $legibles);
    }

    /**
     * Lo que no abre con la clave de hoy se dice, no se inventa.
     *
     * Es el sintoma de un `APP_KEY` rotado sin re-cifrar. En un historial eso es
     * informacion —hay filas que ya no se pueden leer— y no puede parecer un
     * campo vacio ni tumbar la pantalla.
     */
    public function test_lo_que_no_abre_se_dice(): void
    {
        // Un sobre con la forma correcta pero de otra clave.
        $deOtraClave = base64_encode(json_encode([
            'iv'    => base64_encode(random_bytes(16)),
            'value' => base64_encode('lo que sea'),
            'mac'   => str_repeat('0', 64),
            'tag'   => '',
        ]));

        $legibles = ValoresAuditados::legibles(['num_doc' => $deOtraClave], $this->usuarioCon([]));

        $this->assertSame(__('audit_logs.unreadable'), $legibles['num_doc']);
    }

    /**
     * Y el camino de verdad: editar a una persona y mirar su historial.
     *
     * Las dos pruebas de arriba miran la pieza; esta mira que este enchufada.
     */
    public function test_editar_una_persona_deja_un_historial_que_se_lee(): void
    {
        $usuario = $this->usuarioCon(['people.view_private_info']);
        $this->actingAs($usuario);

        $persona = Person::create([
            'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1,
            'name' => 'Ana', 'lastname' => 'Pérez', 'doc_type' => 'DNI', 'num_doc' => '11112222',
            'is_active' => true,
        ]);

        $persona->update(['num_doc' => '33334444']);

        $apunte = AuditLog::where('auditable_type', Person::class)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $pintado = (new AuditLogResource($apunte))->toArray(request());

        $documento = collect($pintado['changes'])->firstWhere('before', '11112222');

        $this->assertNotNull($documento, 'el cambio de documento tiene que salir legible en el historial');
        $this->assertSame('33334444', $documento['after']);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        foreach ($permisos as $permiso) {
            $u->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }

        return $u;
    }
}
