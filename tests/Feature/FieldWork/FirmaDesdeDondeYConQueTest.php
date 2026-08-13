<?php

namespace Tests\Feature\FieldWork;

use App\Models\SignatureEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ArmaUnaFirma;
use Tests\TestCase;

/**
 * Desde que aparato y desde donde se firmo.
 *
 * `signature_events` tiene `device_id`, `latitude` y `longitude` desde el
 * primer dia, el servidor las acepta desde el primer dia, y **la pantalla de
 * firma nunca las mandaba**: estaban vacias en las 13 764 firmas migradas y en
 * todas las nuevas. Una columna que nadie llena es peor que no tenerla, porque
 * se cuenta como que el dato existe.
 *
 * Lo que un plan tiene que poder defender delante de un inspector es «esta
 * persona firmo a las 7:12 desde la tablet de la cuadrilla, en la subestacion»,
 * y de eso solo se guardaba la hora.
 *
 * La regla que fija esta prueba, y que gobierna toda la pantalla de firma:
 * **las dos son de mejor esfuerzo y nunca bloquean la firma**. En obra no se
 * puede dejar a nadie sin firmar porque el GPS tardo o porque alguien dijo que
 * no al permiso del navegador.
 *
 * @see \App\Http\Controllers\FieldWork\SignatureController::store()
 * @see resources/js/Composables/useDondeYConQue.js
 */
class FirmaDesdeDondeYConQueTest extends TestCase
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

    public function test_el_aparato_y_la_ubicacion_quedan_en_el_evento(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            $this->firmaDe($persona, $asignado) + [
                'device_id' => '6f1c9a2e-4b77-4c31-9a0d-77f2b1c0e5aa',
                'latitude'  => -12.046374,
                'longitude' => -77.042793,
            ],
        )->assertCreated();

        $evento = SignatureEvent::sole();

        $this->assertSame('6f1c9a2e-4b77-4c31-9a0d-77f2b1c0e5aa', $evento->device_id);
        $this->assertEqualsWithDelta(-12.046374, (float) $evento->latitude, 0.000001);
        $this->assertEqualsWithDelta(-77.042793, (float) $evento->longitude, 0.000001);
    }

    /**
     * Sin permiso de ubicacion, sin GPS o con el almacenamiento del navegador
     * bloqueado: se firma igual y esos campos quedan vacios, como hasta ahora.
     */
    public function test_sin_ubicacion_ni_aparato_la_firma_se_guarda_igual(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            $this->firmaDe($persona, $asignado) + [
                'device_id' => null,
                'latitude'  => null,
                'longitude' => null,
            ],
        )->assertCreated()->assertJson(['verified' => true]);

        $evento = SignatureEvent::sole();

        $this->assertNull($evento->device_id);
        $this->assertNull($evento->latitude);
        $this->assertNull($evento->longitude);
    }

    /**
     * Unas coordenadas imposibles no se guardan.
     *
     * El navegador nunca las manda asi; quien las manda es alguien tocando la
     * peticion a mano, y una firma «en la latitud 999» ensucia justamente el
     * dato que se puso para poder defenderla.
     */
    public function test_unas_coordenadas_imposibles_se_rechazan(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            $this->firmaDe($persona, $asignado) + ['latitude' => 999, 'longitude' => -77.04],
        )->assertStatus(422)->assertJsonValidationErrors('latitude');

        $this->assertSame(0, SignatureEvent::count());
    }

    /**
     * La pantalla tiene que MANDARLO, que es lo que faltaba.
     *
     * El servidor ya aceptaba los tres campos antes de este cambio; el agujero
     * estaba entero del lado del navegador. Sin esto, todo lo de arriba pasa en
     * verde con la pantalla sin tocar.
     */
    public function test_la_pantalla_de_firma_manda_el_aparato_y_la_ubicacion(): void
    {
        $pantalla = file_get_contents(resource_path('js/Pages/FieldWork/Sign.vue'));

        $this->assertStringContainsString('useDondeYConQue()', $pantalla,
            'la pantalla importa el composable pero no lo llama: coords y dispositivo quedarían sin definir');

        foreach (['device_id:', 'latitude:', 'longitude:'] as $campo) {
            $this->assertStringContainsString($campo, $pantalla,
                "la pantalla de firma dejó de mandar {$campo}");
        }

        // Se pide al ABRIR y no al pulsar «firmar»: el dialogo de permiso del
        // navegador encima de la camara, con la persona ya mirando al objetivo,
        // seria lo peor posible.
        $this->assertStringContainsString('onMounted(() => pedirUbicacion())', $pantalla,
            'el permiso de ubicación se pide al abrir la pantalla, no en mitad de la firma');
    }
}
