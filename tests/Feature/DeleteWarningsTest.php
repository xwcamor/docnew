<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El aviso de «esto tiene datos detras» al borrar, que no salia nunca.
 *
 * Las pantallas de borrado traen el bloque del aviso escrito —viene del molde
 * del generador— pero en seis modulos el modelo no implementaba `dependents()`
 * y el controlador no pasaba nada. Resultado: dar de baja a un trabajador con
 * cinco años de firmas detras avisaba exactamente igual que dar de baja a uno
 * que se registro ayer.
 *
 * `CLAUDE.md` lo lista como paso manual despues de generar un modulo. Nadie lo
 * hizo en ninguno de los seis, que es lo que pasa con los pasos manuales.
 */
class DeleteWarningsTest extends TestCase
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
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Sudamérica', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 1, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    public function test_borrar_a_un_trabajador_dice_cuantas_firmas_se_lleva_por_delante(): void
    {
        [$persona, $plan] = $this->escenario();
        $asignado = WorkPlanPerson::create(['slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $persona->id]);

        SignatureEvent::create([
            'signable_type' => WorkPlanPerson::class, 'signable_id' => $asignado->id,
            'person_id' => $persona->id, 'role_signed' => 'worker', 'signed_at' => now(),
            'method' => SignatureEvent::MANUAL, 'tenant_id' => 1,
        ]);

        $this->actingAs($this->actor(['people.view', 'people.delete']));

        $this->get(route('business_management.people.delete', $persona->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('People/Delete')
                ->where('dependents.signatures.count', 1)
                ->where('dependents.signatures.block', true)
                ->where('dependents.plans.count', 1));
    }

    /** Alguien sin nada detrás no ve el aviso: si sale siempre, deja de leerse. */
    public function test_a_quien_no_tiene_nada_detras_no_se_le_avisa_de_nada(): void
    {
        [$persona] = $this->escenario();

        $this->actingAs($this->actor(['people.view', 'people.delete']));

        $this->get(route('business_management.people.delete', $persona->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('dependents', []));
    }

    public function test_borrar_un_documento_dice_cuantas_entregas_tiene(): void
    {
        [, $plan, $plantilla] = $this->escenario();

        FormSubmission::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $plantilla->id, 'template_version' => 1,
            'status' => 'draft', 'tenant_id' => 1, 'created_by' => 1,
        ]);

        // Y un tipo de trabajo que lo exige: ése NO bloquea, pero avisa —el
        // pivote es `cascadeOnDelete` y el documento dejaría de exigirse solo.
        DB::table('work_type_form_templates')->insert([
            'work_type_id' => WorkType::first()->id, 'form_template_id' => $plantilla->id,
            'is_required' => true,
        ]);

        $this->actingAs($this->actor(['form_templates.view', 'form_templates.delete']));

        $this->get(route('business_management.form_templates.delete', $plantilla->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('FormTemplates/Delete')
                ->where('dependents.submissions.count', 1)
                ->where('dependents.submissions.block', true)
                ->where('dependents.work_types.count', 1)
                ->where('dependents.work_types.block', false));
    }

    /**
     * Marcas se queda sin aviso, y está bien.
     *
     * Es el único de los seis en el que el bloque sigue inerte a propósito: no
     * hay ni una clave ajena que apunte a `brands`, así que no hay nada que
     * contar. Se fija aquí para que nadie «arregle» lo que no está roto — y
     * para que salte el día que alguien le cuelgue una tabla.
     */
    public function test_marcas_no_tiene_nada_que_avisar_porque_nadie_le_apunta(): void
    {
        $marca = Brand::create([
            'slug' => Str::random(22), 'tenant_id' => 1, 'created_by' => 1,
            'name' => 'Una marca', 'is_active' => true,
        ]);

        $this->assertSame([], $marca->countDependents());
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** @return array{0:Person,1:WorkPlan,2:FormTemplate} */
    private function escenario(): array
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $empresa = Company::create($base + [
            'num_doc' => (string) random_int(20000000000, 20999999999),
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'is_active' => true,
        ]);

        $persona = Person::create($base + [
            'slug' => Str::random(22), 'doc_type' => 'DNI', 'num_doc' => '40000001',
            'name' => 'Ana', 'lastname' => 'Quispe',
        ]);

        $tipo  = WorkType::create($base + ['slug' => Str::random(22), 'code' => 'MTTO']);
        $lugar = WorkLocation::create($base + ['slug' => Str::random(22), 'name' => 'Planta']);
        $user  = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        $plan = WorkPlan::create($base + [
            'slug' => Str::random(22), 'company_id' => $empresa->id, 'work_type_id' => $tipo->id,
            'work_location_id' => $lugar->id, 'user_id' => $user->id,
            'code' => 'OT-' . random_int(1000, 9999), 'description' => 'Mantenimiento',
            'date_start' => today(),
        ]);

        $plantilla = FormTemplate::create($base + [
            'slug' => Str::random(22), 'code' => 'AST', 'name' => 'Análisis de seguridad',
            'kind' => FormTemplate::STRUCTURED, 'status' => 'published', 'version' => 1,
            'requires_signature' => true, 'published_at' => now(), 'is_active' => true,
        ]);

        return [$persona, $plan, $plantilla];
    }

    /** @param array<int, string> $permisos */
    private function actor(array $permisos): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');

        foreach ($permisos as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            $u->givePermissionTo($p);
        }

        return $u;
    }
}
