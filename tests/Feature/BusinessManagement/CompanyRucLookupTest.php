<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use Illuminate\Support\Facades\Http;

/**
 * La consulta de RUC en SUNAT, que el sistema anterior tenia y aqui se habia
 * perdido al portar el modulo.
 *
 * Lo que se comprueba no es que la API funcione —es de terceros y no hay token
 * en pruebas— sino que la pantalla NUNCA se queda bloqueada por su culpa: sin
 * token, con la API caida o con un RUC que no existe, la respuesta dice que
 * pasa y el alta se puede seguir a mano, como hacia la v1.
 */
class CompanyRucLookupTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'companies';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas hablan el dialecto de apis.net.pe (`/v2/...`,
        // `razonSocial`). El proveedor por defecto es Decolecta, asi que se fija
        // aqui; el dialecto de Decolecta tiene su propia prueba abajo.
        config(['services.peru_lookup.provider' => \App\Services\Peru\Proveedor::APIS_NET_PE]);
        config(['services.peru_lookup.url' => null]);
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Company::create($this->base() + [
            'name'          => 'HITACHI',
            'complete_name' => 'Hitachi Energy Perú S.A.',
            'num_doc'       => '20512345678',
        ]);
    }

    private function consultar(string $ruc)
    {
        return $this->getJson(route('business_management.companies.lookup_ruc', ['ruc' => $ruc]));
    }

    public function test_devuelve_la_razon_social_cuando_sunat_la_encuentra(): void
    {
        config(['services.peru_lookup.token' => 'token-de-prueba']);
        Http::fake(['*/v2/sunat/ruc*' => Http::response([
            'numeroDocumento' => '20512345678',
            'razonSocial'     => 'HITACHI ENERGY PERU S.A.C.',
            'direccion'       => 'AV. SIEMPRE VIVA 123',
            'estado'          => 'ACTIVO',
        ])]);

        $this->actingAs($this->admin());

        $this->consultar('20512345678')
            ->assertOk()
            ->assertJson([
                'estado'       => 'encontrado',
                'razon_social' => 'HITACHI ENERGY PERU S.A.C.',
                'activo'       => true,
            ]);
    }

    /** Una empresa de baja en SUNAT se marca, no se oculta. */
    public function test_una_empresa_dada_de_baja_en_sunat_se_reporta_como_inactiva(): void
    {
        config(['services.peru_lookup.token' => 'token-de-prueba']);
        Http::fake(['*/v2/sunat/ruc*' => Http::response([
            'razonSocial' => 'CERRADA SAC',
            'estado'      => 'BAJA DE OFICIO',
        ])]);

        $this->actingAs($this->admin());

        $this->consultar('20512345678')->assertOk()->assertJson([
            'estado' => 'encontrado',
            'activo' => false,
        ]);
    }

    /** Sin token no es culpa del usuario: se dice, y no se le enseña un error. */
    public function test_sin_token_configurado_no_es_un_error(): void
    {
        config(['services.peru_lookup.token' => null]);
        Http::fake();

        $this->actingAs($this->admin());

        $this->consultar('20512345678')->assertOk()->assertJson(['estado' => 'sin_configurar']);
        Http::assertNothingSent();
    }

    /** La API caida no puede impedir dar de alta una empresa. */
    public function test_si_la_api_falla_se_puede_seguir_a_mano(): void
    {
        config(['services.peru_lookup.token' => 'token-de-prueba']);
        Http::fake(['*/v2/sunat/ruc*' => Http::response('boom', 500)]);

        $this->actingAs($this->admin());

        $this->consultar('20512345678')->assertOk()->assertJson(['estado' => 'error']);
    }

    public function test_un_ruc_que_no_existe_se_reporta_como_no_encontrado(): void
    {
        config(['services.peru_lookup.token' => 'token-de-prueba']);
        Http::fake(['*/v2/sunat/ruc*' => Http::response(['message' => 'not found'], 404)]);

        $this->actingAs($this->admin());

        $this->consultar('20512345678')->assertOk()->assertJson(['estado' => 'no_encontrado']);
    }

    /**
     * Y el otro proveedor, que es el que de verdad se usa.
     *
     * Decolecta llama a la razon social `razon_social` y contesta en
     * `/v1/sunat/ruc`. Con el token de uno apuntando al otro sale un 401 y
     * parece que la API esta rota.
     */
    public function test_tambien_entiende_a_decolecta(): void
    {
        config(['services.peru_lookup.provider' => \App\Services\Peru\Proveedor::DECOLECTA]);
        config(['services.peru_lookup.token' => 'token-de-prueba']);

        Http::fake(['api.decolecta.com/*' => Http::response([
            'razon_social' => 'HITACHI ENERGY PERU S.A.C.',
            'direccion'    => 'AV. SIEMPRE VIVA 123',
            'estado'       => 'ACTIVO',
        ])]);

        $this->actingAs($this->admin());

        $this->consultar('20512345678')
            ->assertOk()
            ->assertJson(['estado' => 'encontrado', 'razon_social' => 'HITACHI ENERGY PERU S.A.C.']);

        Http::assertSent(fn ($p) => str_contains($p->url(), 'api.decolecta.com/v1/sunat/ruc'));
    }

    /** Un RUC corto ni sale a la red: se ahorra la llamada y la cuota. */
    public function test_un_ruc_incompleto_no_llega_a_llamar_a_la_api(): void
    {
        config(['services.peru_lookup.token' => 'token-de-prueba']);
        Http::fake();

        $this->actingAs($this->admin());

        $this->consultar('2051234')->assertOk()->assertJson(['estado' => 'no_encontrado']);
        Http::assertNothingSent();
    }
}
