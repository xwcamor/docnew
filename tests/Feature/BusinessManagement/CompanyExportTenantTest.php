<?php

namespace Tests\Feature\BusinessManagement;

use App\Jobs\BusinessManagement\Companies\GenerateCompaniesCsvJob;
use App\Models\Company;
use App\Models\Download;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La exportacion respeta el workspace.
 *
 * El listado en pantalla lo respeta solo: lo hace el scope global del modelo,
 * que necesita un usuario en sesion. El fichero lo genera un worker de cola, y
 * ahi no hay sesion — el scope no corre y hay que filtrar a mano. Si nadie lo
 * hace, el admin de un workspace se baja las contratistas de todos los demas y
 * no se entera: el fichero se ve perfectamente normal.
 */
class CompanyExportTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        foreach ([1, 2] as $id) {
            DB::table('tenants')->insertOrIgnore([['id' => $id, 'slug' => Str::random(22), 'name' => "Empresa {$id}", 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        }
    }

    private function empresa(?int $tenantId, string $nombre, string $ruc): Company
    {
        return Company::withoutGlobalScopes()->create([
            'slug'          => Str::random(22),
            'country_id'    => 1,
            'tenant_id'     => $tenantId,
            'name'          => $nombre,
            'complete_name' => $nombre . ' S.A.',
            'num_doc'       => $ruc,
            'is_active'     => true,
        ]);
    }

    private function adminDe(int $tenantId): User
    {
        $rol = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'companies.export', 'guard_name' => 'web']));

        $u = User::factory()->create(['tenant_id' => $tenantId, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    public function test_el_csv_solo_trae_las_empresas_del_workspace_de_quien_exporta(): void
    {
        Storage::fake('local');

        $this->empresa(1, 'HITACHI', '20100000001');
        $this->empresa(2, 'AJENA', '20200000002');
        $this->empresa(null, 'GLOBAL', '20300000003');

        $admin = $this->adminDe(1);

        // El worker corre SIN sesion: es justo la condicion en la que el scope
        // del modelo no se aplica.
        $job = new GenerateCompaniesCsvJob($admin->id, ['scope' => 'all', 'columns' => ['name']]);
        Auth::logout();
        $job->handle();

        $descarga = Download::withoutGlobalScopes()->where('user_id', $admin->id)->latest('id')->first();
        $this->assertNotNull($descarga);
        $this->assertSame('ready', $descarga->status);

        $csv = Storage::disk($descarga->disk)->get($descarga->path);

        $this->assertStringContainsString('HITACHI', $csv);
        $this->assertStringContainsString('GLOBAL', $csv, 'las globales del catalogo si se ven, igual que en pantalla');
        $this->assertStringNotContainsString('AJENA', $csv, 'se colo una contratista de otro workspace en el fichero');
    }

    public function test_el_super_sin_workspace_si_exporta_todo(): void
    {
        Storage::fake('local');

        $this->empresa(1, 'HITACHI', '20100000001');
        $this->empresa(2, 'AJENA', '20200000002');

        $rol   = Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        $super = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $super->assignRole($rol);

        $job = new GenerateCompaniesCsvJob($super->id, ['scope' => 'all', 'columns' => ['name']]);
        Auth::logout();
        $job->handle();

        $descarga = Download::withoutGlobalScopes()->where('user_id', $super->id)->latest('id')->first();
        $csv = Storage::disk($descarga->disk)->get($descarga->path);

        $this->assertStringContainsString('HITACHI', $csv);
        $this->assertStringContainsString('AJENA', $csv);
    }
}
