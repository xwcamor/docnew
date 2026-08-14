<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Buscador global: planes de trabajo, empresas y personas del propio workspace.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'ES', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'T1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 2, 'slug' => Str::random(22), 'name' => 'T2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['work_plans.view', 'companies.view', 'people.view'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web'], ['description' => 'u']);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    public function test_encuentra_planes_empresas_y_personas_del_propio_workspace(): void
    {
        $empresa = $this->empresa('Minera Andina', '20100000001', 1);
        $this->plan($empresa, 'OT-4521', 1);

        // Otro workspace: no debe aparecer.
        $ajena = $this->empresa('Minera Ajena', '20100000002', 2);
        $this->plan($ajena, 'OT-9999', 2);

        Person::create(['slug' => Str::random(22), 'country_id' => 1, 'doc_type' => 'DNI',
            'num_doc' => '44556677', 'name' => 'Rosa', 'lastname' => 'Huaman', 'tenant_id' => 1, 'created_by' => 1]);

        $this->actingAs($this->actor('admin', ['work_plans.view', 'companies.view', 'people.view']));

        $this->getJson(route('search', ['q' => 'OT-']))->assertOk()
            ->assertJsonCount(1, 'work_plans')
            ->assertJsonPath('work_plans.0.label', 'OT-4521');

        $this->getJson(route('search', ['q' => 'Andina']))->assertOk()
            ->assertJsonPath('companies.0.label', 'Minera Andina');

        // Una persona se encuentra por su documento, que es como la buscan en obra.
        // Se BUSCA por el numero entero y se DEVUELVE tapado: este actor no
        // tiene `people.view_private_info`.
        //
        // El numero ENTERO, y ya no vale un trozo: el documento esta cifrado y
        // solo se puede preguntar por el a traves de su indice ciego, que
        // responde a igualdad y a nada mas. El nombre y el apellido siguen
        // encontrandose por trozos.
        $this->getJson(route('search', ['q' => '44556677']))->assertOk()
            ->assertJsonPath('people.0.label', 'Rosa Huaman')
            ->assertJsonPath('people.0.sub', 'DNI ******77');
    }

    /**
     * El buscador global era la ultima puerta por la que salia el DNI entero.
     *
     * El resto de pantallas ya leen `safe_num_doc`; aqui se concatenaba
     * `num_doc` a pelo, asi que cualquiera con `people.view` lo veia — que es
     * justo lo que el permiso `people.view_private_info` existe para impedir.
     */
    public function test_el_buscador_solo_ensena_el_documento_entero_a_quien_puede_verlo(): void
    {
        Person::create(['slug' => Str::random(22), 'country_id' => 1, 'doc_type' => 'DNI',
            'num_doc' => '44556677', 'name' => 'Rosa', 'lastname' => 'Huaman', 'tenant_id' => 1, 'created_by' => 1]);

        Permission::firstOrCreate(['name' => 'people.view_private_info', 'guard_name' => 'web']);

        $this->actingAs($this->actor('admin', ['people.view', 'people.view_private_info']));

        $this->getJson(route('search', ['q' => '44556677']))->assertOk()
            ->assertJsonPath('people.0.sub', 'DNI 44556677');
    }

    public function test_un_plan_se_encuentra_por_la_empresa_que_lo_ejecuta(): void
    {
        $empresa = $this->empresa('Servicios Delta', '20100000003', 1);
        $this->plan($empresa, 'OT-7000', 1);

        $this->actingAs($this->actor('admin', ['work_plans.view', 'companies.view']));

        $this->getJson(route('search', ['q' => 'Delta']))->assertOk()
            ->assertJsonPath('work_plans.0.label', 'OT-7000')
            ->assertJsonPath('companies.0.label', 'Servicios Delta');
    }

    public function test_respeta_permisos_y_longitud_minima(): void
    {
        $empresa = $this->empresa('Contratista Uno', '20100000004', 1);
        $this->plan($empresa, 'OT-1234', 1);

        // Sin permiso de planes no se devuelve ninguno, aunque exista.
        $this->actingAs($this->actor('user', []));
        $this->getJson(route('search', ['q' => 'OT-1234']))->assertOk()->assertJsonCount(0, 'work_plans');

        // Una sola letra no dispara la busqueda.
        $this->actingAs($this->actor('admin', ['work_plans.view']));
        $this->getJson(route('search', ['q' => 'O']))->assertOk()->assertJsonCount(0, 'work_plans');
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    private function actor(string $role, array $perms = [], int $tenant = 1): User
    {
        $u = User::factory()->create(['tenant_id' => $tenant, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($role);
        $u->givePermissionTo($perms);

        return $u;
    }

    private function empresa(string $nombre, string $ruc, int $tenant): Company
    {
        return Company::create([
            'slug' => Str::random(22), 'country_id' => 1, 'num_doc' => $ruc,
            'name' => $nombre, 'complete_name' => "{$nombre} SAC",
            'tenant_id' => $tenant, 'created_by' => 1,
        ]);
    }

    private function plan(Company $empresa, string $codigo, int $tenant): WorkPlan
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => $tenant, 'created_by' => 1];

        return WorkPlan::create($base + [
            'company_id'       => $empresa->id,
            'work_type_id'     => WorkType::create($base + ['slug' => Str::random(22), 'code' => 'MTTO'])->id,
            'work_location_id' => WorkLocation::create($base + ['slug' => Str::random(22), 'name' => 'Planta'])->id,
            'user_id'          => User::factory()->create(['tenant_id' => $tenant, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => $codigo,
            'description'      => 'Trabajo programado',
            'date_start'       => today(),
        ]);
    }
}
