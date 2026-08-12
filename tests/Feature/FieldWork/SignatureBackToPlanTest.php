<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\Person;
use App\Models\PersonBiometric;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

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

    // ── apoyo ────────────────────────────────────────────────────────────────

    /**
     * Lo que manda la pantalla al firmar, menos el descriptor cuando la prueba
     * quiere otro. La firma trazada viaja porque la persona no tiene ninguna en
     * archivo: es su primera vez.
     *
     * @return array<string, mixed>
     */
    protected function firmaDe(Person $persona, WorkPlanPerson $asignado): array
    {
        return [
            'signable_type' => 'work_plan_person',
            'signable_slug' => $asignado->slug,
            'person_slug'   => $persona->slug,
            'role_signed'   => 'worker',
            'descriptor'    => $this->descriptorEnrolado(),
            'photo'         => $this->imagen(),
            'signature'     => $this->imagen(),
        ];
    }

    /** El vector que quedo enrolado: comparado consigo mismo, distancia 0. */
    protected function descriptorEnrolado(): array
    {
        return array_map(fn (int $i) => round($i / 128, 4), range(0, 127));
    }

    /** @return array{0:User,1:Person,2:WorkPlanPerson} */
    protected function escenario(): array
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $usuario->givePermissionTo(
            Permission::firstOrCreate(['name' => 'form_submissions.sign', 'guard_name' => 'web']),
        );

        $empresa = Company::create($base + [
            'num_doc' => (string) random_int(20000000000, 20999999999),
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'is_active' => true,
        ]);

        $persona = Person::create($base + [
            'doc_type' => 'DNI', 'num_doc' => '40000002', 'name' => 'Ana', 'lastname' => 'Quispe',
        ]);

        PersonBiometric::create([
            'person_id'       => $persona->id,
            'face_descriptor' => [$this->descriptorEnrolado()],
            'enrolled_at'     => now(),
            'enrolled_by'     => $usuario->id,
            'is_active'       => true,
        ]);

        $tipo = WorkType::create(['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1, 'code' => 'MTTO']);
        $lugar = WorkLocation::create(['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1, 'name' => 'Planta']);

        $plan = WorkPlan::create($base + [
            'slug'             => Str::random(22),
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $lugar->id,
            'user_id'          => $usuario->id,
            'code'             => 'OT-' . random_int(1000, 9999),
            'description'      => 'Mantenimiento programado',
            'date_start'       => today(),
        ]);

        $asignado = WorkPlanPerson::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $persona->id,
        ]);

        return [$usuario, $persona, $asignado];
    }

    /** Una imagen minima y valida: vale de foto de evidencia y de firma trazada. */
    protected function imagen(): string
    {
        $img = imagecreatetruecolor(40, 20);
        imagefilledrectangle($img, 0, 0, 39, 19, imagecolorallocate($img, 120, 40, 200));

        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }
}
