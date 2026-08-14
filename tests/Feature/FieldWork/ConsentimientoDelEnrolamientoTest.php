<?php

namespace Tests\Feature\FieldWork;

use App\Models\PersonBiometric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\ArmaUnaFirma;
use Tests\TestCase;

/**
 * Registrar una cara exige consentimiento, y el consentimiento queda escrito.
 *
 * EL AGUJERO QUE CIERRA
 * ---------------------
 * `enroll()` ya validaba `'consent' => ['accepted']`, pero la pantalla mandaba
 * `consent: true` a pelo: nadie preguntaba nada. Y aunque se hubiera
 * preguntado, no habia donde apuntarlo — el si de la persona vivia dentro de
 * una peticion HTTP que se descarta.
 *
 * Un descriptor facial es un dato biometrico. Lo que se pide demostrar no es
 * que el sistema pidiera permiso: es que ESTA persona lo dio, CUANDO y sobre
 * QUE TEXTO. Sin las tres cosas no hay nada que enseñar en una auditoria.
 *
 * Por eso el texto viaja entero desde la pantalla y se guarda tal cual: una
 * referencia a la version no responde a «¿a que dijo que si?» cuando hayan
 * pasado dos años y tres redacciones.
 */
class ConsentimientoDelEnrolamientoTest extends TestCase
{
    use RefreshDatabase;
    use ArmaUnaFirma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        $this->sembrarPadresDeFirma();

        Storage::fake('local');
    }

    /** Lo que acepto queda escrito: cuando, que version y que texto. */
    public function test_el_consentimiento_se_guarda_entero(): void
    {
        [$usuario, $persona] = $this->sinEnrolar();

        $texto = 'Juan, para firmar necesitamos registrar tu cara una vez…';

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.enroll', $persona->slug),
            [
                'descriptors'     => [$this->descriptorEnrolado()],
                'consent'         => true,
                'consent_version' => PersonBiometric::CONSENT_VERSION,
                'consent_text'    => $texto,
            ],
        )->assertCreated();

        $biometria = $persona->fresh()->activeBiometric;

        $this->assertNotNull($biometria->consent_at, 'sin la fecha no hay nada que enseñar');
        $this->assertSame(PersonBiometric::CONSENT_VERSION, $biometria->consent_version);
        $this->assertSame($texto, $biometria->consent_text,
            'el texto entero, no una referencia: es lo unico que responde a «a que dijo que si»');
        $this->assertNotNull($biometria->consent_ip);
    }

    /**
     * El historial guarda el consentimiento y NO el descriptor facial.
     *
     * `Auditable` escribe la fila entera al crearla, asi que enrolar dejaba una
     * segunda copia de los 128 numeros de la cara en `audit_logs`: una tabla
     * que nadie purga y que sobrevive a borrar la biometria. Retirar la cara de
     * una persona no la retiraba.
     */
    public function test_el_historial_no_se_queda_una_copia_de_la_cara(): void
    {
        [$usuario, $persona] = $this->sinEnrolar();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.enroll', $persona->slug),
            [
                'descriptors'     => [$this->descriptorEnrolado()],
                'consent'         => true,
                'consent_version' => PersonBiometric::CONSENT_VERSION,
                'consent_text'    => 'Texto aceptado.',
            ],
        )->assertCreated();

        $apunte = \App\Models\AuditLog::where('auditable_type', PersonBiometric::class)
            ->where('event', 'created')
            ->latest('id')
            ->first();

        $this->assertNotNull($apunte, 'registrar una cara tiene que dejar rastro');
        $this->assertArrayNotHasKey('face_descriptor', $apunte->new_values);

        // Y lo que SI tiene que quedar: quien acepto, cuando y sobre que texto.
        $this->assertArrayHasKey('consent_at', $apunte->new_values);

        // El texto queda, pero CIFRADO. El trait escribe los valores tal y como
        // van a la base, y `consent_text` va cifrado desde que se cifraron los
        // datos sensibles: seria absurdo cifrar la columna y dejar una copia en
        // claro en la tabla de al lado —una que ademas nadie purga—. Se sigue
        // leyendo con el mismo APP_KEY, que es lo que esta prueba comprueba.
        $this->assertNotSame('Texto aceptado.', $apunte->new_values['consent_text'],
            'el historial no puede guardar el consentimiento en claro');
        $this->assertSame(
            'Texto aceptado.',
            \App\Support\CifradoEnReposo::enClaro($apunte->new_values['consent_text']),
        );
    }

    /**
     * Sin el si no se registra ninguna cara.
     *
     * Es la mitad que ya estaba, y se comprueba igual: es la regla, y una regla
     * sin prueba se cae el dia que alguien simplifica la validacion.
     */
    public function test_sin_aceptar_no_se_registra_nada(): void
    {
        [$usuario, $persona] = $this->sinEnrolar();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.enroll', $persona->slug),
            [
                'descriptors'     => [$this->descriptorEnrolado()],
                'consent'         => false,
                'consent_version' => PersonBiometric::CONSENT_VERSION,
                'consent_text'    => 'lo que sea',
            ],
        )->assertStatus(422);

        $this->assertNull($persona->fresh()->activeBiometric);
    }

    /**
     * Y sin el TEXTO tampoco.
     *
     * Aceptar sin poder decir a que se acepto no vale: es justo el estado del
     * que se viene. Que el servidor lo exija —y no solo la pantalla— es lo que
     * impide que una version vieja de la aplicacion, o una llamada directa a la
     * API, vuelva a enrolar a ciegas.
     */
    public function test_sin_el_texto_aceptado_no_se_registra_nada(): void
    {
        [$usuario, $persona] = $this->sinEnrolar();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.enroll', $persona->slug),
            ['descriptors' => [$this->descriptorEnrolado()], 'consent' => true],
        )->assertStatus(422)
            ->assertJsonValidationErrors(['consent_version', 'consent_text']);

        $this->assertNull($persona->fresh()->activeBiometric);
    }

    /**
     * La pantalla pregunta de verdad antes de abrir la camara.
     *
     * No hay navegador en la suite, pero si se puede comprobar que la pantalla
     * dejo de mandar el `true` cableado y que espera una respuesta. Es la mitad
     * que el servidor no puede vigilar solo: mandaria un texto igualmente.
     */
    public function test_la_pantalla_pide_el_permiso_antes_de_enrolar(): void
    {
        $vista = file_get_contents(resource_path('js/Pages/FieldWork/Sign.vue'));

        $this->assertStringContainsString('await pedirConsentimiento()', $vista);
        $this->assertStringContainsString('consent_text: textoConsentimiento.value', $vista);

        // Y el aviso no se cierra de un roce: `mask-closable` y `keyboard` en
        // falso. Una ventana que se va tocando fuera no es una decision.
        $this->assertStringContainsString(':mask-closable="false"', $vista);
    }

    /** El texto existe en los dos idiomas y nombra a quien lo lee. */
    public function test_el_texto_esta_en_los_dos_idiomas(): void
    {
        foreach (['es', 'en'] as $idioma) {
            $textos = require resource_path("lang/{$idioma}/field_work.php");

            $this->assertArrayHasKey('consent', $textos);

            foreach (['title', 'text', 'checkbox', 'accept', 'decline', 'declined'] as $clave) {
                $this->assertArrayHasKey($clave, $textos['consent'], "falta consent.{$clave} en {$idioma}");
            }

            $this->assertStringContainsString(':name', $textos['consent']['text'],
                'el texto tiene que nombrar a quien lo lee');
        }
    }

    /**
     * El consentimiento no promete menos de lo que el sistema hace.
     *
     * La primera redaccion decia «lo que se guarda NO es tu fotografia» a
     * secas — y la camara de la firma SI guarda una foto como evidencia del
     * acto, y la firma trazada se guarda y se reutiliza. Un consentimiento que
     * niega lo que el sistema hace no cubre nada: lo vio el dueño del producto
     * («entonces estas contradiciendo»). La v2 autoriza las tres cosas.
     */
    public function test_el_texto_autoriza_la_foto_del_momento_y_la_firma_trazada(): void
    {
        foreach (['es' => ['fotografía', 'firma trazada'], 'en' => ['photograph', 'drawn signature']] as $idioma => $frases) {
            $texto = (require resource_path("lang/{$idioma}/field_work.php"))['consent']['text'];

            foreach ($frases as $frase) {
                $this->assertStringContainsString($frase, $texto,
                    "el consentimiento en {$idioma} tiene que nombrar «{$frase}»: es algo que el sistema guarda");
            }
        }
    }

    /**
     * La version que manda la pantalla es la del modelo.
     *
     * Son dos constantes a proposito —la pantalla no puede leer PHP— pero si se
     * separan, lo aceptado se apunta con una version que no corresponde al
     * texto. Esta prueba es lo que las mantiene gemelas.
     */
    public function test_la_pantalla_manda_la_version_vigente(): void
    {
        $vista = file_get_contents(resource_path('js/Pages/FieldWork/Sign.vue'));

        $this->assertStringContainsString(
            "const CONSENT_VERSION = '" . PersonBiometric::CONSENT_VERSION . "'",
            $vista,
            'la constante de Sign.vue tiene que subir junto a PersonBiometric::CONSENT_VERSION',
        );
    }

    /**
     * El decorado, con la persona SIN cara registrada.
     *
     * @return array{0:\App\Models\User,1:\App\Models\Person}
     */
    private function sinEnrolar(): array
    {
        [$usuario, $persona] = $this->escenario();

        PersonBiometric::where('person_id', $persona->id)->delete();
        $usuario->givePermissionTo(Permission::firstOrCreate(['name' => 'people.edit', 'guard_name' => 'web']));

        return [$usuario, $persona->fresh()];
    }
}
