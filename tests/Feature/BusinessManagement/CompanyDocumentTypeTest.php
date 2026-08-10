<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\Country;
use App\Models\DocumentType;
use Illuminate\Support\Str;

/**
 * La empresa tambien lleva documento, y tampoco es el mismo en todas partes.
 *
 * `companies.num_doc` era un texto suelto con `min:3|max:20`, sin tipo y
 * llamado «codigo» en la pantalla. Los dos FormRequest llevaban el mismo
 * comentario:
 *
 *   «No pongo digits:11 porque eso es el RUC peruano y el modulo es multi-pais:
 *    cada uno tiene su documento fiscal.»
 *
 * La observacion era correcta y la conclusion no. Lo que faltaba no era
 * renunciar a la regla, era saber DE QUE documento se habla: con el tipo
 * delante, un RUC peruano son once cifras y se puede comprobar; en Chile seria
 * un RUT y la regla saldria de su fila. Es la misma tabla que la del DNI de una
 * persona, con `scope` distinto.
 */
class CompanyDocumentTypeTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'companies';
    }

    protected function setUp(): void
    {
        parent::setUp();

        DocumentType::firstOrCreate(
            ['country_id' => 1, 'scope' => DocumentType::EMPRESA, 'code' => 'RUC'],
            ['slug' => Str::random(22), 'name' => 'Registro Único de Contribuyentes',
             'min_length' => 11, 'max_length' => 11, 'allowed_chars' => DocumentType::SOLO_CIFRAS,
             'is_active' => true, 'created_by' => 1],
        );

        DocumentType::firstOrCreate(
            ['country_id' => 1, 'scope' => DocumentType::PERSONA, 'code' => 'DNI'],
            ['slug' => Str::random(22), 'name' => 'Documento Nacional de Identidad',
             'min_length' => 8, 'max_length' => 8, 'allowed_chars' => DocumentType::SOLO_CIFRAS,
             'is_active' => true, 'created_by' => 1],
        );
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Company::create($this->base() + [
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'num_doc' => '20100000001',
        ]);
    }

    private function alta(array $extra = [])
    {
        return $this->actingAs($this->admin())->post(route('business_management.companies.store'), $extra + [
            'country_id' => 1, 'doc_type' => 'RUC', 'num_doc' => '20487654321',
            'name' => 'LIMTEK', 'complete_name' => 'Limtek Servicios Integrales S.A.',
        ]);
    }

    /** Un RUC son once cifras: ni diez ni doce. */
    public function test_un_ruc_de_diez_cifras_no_entra(): void
    {
        $this->alta(['num_doc' => '2048765432'])->assertSessionHasErrors('num_doc');

        $this->assertDatabaseMissing('companies', ['num_doc' => '2048765432']);
    }

    public function test_un_ruc_de_once_cifras_entra(): void
    {
        $this->alta()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['num_doc' => '20487654321', 'doc_type' => 'RUC']);
    }

    /** Y solo cifras: la regla es la misma que la del DNI, del mismo catalogo. */
    public function test_un_ruc_con_letras_no_entra_aunque_mida_once(): void
    {
        $this->alta(['num_doc' => '2048765432A'])->assertSessionHasErrors('num_doc');
    }

    /** El tipo tiene que ser uno del catalogo de ese pais, y de empresa. */
    public function test_no_se_admite_un_tipo_que_no_es_de_ese_pais(): void
    {
        $this->alta(['doc_type' => 'CUIT'])->assertSessionHasErrors('doc_type');
    }

    /**
     * Y no vale colar el de una persona: son el mismo catalogo con `scope`
     * distinto, asi que sin filtrar por scope el DNI habria pasado.
     */
    public function test_el_documento_de_una_persona_no_sirve_para_una_empresa(): void
    {
        $this->alta(['doc_type' => 'DNI', 'num_doc' => '45871236'])->assertSessionHasErrors('doc_type');
    }

    /**
     * Al reves tampoco: el selector de una persona no puede ofrecer el RUC.
     *
     * Es lo que habria pasado al meter las dos cosas en la misma tabla sin
     * separar: `DocumentType::delPais()` devolvia todo lo del pais.
     */
    public function test_el_ruc_no_sale_en_el_catalogo_de_personas(): void
    {
        $dePersona = DocumentType::delPais(1)->pluck('code')->all();
        $deEmpresa = DocumentType::delPais(1, DocumentType::EMPRESA)->pluck('code')->all();

        $this->assertContains('DNI', $dePersona);
        $this->assertNotContains('RUC', $dePersona);

        $this->assertContains('RUC', $deEmpresa);
        $this->assertNotContains('DNI', $deEmpresa);
    }

    /**
     * Si no viene el tipo se deduce del catalogo del pais.
     *
     * La pantalla siempre lo manda; la migracion de la v1 y las llamadas de
     * fuera, no. En Peru solo hay un documento de empresa: pedirlo seria pedir
     * un dato que solo puede tener un valor.
     */
    public function test_sin_tipo_se_toma_el_unico_del_pais(): void
    {
        $this->actingAs($this->admin())->post(route('business_management.companies.store'), [
            'country_id' => 1, 'num_doc' => '20487654322',
            'name' => 'ACME', 'complete_name' => 'ACME Servicios Generales S.A.C.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['num_doc' => '20487654322', 'doc_type' => 'RUC']);
    }

    /** Un pais sin catalogo de empresa no se queda sin poder dar de alta. */
    public function test_un_pais_sin_catalogo_sigue_admitiendo_altas(): void
    {
        $chile = Country::firstOrCreate(
            ['iso_code' => 'CL'],
            ['slug' => Str::random(22), 'region_id' => 999, 'name' => 'Chile',
             'currency' => 'CLP', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true],
        );

        $this->actingAs($this->admin())->post(route('business_management.companies.store'), [
            'country_id' => $chile->id, 'num_doc' => '761234567',
            'name' => 'Contratista CL', 'complete_name' => 'Contratista Chile SpA',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['num_doc' => '761234567']);
    }

    /**
     * El tipo que la empresa YA tiene sigue valiendo aunque se de de baja del
     * catalogo: corregirle el nombre no puede cambiarle el documento.
     */
    public function test_el_tipo_guardado_sigue_valiendo_si_se_da_de_baja(): void
    {
        $empresa = Company::create($this->base() + [
            'name' => 'Antigua', 'complete_name' => 'Antigua SAC',
            'doc_type' => 'RUC-VIEJO', 'num_doc' => '20100000009',
        ]);

        $this->actingAs($this->admin())
            ->put(route('business_management.companies.update', $empresa->slug), [
                'country_id' => 1, 'doc_type' => 'RUC-VIEJO', 'num_doc' => '20100000009',
                'name' => 'Antigua Corregida', 'complete_name' => 'Antigua SAC',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Antigua Corregida', $empresa->fresh()->name);
    }
}
