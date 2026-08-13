<?php

namespace Tests\Feature\FieldWork;

use App\Models\PersonBiometric;
use App\Models\PersonPhoto;
use App\Services\FieldWork\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\ArmaUnaFirma;
use Tests\TestCase;

/**
 * Quien se enrola sin foto se queda con una.
 *
 * El agujero, que no cerraba nunca por si solo:
 *
 *   1. se da de alta a alguien SIN foto — el alta no la exige, y con la
 *      importacion detras hay gente cuya foto la subio el administrador a mano
 *      y gente que se quedo sin ninguna;
 *   2. esa persona se enrola: el enrolamiento guarda los 128 numeros y
 *      **ninguna imagen**, a proposito;
 *   3. firma, el servidor le reconoce la cara y —por decision del dueño del
 *      producto— cuando reconoce **no se guarda foto de evidencia**.
 *
 * Resultado: la ficha del plan dice «reconocimiento facial» y no hay una sola
 * cara que enseñarle a nadie, ni la va a haber por muchas veces que esa persona
 * firme. El enrolamiento es el momento natural para resolverlo: la persona esta
 * delante de la camara, consintiendo, y de ahi sale una frontal buena.
 *
 * Y no pisa nada: la foto que subio el administrador existe justamente porque
 * alguien decidio cual era la buena.
 */
class EnrolarDejaCaraTest extends TestCase
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

    public function test_a_quien_no_tiene_foto_el_enrolamiento_le_deja_una(): void
    {
        [$usuario, $persona] = $this->sinEnrolar();

        $this->assertNull(app(SignatureService::class)->fotoVigente($persona),
            'el caso es justamente que no tiene ninguna');

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.enroll', $persona->slug),
            [
                'descriptors' => [$this->descriptorEnrolado()],
                'consent'     => true,
                'photo'       => $this->imagen(),
            ],
        )->assertCreated();

        $foto = app(SignatureService::class)->fotoVigente($persona->fresh());

        $this->assertNotNull($foto, 'sin esto la persona firmaría siempre sin cara que enseñar');
        $this->assertSame(PersonPhoto::CAPTURADA, $foto->source);
        $this->assertTrue(Storage::disk('local')->exists($foto->file_path));
    }

    /** La que subio el administrador manda: existe porque alguien la eligio. */
    public function test_no_pisa_la_foto_que_ya_tenia(): void
    {
        [$usuario, $persona] = $this->sinEnrolar();

        $firmas = app(SignatureService::class);
        $suya = $firmas->guardarFoto($persona, $this->imagen(), PersonPhoto::SUBIDA);

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.enroll', $persona->slug),
            [
                'descriptors' => [$this->descriptorEnrolado()],
                'consent'     => true,
                'photo'       => $this->otraImagen(),
            ],
        )->assertCreated();

        $this->assertSame($suya->id, $firmas->fotoVigente($persona->fresh())->id);
    }

    /**
     * Sin fotograma el enrolamiento sigue valiendo.
     *
     * La foto es un extra de la pantalla; que un navegador no la mande no puede
     * dejar a nadie sin poder registrar su cara, que es a lo que vino.
     */
    public function test_sin_fotograma_el_enrolamiento_no_falla(): void
    {
        [$usuario, $persona] = $this->sinEnrolar();

        $this->actingAs($usuario)->postJson(
            route('field_work.signatures.enroll', $persona->slug),
            ['descriptors' => [$this->descriptorEnrolado()], 'consent' => true],
        )->assertCreated();

        $this->assertNotNull($persona->fresh()->activeBiometric);
        $this->assertNull(app(SignatureService::class)->fotoVigente($persona->fresh()));
    }

    /**
     * El decorado, pero con la persona SIN cara registrada.
     *
     * `escenario()` la deja enrolada porque esta hecho para probar la firma, y
     * aqui lo que se prueba es el enrolamiento en si: con biometria ya puesta
     * el servidor responde 409 y no se llega a nada.
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

    /** Un color distinto: si no, el hash coincide y no se puede distinguir. */
    private function otraImagen(): string
    {
        $img = imagecreatetruecolor(40, 20);
        imagefilledrectangle($img, 0, 0, 39, 19, imagecolorallocate($img, 10, 200, 60));

        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }
}
