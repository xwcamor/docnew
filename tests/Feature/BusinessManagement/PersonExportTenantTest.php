<?php

namespace Tests\Feature\BusinessManagement;

use App\Jobs\BusinessManagement\People\GeneratePeopleCsvJob;
use App\Models\Download;
use App\Models\Person;
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
 * La exportacion de personas respeta el workspace.
 *
 * Es el mismo agujero que ya se cerro en Empresas, y aqui pesaba mas porque lo
 * que se escapaba eran personas con su documento de identidad.
 *
 * El listado en pantalla filtra solo: lo hace el scope global del modelo, que
 * necesita un usuario en sesion. Pero el fichero lo genera un worker de cola
 * (`QUEUE_CONNECTION=database`), y ahi no hay sesion: el scope se rinde en su
 * primera linea (`if (! auth()->hasUser()) return;`) y hay que filtrar a mano.
 * El job capturaba el `tenantId` al encolarse y no lo usaba, ademas de pedir
 * quitar un scope con un nombre que no existe. Resultado: un admin se bajaba a
 * la gente de TODOS los workspaces y el fichero se veia perfectamente normal.
 */
class PersonExportTenantTest extends TestCase
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

    private function persona(?int $tenantId, string $nombre, string $numDoc): void
    {
        DB::table('people')->insert([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => $tenantId,
            'doc_type' => 'DNI', 'num_doc' => $numDoc,
            'name' => $nombre, 'lastname' => 'Apellido',
            'is_active' => true, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function adminDe(int $tenantId): User
    {
        $rol = Role::firstOrCreate(['name' => "admin{$tenantId}", 'guard_name' => 'web'], ['description' => 'admin']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'people.export', 'guard_name' => 'web']));

        $u = User::factory()->create(['tenant_id' => $tenantId, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    public function test_el_csv_no_trae_gente_de_otro_workspace(): void
    {
        Storage::fake('local');

        $this->persona(1, 'MIA', '10000001');
        $this->persona(2, 'AJENA', '20000002');

        $admin = $this->adminDe(1);

        // El worker corre SIN sesion: es justo la condicion en la que el scope
        // del modelo no se aplica y solo queda el filtro que ponga el job.
        $job = new GeneratePeopleCsvJob($admin->id, ['scope' => 'all', 'columns' => ['name']]);
        Auth::logout();
        $job->handle();

        $descarga = Download::withoutGlobalScopes()->where('user_id', $admin->id)->latest('id')->first();
        $this->assertNotNull($descarga);
        $this->assertSame('ready', $descarga->status);

        $csv = Storage::disk($descarga->disk)->get($descarga->path);

        $this->assertStringContainsString('MIA', $csv);
        $this->assertStringNotContainsString('AJENA', $csv, 'se colo gente de otro workspace en el fichero');
    }

    /** El super sin workspace si se lo lleva todo: es lo que ve en pantalla. */
    public function test_el_super_sin_workspace_si_exporta_todo(): void
    {
        Storage::fake('local');

        $this->persona(1, 'MIA', '10000001');
        $this->persona(2, 'AJENA', '20000002');

        $rol = Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'super']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'people.export', 'guard_name' => 'web']));
        $super = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $super->assignRole($rol);

        $job = new GeneratePeopleCsvJob($super->id, ['scope' => 'all', 'columns' => ['name']]);
        Auth::logout();
        $job->handle();

        $descarga = Download::withoutGlobalScopes()->where('user_id', $super->id)->latest('id')->first();
        $csv = Storage::disk($descarga->disk)->get($descarga->path);

        $this->assertStringContainsString('MIA', $csv);
        $this->assertStringContainsString('AJENA', $csv);
    }
}
