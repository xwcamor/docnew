<?php

namespace Tests\Feature\BusinessManagement;

use App\Jobs\BusinessManagement\People\GeneratePeopleCsvJob;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Exportar no puede ser la puerta de atras del enmascarado.
 *
 * Se tapo el documento en pantalla —`PrivateInfo`, permiso
 * `people.view_private_info`— y el fichero seguia saliendo en crudo: bastaba
 * pulsar «Exportar CSV» para bajarse los documentos completos de todo el
 * padron. Y la ruta ni siquiera pedia `people.export`: con `people.view`, o
 * sea con poder abrir el listado, ya se podia.
 */
class PeopleExportPrivacyTest extends TestCase
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

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['people.view', 'people.export', 'people.view_private_info'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        Person::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'doc_type' => 'DNI', 'num_doc' => '47019236', 'name' => 'Ana', 'lastname' => 'Quispe',
        ]);
    }

    /** @param array<int, string> $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole(Role::firstOrCreate(['name' => 'r' . Str::random(6), 'guard_name' => 'web'], ['description' => 'x']));
        $u->givePermissionTo($permisos);

        return $u;
    }

    private function csvDe(User $usuario): string
    {
        Storage::fake('local');
        (new GeneratePeopleCsvJob($usuario->id, ['scope' => 'all', 'columns' => ['name', 'doc_type', 'num_doc']]))->handle();

        $archivos = Storage::disk('local')->allFiles('downloads');
        $this->assertNotEmpty($archivos, 'no se genero ningun fichero');

        return Storage::disk('local')->get($archivos[0]);
    }

    /** Sin el permiso, el fichero sale tapado igual que la pantalla. */
    public function test_sin_permiso_el_documento_sale_enmascarado_en_el_csv(): void
    {
        $csv = $this->csvDe($this->usuario(['people.view', 'people.export']));

        $this->assertStringNotContainsString('47019236', $csv, 'el documento salio completo en el CSV');
        $this->assertStringContainsString('******36', $csv);
    }

    /** Y con el permiso, completo: para eso existe. */
    public function test_con_permiso_el_documento_sale_completo(): void
    {
        $csv = $this->csvDe($this->usuario(['people.view', 'people.export', 'people.view_private_info']));

        $this->assertStringContainsString('47019236', $csv);
    }

    /**
     * Y exportar pide `people.export`, no `people.view`.
     *
     * Estaba gateado por `.view`: cualquiera que abriera el listado se bajaba
     * el fichero. El permiso `.export` existia desde el principio y no lo
     * comprobaba nadie.
     */
    public function test_exportar_exige_el_permiso_de_exportar(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        $ruta = route('business_management.people.export_csv');

        $soloVer = $this->usuario(['people.view']);
        $r = $this->actingAs($soloVer)->post($ruta, []);
        $r->assertRedirect(route('dashboard_management.dashboards.index'));
        $r->assertSessionHas('error');

        $conExport = $this->usuario(['people.view', 'people.export']);
        $this->actingAs($conExport)->post($ruta, [])->assertSessionHasNoErrors();
    }
}
