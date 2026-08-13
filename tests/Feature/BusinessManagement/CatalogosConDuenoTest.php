<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\ApproverRole;
use App\Models\Position;
use Database\Seeders\ApproverRolesSeeder;
use Database\Seeders\PositionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los cargos y los roles del flujo son DE LA EMPRESA, no de la plataforma.
 *
 * Salian los veintiun cargos y los dos roles con «Workspace: Plataforma»
 * siendo de una sola empresa, y no por una decision de diseño: eran dos
 * accidentes de orden.
 *
 *   - `PositionsSeeder` corria ANTES que `TenantsSeeder`. Cuando escribia no
 *     existia todavia ninguna empresa a la que asignarselos.
 *   - Los roles aprobadores los inserta una migracion, y una migracion nunca
 *     puede saberlo: corre antes que cualquier seeder.
 *
 * Y no era solo la etiqueta. Un registro global lo ven TODOS los workspaces y
 * —por `BelongsToTenantOrGlobal`— **solo el super lo puede editar**, asi que el
 * admin de la empresa que usa esos cargos a diario no podia renombrar ni uno.
 *
 * La regla, con su limite: si la instalacion tiene UNA empresa, los catalogos
 * sembrados son suyos. Con cero o varias se quedan globales, porque cualquier
 * reparto seria inventado.
 */
class CatalogosConDuenoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    public function test_los_cargos_sembrados_son_del_unico_workspace(): void
    {
        $this->empresa(1);

        (new PositionsSeeder)->run();

        $this->assertGreaterThan(0, Position::count());
        $this->assertSame(0, Position::whereNull('tenant_id')->count(),
            'ningún cargo sembrado debería quedar como global');
    }

    public function test_los_roles_del_flujo_son_del_unico_workspace(): void
    {
        $this->empresa(1);

        (new ApproverRolesSeeder)->run();

        $roles = ApproverRole::pluck('tenant_id', 'code');

        $this->assertSame([1, 1], [$roles['supervisor'], $roles['hse_supervisor']]);
    }

    /**
     * El representante de la cuadrilla NO vuelve.
     *
     * `worker` se saco del catalogo a proposito —firma como trabajador, no como
     * aprobacion—, y sembrarlo aqui lo resucitaria en cada instalacion.
     */
    public function test_el_representante_de_la_cuadrilla_no_se_resucita(): void
    {
        $this->empresa(1);

        (new ApproverRolesSeeder)->run();

        $this->assertSame(0, ApproverRole::where('code', 'worker')->count());
    }

    /** El rol que dejo la migracion sin dueño se adopta, no se duplica. */
    public function test_un_rol_global_de_antes_se_reasigna_en_vez_de_duplicarse(): void
    {
        $this->empresa(1);

        // El estado de partida no hay que fabricarlo: la migracion
        // `make_approval_flow_configurable` ya dejo la fila, y sin workspace,
        // que es justo el defecto. Se anota su id para poder distinguir «se
        // adoptó» de «se creó otra al lado».
        $antes = DB::table('approver_roles')->where('code', 'supervisor')->first();

        $this->assertNotNull($antes, 'la migración tenía que haber dejado el rol');
        $this->assertNull($antes->tenant_id);

        (new ApproverRolesSeeder)->run();

        $ahora = ApproverRole::where('code', 'supervisor')->get();

        $this->assertCount(1, $ahora, 'adoptarlo, no crear un segundo al lado');
        $this->assertSame($antes->id, $ahora->first()->id);
        $this->assertSame(1, $ahora->first()->tenant_id);
    }

    /** Y lo mismo con los cargos que ya estaban sueltos. */
    public function test_un_cargo_global_de_antes_se_reasigna(): void
    {
        $this->empresa(1);

        Position::create([
            'slug' => Str::random(22), 'country_id' => 1, 'code' => 'Soldador',
            'tenant_id' => null, 'is_active' => true, 'created_by' => 1,
        ]);

        (new PositionsSeeder)->run();

        $soldadores = Position::where('code', 'Soldador')->get();

        $this->assertCount(1, $soldadores);
        $this->assertSame(1, $soldadores->first()->tenant_id);
    }

    /**
     * Con dos empresas no se adivina.
     *
     * Es el limite honesto de la regla: repartir un catalogo compartido entre
     * varias empresas seria inventarse a quien pertenece, y dejarlo global —que
     * es lo que hacia antes— al menos lo deja a la vista de todas.
     */
    public function test_con_varias_empresas_los_catalogos_siguen_siendo_globales(): void
    {
        $this->empresa(1);
        $this->empresa(2);

        (new PositionsSeeder)->run();
        (new ApproverRolesSeeder)->run();

        $this->assertSame(0, Position::whereNotNull('tenant_id')->count());
        $this->assertSame(0, ApproverRole::whereNotNull('tenant_id')->count());
    }

    /** Sembrar dos veces no crea nada nuevo. */
    public function test_sembrar_dos_veces_no_duplica(): void
    {
        $this->empresa(1);

        (new PositionsSeeder)->run();
        (new ApproverRolesSeeder)->run();

        $cargos = Position::count();
        $roles  = ApproverRole::count();

        (new PositionsSeeder)->run();
        (new ApproverRolesSeeder)->run();

        $this->assertSame($cargos, Position::count());
        $this->assertSame($roles, ApproverRole::count());
    }

    private function empresa(int $id): void
    {
        DB::table('tenants')->insertOrIgnore([[
            'id' => $id, 'slug' => Str::random(22), 'name' => "Empresa {$id}",
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
    }
}
