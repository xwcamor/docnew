<?php

namespace Tests\Feature\FieldWork;

use App\Models\SignatureEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ArmaUnaFirma;
use Tests\TestCase;

/**
 * Que pasa cuando se acaba de firmar.
 *
 * La pantalla de firma tenia una cuenta atras («Volviendo al plan… 6») y un
 * boton que habia que pulsar. El dueño del producto: «cuando firma un
 * trabajador o aprobador de flujo no pongas eso del contador, solo anda al
 * plan, nadie quiere saber detalles, mucho menos hacer click».
 *
 * La regla que sustituye a eso, y es la que fija esta prueba:
 *
 *  - **Firma limpia → al plan, sin toques.** El aviso lo deja el servidor en la
 *    sesion y sale en el plan, que es adonde va la persona.
 *  - **Firma pendiente de revision → la pantalla se queda.** Ahi hay algo que
 *    la persona tiene que saber —su firma todavia no vale— y por eso NO se
 *    manda por flash: el flash es un aviso que se desvanece solo y encima solo
 *    tiene canal `success` y `error`.
 *
 * Como el navegador no se puede probar aqui, lo que se comprueba es lo que
 * decide esas dos ramas: el `pending_review` de la respuesta y si el servidor
 * dejo o no el aviso en la sesion.
 */
class SignatureBackToPlanTest extends TestCase
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

    /**
     * La cara coincide y el gesto se completa: nada que leer.
     *
     * El aviso viaja en la sesion porque la pantalla se va al plan sin parar, y
     * es alli donde tiene que salir.
     */
    public function test_la_firma_limpia_no_deja_nada_que_leer_y_avisa_en_el_plan(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        $respuesta = $this->actingAs($usuario)
            ->postJson(route('field_work.signatures.store'), $this->firmaDe($persona, $asignado));

        $respuesta->assertCreated()
            ->assertJson(['verified' => true, 'pending_review' => false]);

        $this->assertTrue(session()->has('success'),
            'la firma limpia tiene que anunciarse en el plan, que es adonde va la pantalla');
    }

    /**
     * Reconocida: no se guarda foto de evidencia.
     *
     * Cuando el servidor reconoce la cara, la foto no aporta nada que no diga
     * ya la comparacion —que guarda su distancia y su umbral— y en cambio deja
     * una cara por firma y por dia. La foto buena de la persona ya esta en su
     * ficha, y es la que la ficha del plan enseña.
     *
     * La decision la toma el servidor y no el navegador: la camara manda la
     * foto igual, asi que si no se descartara aqui, apagar el ajuste no
     * cambiaria nada.
     */
    public function test_si_reconoce_la_cara_no_se_guarda_foto_de_evidencia(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        // Con foto de referencia ya puesta: sin ella la primera captura SI se
        // guarda, y a proposito — ver la prueba de aqui debajo.
        app(\App\Services\FieldWork\SignatureService::class)
            ->guardarFoto($persona, $this->imagen(), \App\Models\PersonPhoto::SUBIDA);

        $this->actingAs($usuario)
            ->postJson(route('field_work.signatures.store'), $this->firmaDe($persona, $asignado))
            ->assertCreated()->assertJson(['verified' => true]);

        $this->assertSame(0, \App\Models\EvidenceFile::count(),
            'reconocio: la foto sobra, y la camara la manda igual');
    }

    /**
     * Pero a quien NO tiene foto de referencia, la primera se le queda.
     *
     * Es la unica manera de que esa persona llegue a tener cara sin que nadie
     * se la suba a mano. Sin esto se cerraba un circulo del que no se salia:
     * reconoce siempre → no se guarda foto → nunca tiene foto de referencia →
     * la ficha del plan dice «reconocimiento facial» y no hay una sola cara que
     * enseñar, por muchas veces que firme.
     *
     * Pasa una vez por persona: en la firma siguiente ya tiene, y la captura
     * vuelve a descartarse.
     */
    public function test_la_primera_captura_de_quien_no_tiene_foto_se_queda_como_suya(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();
        $firmas = app(\App\Services\FieldWork\SignatureService::class);

        $this->assertNull($firmas->fotoVigente($persona), 'el caso es que no tiene ninguna');

        $this->actingAs($usuario)
            ->postJson(route('field_work.signatures.store'), $this->firmaDe($persona, $asignado))
            ->assertCreated()->assertJson(['verified' => true]);

        $suya = $firmas->fotoVigente($persona->fresh());

        $this->assertNotNull($suya, 'sin esto no llega a tener foto nunca');
        $this->assertSame(\App\Models\PersonPhoto::CAPTURADA, $suya->source);
    }

    /** Pero la que NO reconoce se guarda siempre: esa es la que hay que mirar. */
    public function test_si_no_reconoce_la_foto_se_guarda_igual(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        // Un descriptor que no se parece al enrolado.
        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            $this->firmaDe($persona, $asignado) + [],
            [],
        );

        \App\Models\SignatureEvent::query()->delete();
        \App\Models\EvidenceFile::query()->delete();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            array_merge($this->firmaDe($persona, $asignado), [
                'descriptor' => array_fill(0, 128, 0.99),
            ]),
        )->assertCreated()->assertJson(['verified' => false]);

        $this->assertSame(1, \App\Models\EvidenceFile::count(),
            'no reconocio: sin la foto no queda nada que revisar');
    }

    /** Y con el ajuste encendido se guarda tambien la de la que reconoce. */
    public function test_con_el_ajuste_encendido_se_guarda_siempre(): void
    {
        \App\Models\Setting::updateOrCreate(
            ['key' => 'docufiz.always_store_photo'],
            ['name' => 'x', 'type' => 'bool', 'value' => '1', 'group' => 'field_work', 'is_active' => true],
        );

        [$usuario, $persona, $asignado] = $this->escenario();

        $this->actingAs($usuario)
            ->postJson(route('field_work.signatures.store'), $this->firmaDe($persona, $asignado))
            ->assertCreated()->assertJson(['verified' => true]);

        $this->assertSame(1, \App\Models\EvidenceFile::count());
    }

    /**
     * La cara coincide pero no se completa el gesto de vida.
     *
     * La distancia entre descriptores es la misma que en una firma buena —el
     * gesto no deja rastro en el vector—, asi que sin decirselo el servidor la
     * daria por verificada. Una cara que coincide sin gesto es justo lo que da
     * una foto puesta delante del objetivo: va a revision.
     */
    public function test_el_gesto_de_vida_fallido_deja_la_firma_pendiente_de_revision(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        $respuesta = $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            $this->firmaDe($persona, $asignado) + ['liveness_failed' => true],
        );

        $respuesta->assertCreated()->assertJson(['pending_review' => true]);

        $this->assertTrue(SignatureEvent::sole()->pending_review,
            'el evento tiene que quedar marcado, o el supervisor no lo ve en su bandeja');

        // Se anuncia en el plan, por el canal `warning`. Ni por `success`, que
        // seria mentir —esa firma todavia no vale—, ni por `error`, que diria
        // que no se guardo, y si se guardo.
        //
        // Antes no se anunciaba por ningun canal: la pantalla de firma se
        // detenia con un cartel y un boton. En obra la tablet pasa a la
        // siguiente persona en cuanto la anterior suelta el dedo, asi que eso
        // era un toque de mas entre firma y firma, y encima la unica vez que se
        // decia. Ahora queda ademas en la fila del plan, marcada «sin
        // reconocer», que no se desvanece.
        $this->assertSame(__('field_work.sign.left_pending'), session('warning'));
        $this->assertFalse(session()->has('success'));
        $this->assertFalse(session()->has('error'));
    }

    /**
     * Y la que va a revision se queda con la foto, aunque haya reconocido.
     *
     * Este es el fallo que se veia solo poniendolo en orden. `firmar()` tiraba
     * la foto de toda firma reconocida —correcto: si el servidor comparo la
     * cara, la foto no aporta nada y llena el disco— y el controlador marcaba
     * «pendiente de revision» DESPUES. O sea que la unica firma que un
     * supervisor iba a ir a mirar era exactamente la unica que llegaba a su
     * bandeja sin nada que mirar.
     *
     * Una cara que coincide sin gesto de vida es lo que da una foto puesta
     * delante del objetivo. Sin la imagen, revisarla es imposible.
     */
    public function test_la_firma_que_va_a_revision_conserva_su_foto(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            $this->firmaDe($persona, $asignado) + ['liveness_failed' => true],
        )->assertCreated()->assertJson(['verified' => true, 'pending_review' => true]);

        $this->assertSame(1, \App\Models\EvidenceFile::count(),
            'a la bandeja del supervisor no se manda una firma sin cara que revisar');
    }

    /** Sin coincidencia de cara: la firma se guarda igual, pero pendiente. */
    public function test_la_cara_que_no_coincide_deja_la_firma_pendiente_de_revision(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        $respuesta = $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            ['descriptor' => array_fill(0, 128, 0.9)] + $this->firmaDe($persona, $asignado),
        );

        $respuesta->assertCreated()
            ->assertJson(['verified' => false, 'pending_review' => true]);

        $this->assertFalse(session()->has('success'));
    }

    /**
     * `liveness_failed` solo puede empeorar el resultado.
     *
     * Lo manda el navegador y el servidor no lo puede recomprobar, asi que hay
     * que dejar claro que decir «el gesto salio bien» no sirve para nada: una
     * cara que no coincide sigue yendo a revision.
     */
    public function test_el_cliente_no_puede_dar_por_buena_una_firma_diciendo_que_el_gesto_salio_bien(): void
    {
        [$usuario, $persona, $asignado] = $this->escenario();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.store'),
            ['descriptor' => array_fill(0, 128, 0.9), 'liveness_failed' => false]
                + $this->firmaDe($persona, $asignado),
        )->assertCreated()->assertJson(['pending_review' => true]);
    }

    /**
     * La cuenta atras no vuelve.
     *
     * Su clave de idioma se borro de los dos ficheros; si alguien la reescribe
     * es que ha vuelto a poner un boton que se renombra solo cada segundo
     * mientras se apunta con el dedo.
     */
    public function test_la_pantalla_de_firma_no_tiene_cuenta_atras(): void
    {
        foreach (['es', 'en'] as $idioma) {
            $textos = require resource_path("lang/{$idioma}/field_work.php");

            $this->assertArrayNotHasKey('back_to_plan_in', $textos['sign'],
                "la cuenta atras volvio a resources/lang/{$idioma}/field_work.php");
        }

        $pantalla = file_get_contents(resource_path('js/Pages/FieldWork/Sign.vue'));

        $this->assertStringNotContainsString('setInterval', $pantalla,
            'la pantalla de firma no cuenta segundos: la limpia se va sola y la pendiente espera al botón');
    }
}
