<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Empresas es per-tenant: cada workspace tiene sus propias contratistas.
 *
 * El modelo usaba el trait de los catalogos compartidos por arrastre del modulo
 * del que se clono, contradiciendo a su propio comentario. Con eso, una empresa
 * creada por el super se veia desde TODOS los workspaces y salia en los exports
 * de cualquier admin — y era la raiz de que un solo registro asi tumbara un
 * import, un «Editar todo» o una masiva entera.
 *
 * Esto fija lo contrario, que es lo que se decidio: un admin ve las suyas y solo
 * las suyas. El super sigue viendo todo, que es su trabajo.
 */
class CompanyTenantIsolationTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'companies';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->deEsteWorkspace();
    }

    private function deEsteWorkspace(): Company
    {
        return Company::create($this->base() + [
            'name'          => 'DE EMPRESA UNO',
            'complete_name' => 'De Empresa Uno S.A.',
            'num_doc'       => '20900000002',
        ]);
    }

    /** Una empresa de OTRO workspace, saltandose el scope para poder sembrarla. */
    private function deOtroWorkspace(): Company
    {
        DB::table('tenants')->insertOrIgnore([[
            'id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);

        $id = DB::table('companies')->insertGetId([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 2, 'created_by' => 1,
            'name' => 'DE EMPRESA DOS', 'complete_name' => 'De Empresa Dos S.A.',
            'num_doc' => '20900000003', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Company::withoutGlobalScopes()->find($id);
    }

    /** Una empresa sin workspace: el estado que producia el trait equivocado. */
    private function sinWorkspace(): Company
    {
        $id = DB::table('companies')->insertGetId([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => null, 'created_by' => 1,
            'name' => 'SIN WORKSPACE', 'complete_name' => 'Sin Workspace S.A.',
            'num_doc' => '20900000001', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Company::withoutGlobalScopes()->find($id);
    }

    public function test_un_admin_solo_ve_las_empresas_de_su_workspace(): void
    {
        $mia  = $this->deEsteWorkspace();
        $otra = $this->deOtroWorkspace();
        $huerfana = $this->sinWorkspace();

        $this->actingAs($this->admin());

        $visibles = Company::pluck('id');

        $this->assertTrue($visibles->contains($mia->id));
        $this->assertFalse($visibles->contains($otra->id), 'Ve una empresa de otro workspace.');
        $this->assertFalse($visibles->contains($huerfana->id), 'Ve una empresa sin workspace.');
    }

    /** El super sigue viendolo todo: es lo que le permite dar soporte. */
    public function test_el_super_sigue_viendo_todos_los_workspaces(): void
    {
        $mia  = $this->deEsteWorkspace();
        $otra = $this->deOtroWorkspace();

        $this->actingAs($this->makeSuper());

        $visibles = Company::pluck('id');

        $this->assertTrue($visibles->contains($mia->id));
        $this->assertTrue($visibles->contains($otra->id));
    }

    /**
     * La ficha de una empresa ajena no se abre ni sabiendo su slug.
     *
     * La app no contesta 404 sino que devuelve al dashboard, que ademas no
     * delata si el slug existe o no. Lo que se comprueba es que no se sirve la
     * ficha y que el nombre ajeno no aparece por ningun lado.
     */
    public function test_no_se_abre_la_ficha_de_una_empresa_de_otro_workspace(): void
    {
        $otra = $this->deOtroWorkspace();

        $r = $this->actingAs($this->admin())
            ->get(route('business_management.companies.show', $otra->slug));

        $this->assertNotSame(200, $r->status(), 'Sirvio la ficha de otro workspace.');
        $this->assertStringNotContainsString('DE EMPRESA DOS', $r->getContent());
    }

    /** Y el alta cuelga del workspace de quien la crea, no del que le manden. */
    public function test_el_alta_se_queda_en_el_workspace_del_actor(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('business_management.companies.store'), [
            'name'          => 'NUEVA',
            'complete_name' => 'Nueva Contratista S.A.',
            'num_doc'       => '20900000004',
            'country_id'    => 1,
            // Un intento de colarla en otro workspace por mass-assignment.
            'tenant_id'     => 2,
        ]);

        $creada = Company::withoutGlobalScopes()->where('num_doc', '20900000004')->first();

        $this->assertNotNull($creada);
        $this->assertSame($admin->tenant_id, $creada->tenant_id);
    }
}
