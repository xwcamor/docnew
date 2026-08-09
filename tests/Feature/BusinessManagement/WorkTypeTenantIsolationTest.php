<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\WorkType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tipos de trabajo tambien se aisla por workspace.
 *
 * Era el UNICO catalogo de negocio con columna `tenant_id` y sin el trait que
 * la hace valer: sin scope, sin autorrelleno protegido y sin guard de globales.
 * Cualquier admin veia, editaba y borraba los tipos de otro workspace — y con
 * ellos su matriz de documentos, que es la que decide que papeles de seguridad
 * se exigen en los planes que ese otro tiene HOY en obra.
 *
 * El frontend si lo contemplaba (la celda de acciones oculta editar y eliminar
 * en los globales), pero el servidor no lo hacia valer: era cosmetica.
 */
class WorkTypeTenantIsolationTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'work_types';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return WorkType::create($this->base() + ['code' => 'MTTO']);
    }

    /** Un tipo de OTRO workspace, saltandose el scope para poder sembrarlo. */
    private function deOtroWorkspace(): WorkType
    {
        DB::table('tenants')->insertOrIgnore([[
            'id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);

        $id = DB::table('work_types')->insertGetId([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 2,
            'code' => 'AJENO', 'is_active' => true, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return WorkType::withoutGlobalScopes()->find($id);
    }

    public function test_un_admin_no_ve_los_tipos_de_otro_workspace(): void
    {
        $mio  = $this->unaFila();
        $otro = $this->deOtroWorkspace();

        $this->actingAs($this->admin());

        $visibles = WorkType::pluck('id');

        $this->assertTrue($visibles->contains($mio->id));
        $this->assertFalse($visibles->contains($otro->id), 'Ve un tipo de otro workspace.');
    }

    public function test_no_se_abre_la_ficha_de_un_tipo_ajeno(): void
    {
        $otro = $this->deOtroWorkspace();

        $r = $this->actingAs($this->admin())
            ->get(route('business_management.work_types.show', $otro->slug));

        $this->assertNotSame(200, $r->status(), 'Sirvio la ficha de otro workspace.');
    }

    /** Lo mas grave: cambiarle a otro que papeles exige en obra. */
    public function test_no_se_edita_un_tipo_ajeno(): void
    {
        $otro = $this->deOtroWorkspace();

        $this->actingAs($this->admin())
            ->put(route('business_management.work_types.update', $otro->slug), [
                'code' => 'ROBADO', 'country_id' => 1, 'is_active' => true,
            ]);

        $this->assertSame('AJENO', WorkType::withoutGlobalScopes()->find($otro->id)->code,
            'Un admin renombro el tipo de trabajo de otro workspace.');
    }

    public function test_no_se_borra_un_tipo_ajeno(): void
    {
        $otro = $this->deOtroWorkspace();

        $this->actingAs($this->admin())
            ->delete(route('business_management.work_types.deleteSave', $otro->slug), [
                'deleted_description' => 'Ya no se usa',
            ]);

        $this->assertFalse(
            WorkType::withoutGlobalScopes()->find($otro->id)->trashed(),
            'Un admin borro el tipo de trabajo de otro workspace.',
        );
    }

    /** Y el alta cuelga de su workspace, no del que le manden. */
    public function test_el_alta_se_queda_en_el_workspace_del_actor(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('business_management.work_types.store'), [
            'code' => 'NUEVO', 'country_id' => 1, 'is_active' => true,
            'tenant_id' => 2,
        ]);

        $creado = WorkType::withoutGlobalScopes()->where('code', 'NUEVO')->first();

        $this->assertNotNull($creado);
        $this->assertSame($admin->tenant_id, $creado->tenant_id);
    }
}
