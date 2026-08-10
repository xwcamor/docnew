<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\Person;
use App\Models\Tenant;

/**
 * «Mi empresa» se queda marcada aunque se rehaga la base.
 *
 * De ese ajuste depende a quien se le pregunta que aprueba: los roles en obra
 * son de la gente de la empresa que contrata, no de las contratistas. Sin el,
 * el selector de aprobadores del plan ofrece el padron entero.
 *
 * Y se ponia a mano en Ajustes, en una base que `setup:project --datos` rehace
 * de cero: en cada carga volvia a quedarse vacio, y lo unico que se veia era
 * que de pronto cualquiera podia ser supervisor otra vez.
 *
 * Va por DOCUMENTO y no por id, que es lo unico que sobrevive a un
 * `migrate:fresh`. Y es un ajuste de la INSTALACION: el nombre de un cliente no
 * se escribe en el codigo de un producto que se vende a varios.
 */
class MiEmpresaSeMarcaSolaTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'companies';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->empresa('20100000001', 'Contratista');
    }

    private function empresa(string $doc, string $nombre): Company
    {
        return Company::firstOrCreate(
            ['num_doc' => $doc],
            $this->base() + ['name' => $nombre, 'complete_name' => $nombre . ' SAC'],
        );
    }

    public function test_la_empresa_del_env_queda_marcada(): void
    {
        $mia = $this->empresa('20321000022', 'La que contrata');
        $this->empresa('20521314649', 'Contratista');

        config(['companies.own_doc' => '20321000022']);

        $tenant = Tenant::find(1);
        $this->assertNull($tenant->company_id);

        $tenant->marcarSuEmpresaSiLaInstalacionLaDice();

        $this->assertSame($mia->id, $tenant->fresh()->company_id);
    }

    /** Con espacios de sobra en el `.env`, que es como se pegan estas cosas. */
    public function test_el_documento_se_lee_aunque_venga_con_espacios(): void
    {
        $mia = $this->empresa('20321000022', 'La que contrata');
        config(['companies.own_doc' => '  20321000022  ']);

        Tenant::find(1)->marcarSuEmpresaSiLaInstalacionLaDice();

        $this->assertSame($mia->id, Tenant::find(1)->company_id);
    }

    /** Sin la variable no se marca nada y se sigue preguntando en la pantalla. */
    public function test_sin_la_variable_no_se_marca_nada(): void
    {
        $this->empresa('20321000022', 'La que contrata');
        config(['companies.own_doc' => null]);

        Tenant::find(1)->marcarSuEmpresaSiLaInstalacionLaDice();

        $this->assertNull(Tenant::find(1)->company_id);
    }

    /** Un documento que no está entre las empresas no inventa ninguna. */
    public function test_un_documento_que_no_existe_no_marca_a_otra(): void
    {
        $this->empresa('20521314649', 'Contratista');
        config(['companies.own_doc' => '20999999999']);

        Tenant::find(1)->marcarSuEmpresaSiLaInstalacionLaDice();

        $this->assertNull(Tenant::find(1)->company_id);
    }

    /**
     * Lo elegido en la pantalla manda sobre el fichero de configuracion.
     *
     * Al reves, cambiar de empresa en Ajustes duraria hasta la siguiente carga y
     * nadie entenderia por que se le mueve solo.
     */
    public function test_no_pisa_lo_que_ya_estaba_elegido(): void
    {
        $this->empresa('20321000022', 'La que contrata');
        $otra = $this->empresa('20521314649', 'Contratista');

        Tenant::find(1)->forceFill(['company_id' => $otra->id])->save();
        config(['companies.own_doc' => '20321000022']);

        Tenant::find(1)->marcarSuEmpresaSiLaInstalacionLaDice();

        $this->assertSame($otra->id, Tenant::find(1)->company_id);
    }

    /**
     * Y con eso el formulario de personas ya distingue: a la gente de la propia
     * empresa se le preguntan los roles y a la de una contratista no. Es para lo
     * que sirve el ajuste, asi que se comprueba de punta a punta.
     */
    public function test_marcada_la_empresa_la_contratista_deja_de_repartir_roles(): void
    {
        $mia = $this->empresa('20321000022', 'La que contrata');
        $contratista = $this->empresa('20521314649', 'Contratista');

        config(['companies.own_doc' => '20321000022']);
        Tenant::find(1)->marcarSuEmpresaSiLaInstalacionLaDice();

        $this->actingAs($this->admin())
            ->get(route('business_management.people.create'))
            ->assertInertia(fn ($page) => $page->where('ownCompanyId', $mia->id));

        $this->assertNotSame($mia->id, $contratista->id);
        $this->assertSame(0, Person::count());
    }
}
