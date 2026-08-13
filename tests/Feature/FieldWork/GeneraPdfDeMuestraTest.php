<?php

namespace Tests\Feature\FieldWork;

use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\PersonSignature;
use App\Models\ReportSigner;
use App\Models\SignatureEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use App\Services\FieldWork\FormSubmissionPdfService;
use App\Services\FieldWork\FormSubmissionService;
use Database\Seeders\FormTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Genera los cuatro PDF de muestra para mirarlos.
 *
 * NO es una prueba: es una herramienta. Monta un dia de obra completo sobre los
 * formatos REALES que siembra `FormTemplatesSeeder` —no una plantilla inventada
 * para la ocasion, que es justo lo que hace que un PDF pase todas las pruebas y
 * salga mal en produccion— y escribe los cuatro documentos en disco.
 *
 * Se corre a mano:
 *
 *     php artisan test --filter=GeneraPdfDeMuestraTest
 *
 * y deja los ficheros en `storage/app/muestras-pdf/`.
 */
class GeneraPdfDeMuestraTest extends TestCase
{
    use RefreshDatabase;

    private const DESTINO = 'muestras-pdf';

    public function test_genera_los_cuatro_pdf(): void
    {
        // Apagado en la suite normal. Escribe cuatro ficheros en `storage/app`
        // y eso no es lo que hace una prueba: se corre a mano cuando hay que
        // mirar los PDF con los ojos, que es la unica forma de ver que una
        // tabla se desborda o que una columna quedo en dos letras.
        if (! env('MUESTRAS_PDF')) {
            $this->markTestSkipped('Herramienta manual: MUESTRAS_PDF=1 php artisan test --filter=GeneraPdfDeMuestraTest');
        }

        $this->sembrarCatalogos();

        [$usuario, $plan] = $this->diaDeObra();

        (new FormTemplatesSeeder)->run();

        // Los nombres de severidad y probabilidad NO los sabe el sembrador: en
        // la v1 viven en su tabla `translations`, y quien los trae es
        // `docufiz:migrate-data` cuando refresca los catalogos con la base
        // vieja delante (ver `refrescarCatalogosDeLaMatriz`). Sin ellos el
        // campo cae a la clave interna —«c1», «p5»— que es lo que siempre hizo
        // la pantalla.
        //
        // Aqui se inyectan a mano para que la muestra se parezca a la
        // instalacion de verdad y no a un sembrado pelado. Las palabras son de
        // ejemplo; en produccion salen del catalogo del propio cliente.
        $this->ponerNombresDeLaMatriz();

        $pdf = app(FormSubmissionPdfService::class);
        $carpeta = storage_path('app/' . self::DESTINO);

        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        foreach (['AST', 'PTF', 'EPP', 'IHM'] as $codigo) {
            $plantilla = FormTemplate::where('code', $codigo)->firstOrFail();
            $entrega = $this->llenar($plan, $plantilla, $usuario);

            $bytes = $pdf->generar($entrega->fresh(), $usuario)->output();

            file_put_contents($carpeta . '/' . $codigo . '.pdf', $bytes);

            $this->assertStringStartsWith('%PDF-', $bytes, "{$codigo} no se genero");
            $paginas = preg_match_all('#/Type /Page[^s]#', $bytes);
            fwrite(STDERR, sprintf(
                "  %s → %d KB, %d pagina(s)\n", $codigo, (int) round(strlen($bytes) / 1024), $paginas,
            ));
        }
    }

    /** Los rotulos que en produccion trae la migracion desde la v1. */
    private function ponerNombresDeLaMatriz(): void
    {
        $severidades   = ['c1' => 'Catastrófico', 'c2' => 'Mayor', 'c3' => 'Moderado',
                          'c4' => 'Menor', 'c5' => 'Insignificante'];
        $probabilidades = ['p1' => 'Casi seguro', 'p2' => 'Probable', 'p3' => 'Posible',
                           'p4' => 'Poco probable', 'p5' => 'Raro de suceder'];

        foreach (\App\Models\FormField::where('field_type', 'risk_matrix')->get() as $campo) {
            $campo->forceFill(['config' => array_merge($campo->config ?? [], [
                'severity_labels'    => $severidades,
                'probability_labels' => $probabilidades,
            ])])->save();
        }
    }

    /** Rellena la entrega con datos creibles, campo por campo segun su tipo. */
    private function llenar(WorkPlan $plan, FormTemplate $plantilla, User $usuario)
    {
        $servicio = app(FormSubmissionService::class);
        $entrega = $servicio->abrir($plan, $plantilla, $usuario->id);

        $respuestas = [];

        foreach ($plantilla->sections()->with('fields')->get() as $seccion) {
            foreach ($seccion->fields as $campo) {
                $respuestas = array_merge($respuestas, $this->respuestaDe($campo));
            }
        }

        $servicio->responder($entrega, $respuestas);
        $entrega->update(['status' => 'confirmed', 'submitted_at' => now()]);

        return $entrega;
    }

    /** @return array<int, array<string, mixed>> */
    private function respuestaDe($campo): array
    {
        $config = $campo->config ?? [];
        $codigo = $campo->code;

        return match ($campo->field_type) {
            'risk_matrix' => $this->matriz($codigo, $config),
            'question_bank' => [[
                'code' => $codigo,
                'value' => collect($config['questions'] ?? [])
                    ->values()
                    ->map(fn ($q, $i) => ['question' => $q, 'answer' => $i === 4 || $i === 9 ? 'No' : 'Si'])
                    ->all(),
            ]],
            'person_checklist' => $this->epp($codigo, $config),
            'tool_checklist'   => $this->herramientas($codigo, $config),
            'multiselect' => [[
                'code' => $codigo,
                'value' => array_slice(array_values($config['options'] ?? []), 0, 3),
            ]],
            'textarea' => [['code' => $codigo, 'value' => 'Trabajo ejecutado sin novedad. Se retiraron los bloqueos y se entregó el área limpia.']],
            'text' => [['code' => $codigo, 'value' => 'Permiso de trabajo en caliente N.º 2026-0418']],
            default => [],
        };
    }

    private function matriz(string $codigo, array $config): array
    {
        $sev  = array_values($config['severities'] ?? []);
        $prob = array_values($config['probabilities'] ?? []);

        $filas = [
            ['Inspección del área de trabajo', 'Objetos fuera de lugar', 'Tropiezos, caídas', 'Transitar por zonas seguras y señalizadas', 4, 4],
            ['Inspección del área de trabajo', 'Carga suspendida por manipulación de puente grúa', 'Aplastamiento, caída de carga', 'Aplicar distancia de seguridad y vigía permanente', 1, 3],
            ['Inspección del área de trabajo', 'Tránsito de vehículo', 'Atropellos, golpes', 'Mantener distancia y usar chaleco reflectivo', 4, 4],
            ['Desmontaje de celda de media tensión', 'Energía eléctrica residual', 'Contacto eléctrico, quemadura por arco', 'Bloqueo y etiquetado, verificación de ausencia de tensión', 0, 0],
            ['Desmontaje de celda de media tensión', 'Herramienta manual en mal estado', 'Cortes, golpes', 'Inspección previa y retiro de herramienta observada', 3, 3],
            ['Cierre y entrega del área', 'Residuos metálicos en el piso', 'Cortes', 'Segregar residuos y limpiar el área', 4, 4],
        ];

        return collect($filas)->map(fn (array $f, int $i) => [
            'code' => $codigo,
            'row'  => $i,
            'value' => [
                'actividad'    => $f[0],
                'peligro'      => $f[1],
                'riesgo'       => $f[2],
                'control'      => $f[3],
                'severidad'    => $sev[$f[4]] ?? null,
                'probabilidad' => $prob[$f[5]] ?? null,
            ],
        ])->all();
    }

    private function epp(string $codigo, array $config): array
    {
        $items = array_values($config['items'] ?? []);
        $gente = [
            ['Quispe Quispe, Edgar Marino', '80264406', 6],
            ['Dueñas Patricio, Ezequiel Luis', '10199705', null],
            ['Trujillo Tolentino, Susano Jorge', '25452269', 13],
        ];

        return collect($gente)->map(fn (array $p, int $i) => [
            'code' => $codigo,
            'row'  => $i,
            'value' => array_filter([
                'person_name' => $p[0],
                'person_doc'  => $p[1],
                'items' => collect($items)->map(fn ($item, $j) => [
                    'item'   => $item,
                    'answer' => $j === $p[2] ? 'No conforme' : ($j % 3 === 1 ? 'No aplica' : 'Conforme'),
                ])->all(),
                'correction_measure' => $p[2] === null ? null : 'Se entrega equipo nuevo antes de iniciar',
                'deadline_date'      => $p[2] === null ? null : today()->toDateString(),
            ], fn ($v) => $v !== null),
        ])->all();
    }

    private function herramientas(string $codigo, array $config): array
    {
        $items = array_values($config['items'] ?? []);
        $herramientas = [
            ['Amoladora angular 4½"', null],
            ['Taladro percutor', 2],
            ['Juego de llaves aisladas', null],
            ['Escalera de fibra de vidrio', 0],
        ];

        return collect($herramientas)->map(fn (array $h, int $i) => [
            'code' => $codigo,
            'row'  => $i,
            'value' => array_filter([
                'tool'  => $h[0],
                'items' => collect($items)->map(fn ($item, $j) => [
                    'item'   => $item,
                    'answer' => $j === $h[1] ? 'No cumple' : ($j % 4 === 2 ? 'No aplica' : 'Cumple'),
                ])->all(),
                'correction_measure' => $h[1] === null ? null : 'Se retira de servicio y se rotula',
                'responsible'        => $h[1] === null ? null : 'Luis Ramos',
            ], fn ($v) => $v !== null),
        ])->all();
    }

    /** @return array{0:User,1:WorkPlan} */
    private function diaDeObra(): array
    {
        $base = ['country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $permisos = ['form_submissions.view', 'form_submissions.export', 'people.view_private_info'];

        foreach ($permisos as $permiso) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $rol = Role::firstOrCreate(['name' => 'admin_muestra', 'guard_name' => 'web'], ['description' => 'x']);
        $rol->syncPermissions($permisos);

        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $usuario->assignRole($rol);

        Storage::disk('public')->put('branding/logo.png', $this->png(200, 90, [10, 110, 209]));
        Tenant::find(1)->forceFill([
            'logo' => 'branding/logo.png',
            'address' => 'Av. Industrial 1234, Urb. Las Praderas, Lima — Perú',
            'report_disclaimer' => 'Los resultados de este informe corresponden únicamente a las condiciones '
                . 'y al alcance del trabajo descrito. Se prohíbe la reproducción total o parcial de este '
                . 'documento sin autorización previa escrita.',
        ])->save();

        ReportSigner::create(['tenant_id' => 1, 'user_id' => null, 'name' => 'Ing. María Torres',
            'title' => 'Jefa de Seguridad', 'relation' => 'approved', 'sort_order' => 1]);
        ReportSigner::create(['tenant_id' => 1, 'user_id' => null, 'name' => 'Ing. Jorge Salas',
            'title' => 'Supervisor de obra', 'relation' => 'prepared', 'sort_order' => 2]);

        $empresa = Company::create($base + ['slug' => Str::random(22), 'num_doc' => '20481234567',
            'name' => 'LIMTEK', 'complete_name' => 'Limtek Servicios Integrales SAC', 'is_active' => true]);

        $tipo  = WorkType::create($base + ['slug' => Str::random(22), 'code' => 'Estándar']);
        $lugar = WorkLocation::create($base + ['slug' => Str::random(22), 'name' => 'Subestación Lurín']);

        $plan = WorkPlan::create($base + [
            'slug' => Str::random(22), 'company_id' => $empresa->id, 'work_type_id' => $tipo->id,
            'work_location_id' => $lugar->id, 'user_id' => $usuario->id,
            'code' => 'PE26-1008-0001', 'num_os' => '10082026',
            'description' => 'Mantenimiento preventivo de celda de media tensión y limpieza de áreas',
            'date_start' => today(),
        ]);

        $trabajadora = $this->persona($base, 'Edgar Marino', 'Quispe Quispe', '80264406');
        $supervisor  = $this->persona($base, 'Jhon Richard', 'Sánchez Pimentel', '32130124');

        $enPlan = WorkPlanPerson::create(['slug' => Str::random(22),
            'work_plan_id' => $plan->id, 'person_id' => $trabajadora->id]);

        $regla = ApprovalRule::create($base + ['slug' => Str::random(22),
            'approver_role' => PersonRole::SUPERVISOR, 'priority_level' => 1, 'is_required' => true,
            'name' => 'Supervisor Autorizante - HITACHI']);

        $aprobacion = WorkPlanApproval::create(['slug' => Str::random(22),
            'work_plan_id' => $plan->id, 'approval_rule_id' => $regla->id, 'person_id' => $supervisor->id]);

        $this->firmar($enPlan, $trabajadora, PersonRole::WORKER, [
            'method' => SignatureEvent::FACE_RECOGNITION, 'used_ai' => true,
            'match_distance' => 0.15, 'threshold_used' => 0.5,
        ]);
        $this->firmar($aprobacion, $supervisor, PersonRole::SUPERVISOR, [
            'method' => SignatureEvent::TIMEOUT_CAPTURE, 'used_ai' => false,
            'threshold_used' => 0.5, 'pending_review' => true,
        ]);

        return [$usuario, $plan];
    }

    private function persona(array $base, string $nombre, string $apellido, string $doc): Person
    {
        return Person::create($base + ['slug' => Str::random(22), 'doc_type' => 'DNI',
            'num_doc' => $doc, 'name' => $nombre, 'lastname' => $apellido]);
    }

    private function firmar($firmable, Person $persona, string $rol, array $extra): void
    {
        SignatureEvent::create($extra + [
            'signable_type' => $firmable->getMorphClass(), 'signable_id' => $firmable->getKey(),
            'person_id' => $persona->id, 'role_signed' => $rol, 'signed_at' => now(),
            'ip_address' => '190.238.40.88', 'device_id' => 'dev-5528afcf-63f2-4a2d',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 11) Chrome/121.0.0.0 Mobile Safari/537.36',
            'latitude' => -12.297842, 'longitude' => -76.835961, 'tenant_id' => 1,
        ]);

        $ruta = 'firmas/' . Str::random(24) . '.png';
        Storage::disk('local')->put($ruta, $this->png(220, 70, [40, 40, 60]));

        PersonSignature::firstOrCreate(['person_id' => $persona->id], [
            'file_path' => $ruta, 'sha256' => hash('sha256', $ruta),
            'source' => 'drawn', 'valid_from' => now(),
        ]);

        $firmable->forceFill(['is_approved' => true])->save();
    }

    private function png(int $w, int $h, array $rgb): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, ...$rgb));

        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function sembrarCatalogos(): void
    {
        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Perú', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'timezone' => 'America/Lima', 'created_at' => now(), 'updated_at' => now()]]);
    }
}
