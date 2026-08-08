<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\EvidenceFile;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use App\Services\FieldWork\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que cuesta guardar la evidencia.
 *
 * Una obra con 500 firmas al dia genera mucha foto. Sin tocar nada, a 122 KB
 * por captura son unos 15 GB al año, que en el disco de un droplet pequeño es
 * un problema real. Dos medidas lo bajan un orden de magnitud, y esta prueba
 * existe para que nadie las quite sin darse cuenta:
 *
 *   1. cada captura se reduce a 320 px y se guarda en WebP;
 *   2. la misma persona firmando varios formatos del mismo plan el mismo dia
 *      reutiliza la foto que ya se guardo.
 *
 * Lo que NO cambia: sigue habiendo una fila de evidencia por cada firma. Se
 * comparte el archivo, no el registro.
 */
class SignatureEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    public function test_la_captura_se_reduce_antes_de_guardarse(): void
    {
        Storage::fake('local');

        [$persona, $plan] = $this->escenario();
        $jpeg = $this->capturaDe(640, 480);

        $this->assertGreaterThan(100 * 1024, strlen($jpeg), 'la captura de partida deberia pesar mas de 100 KB');

        $evento = app(SignatureService::class)->firmar(
            $this->trabajador($plan, $persona), $persona, 'worker', null, $this->base64($jpeg),
        );

        $evidencia = EvidenceFile::where('signature_event_id', $evento->id)->sole();

        Storage::disk('local')->assertExists($evidencia->file_path);
        $this->assertStringEndsWith('.webp', $evidencia->file_path);
        $this->assertSame(320, $evidencia->width);
        $this->assertSame(240, $evidencia->height);

        // La imagen de la prueba es ruido puro, el caso mas dificil de comprimir:
        // una cara real baja bastante mas. Aun asi tiene que caber en un tercio.
        $this->assertLessThan(strlen($jpeg) / 3, $evidencia->byte_size);
        $this->assertSame($evidencia->byte_size, strlen(Storage::disk('local')->get($evidencia->file_path)));
    }

    public function test_dos_firmas_del_mismo_plan_y_dia_comparten_la_foto(): void
    {
        Storage::fake('local');

        [$persona, $plan] = $this->escenario();
        $foto = $this->base64($this->capturaDe(640, 480));
        $servicio = app(SignatureService::class);

        // Se asigna al plan y firma; despues firma un formato de ese mismo plan.
        $primera = $servicio->firmar($this->trabajador($plan, $persona), $persona, 'worker', null, $foto);
        $segunda = $servicio->firmar($this->formato($plan), $persona, 'worker', null, $foto);

        $este = fn (SignatureEvent $e) => EvidenceFile::where('signature_event_id', $e->id)->sole();

        // Una fila por firma: la trazabilidad no se pierde.
        $this->assertSame(2, EvidenceFile::count());
        // Un solo archivo en disco.
        $this->assertSame($este($primera)->file_path, $este($segunda)->file_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('evidencias'));
    }

    public function test_una_firma_de_otro_plan_guarda_su_propia_evidencia(): void
    {
        Storage::fake('local');

        [$persona, $plan] = $this->escenario();
        $servicio = app(SignatureService::class);

        $servicio->firmar($this->trabajador($plan, $persona), $persona, 'worker', null,
            $this->base64($this->capturaDe(640, 480)));

        // Otro plan, otra foto: no se puede reutilizar la de al lado.
        [, $otroPlan] = $this->escenario();
        $servicio->firmar($this->trabajador($otroPlan, $persona), $persona, 'worker', null,
            $this->base64($this->capturaDe(480, 640)));

        $this->assertCount(2, Storage::disk('local')->allFiles('evidencias'));
    }

    public function test_sin_foto_no_hay_firma(): void
    {
        [$persona, $plan] = $this->escenario();

        $this->expectException(\InvalidArgumentException::class);

        app(SignatureService::class)->firmar(
            $this->trabajador($plan, $persona), $persona, 'worker', null, null,
        );
    }

    /**
     * La firma trazada se guarda una vez y se reutiliza.
     *
     * Es la lógica del sistema anterior, que no había portado: allí la firma
     * vive en `workers.signature` y en cada plan se guarda el marcador
     * `signed_by_IA`, que significa «la de siempre». La primera vez se le pide a
     * la persona que firme; a partir de ahí sólo la foto.
     *
     * No es sólo comodidad: una firma redibujada cada día es distinta cada día,
     * y entonces no prueba nada. La gracia es que sea la misma.
     */
    public function test_la_firma_trazada_se_guarda_una_vez_y_se_reutiliza(): void
    {
        Storage::fake('local');

        [$persona, $plan] = $this->escenario();
        $firmas = app(SignatureService::class);

        $this->assertNull($firmas->firmaVigente($persona), 'no deberia tener firma todavia');

        $trazo = $this->base64($this->capturaDe(200, 80));
        $primera = $firmas->guardarFirma($persona, $trazo);

        Storage::disk('local')->assertExists($primera->file_path);
        $this->assertStringEndsWith('.png', $primera->file_path);
        $this->assertSame('drawn', $primera->source);
        $this->assertNull($primera->valid_to);

        // El mismo trazo otra vez no crea una version nueva: seria ruido en el
        // historial y un archivo duplicado.
        $this->assertSame($primera->id, $firmas->guardarFirma($persona->fresh(), $trazo)->id);
        $this->assertSame(1, $persona->signatures()->count());
    }

    /**
     * Al cambiarla, la anterior se jubila en vez de sobrescribirse.
     *
     * Los documentos ya firmados tienen que seguir apuntando a la firma que se
     * usó ese día. Sobrescribir el archivo cambiaría retroactivamente lo que
     * dice un documento que ya vio un inspector.
     */
    public function test_al_reemplazar_la_firma_la_anterior_se_conserva(): void
    {
        Storage::fake('local');

        [$persona] = $this->escenario();
        $firmas = app(SignatureService::class);

        $vieja = $firmas->guardarFirma($persona, $this->base64($this->capturaDe(200, 80)));
        $nueva = $firmas->guardarFirma($persona->fresh(), $this->base64($this->capturaDe(220, 90)));

        $this->assertNotSame($vieja->id, $nueva->id);
        $this->assertNotNull($vieja->fresh()->valid_to, 'la anterior deberia quedar cerrada');
        $this->assertNull($nueva->valid_to);

        // El archivo viejo sigue ahí: es a lo que apuntan los documentos firmados.
        Storage::disk('local')->assertExists($vieja->file_path);
        $this->assertSame($nueva->id, $firmas->firmaVigente($persona->fresh())->id);
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** @return array{0:Person,1:WorkPlan} */
    protected function escenario(): array
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $empresa = Company::create($base + [
            'num_doc' => (string) random_int(20000000000, 20999999999),
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'is_active' => true,
        ]);

        $persona = Person::firstOrCreate(
            ['country_id' => 1, 'doc_type' => 'DNI', 'num_doc' => '40000001'],
            $base + ['name' => 'Ana', 'lastname' => 'Quispe'],
        );

        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

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

        return [$persona, $plan];
    }

    protected function trabajador(WorkPlan $plan, Person $persona): WorkPlanPerson
    {
        return WorkPlanPerson::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $persona->id,
        ]);
    }

    /** Un formato entregado dentro del plan, que es lo otro que se firma. */
    protected function formato(WorkPlan $plan): FormSubmission
    {
        $plantilla = FormTemplate::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'code' => 'AST-' . random_int(100, 999), 'kind' => FormTemplate::STRUCTURED,
            'status' => 'published', 'version' => 1, 'requires_signature' => true, 'published_at' => now(),
        ]);

        return FormSubmission::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $plantilla->id, 'template_version' => 1,
            'status' => 'draft', 'tenant_id' => 1, 'created_by' => 1,
        ]);
    }

    /** Ruido en color: el peor caso para el compresor, a proposito. */
    protected function capturaDe(int $ancho, int $alto): string
    {
        $img = imagecreatetruecolor($ancho, $alto);

        for ($x = 0; $x < $ancho; $x += 4) {
            for ($y = 0; $y < $alto; $y += 4) {
                imagefilledrectangle($img, $x, $y, $x + 3, $y + 3, imagecolorallocate($img,
                    ($x * 7 + $y * 3) % 256, ($x * 3 + $y * 11) % 256, ($x + $y) % 256));
            }
        }

        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return $jpeg;
    }

    protected function base64(string $binario): string
    {
        return 'data:image/jpeg;base64,' . base64_encode($binario);
    }
}
