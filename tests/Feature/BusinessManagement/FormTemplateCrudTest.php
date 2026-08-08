<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dar de alta un formato desde la pantalla.
 *
 * El modulo no tenia ni una prueba, y por eso nadie vio que **crear un formato
 * reventaba**: `form_templates.country_id` es NOT NULL y ni el formulario, ni
 * las reglas, ni el servicio lo ponian. Salia un 23502 de Postgres en la cara
 * del usuario.
 *
 * Viene de que este modulo se genero clonando `Brand`, que no tiene pais.
 */
class FormTemplateCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LaravelLocalizationRedirectFilter::class, LocaleSessionRedirect::class]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['view', 'show', 'create', 'edit', 'delete'] as $a) {
            Permission::firstOrCreate(['name' => "form_templates.{$a}", 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(Permission::all());

        return $u;
    }

    /**
     * Un formato nuevo se guarda. Sin más.
     *
     * Esto es exactamente lo que reventaba: el insert salía sin `country_id` y
     * Postgres lo rechazaba con un 23502 que llegaba entero a la pantalla.
     */
    public function test_un_formato_nuevo_se_da_de_alta(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.store'), [
            'country_id' => 1, 'name' => 'Analisis de Seguridad', 'code' => 'AST',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('form_templates', [
            'name' => 'Analisis de Seguridad', 'code' => 'AST', 'country_id' => 1, 'tenant_id' => 1,
        ]);
    }

    /** Y sin país no se guarda a medias: se dice qué falta. */
    public function test_sin_pais_no_se_guarda(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.store'), [
            'name' => 'Sin pais', 'code' => 'XXX',
        ])->assertSessionHasErrors('country_id');

        $this->assertDatabaseMissing('form_templates', ['name' => 'Sin pais']);
    }

    /** El formulario llega con el país del usuario ya puesto. */
    public function test_el_formulario_propone_el_pais_del_usuario(): void
    {
        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('defaultCountryId', 1)
                ->has('countryOptions', 1));
    }

    /** Las pantallas del módulo abren, que es lo que nadie había comprobado. */
    public function test_las_pantallas_del_modulo_abren(): void
    {
        $plantilla = FormTemplate::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'code' => 'AST', 'name' => 'Analisis de Seguridad', 'kind' => FormTemplate::STRUCTURED,
            'status' => 'draft', 'version' => 1,
        ]);

        $this->actingAs($this->admin());

        foreach (['index', 'create'] as $pantalla) {
            $this->get(route("business_management.form_templates.{$pantalla}"))->assertOk();
        }
        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("business_management.form_templates.{$pantalla}", $plantilla->slug))->assertOk();
        }
    }
}
