<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Una empresa guardada dice con que documento se identifica, entre o por donde
 * entre.
 *
 * En el listado se veia «20522756441» a secas en todas las empresas traidas del
 * sistema anterior, mientras la unica dada de alta desde el formulario ponia
 * «RUC 20522756441». Todas son peruanas y todas llevan RUC: la diferencia no
 * estaba en el dato, estaba en quien lo habia escrito.
 *
 * `companies.doc_type` tenia `default 'RUC'` y el migrador nunca escribia la
 * columna porque no le hacia falta. Quitar ese valor por defecto estaba bien —una
 * contratista chilena se guardaba en silencio con un documento peruano— pero
 * dejo al migrador sin la muleta, y eso no se vio: lo migrado siguio igual y lo
 * nuevo entro en blanco.
 *
 * De ahi que la prueba mire el CATALOGO y no la cadena «RUC»: la empresa de al
 * lado puede ser chilena y llevar RUT.
 */
class LaEmpresaDiceQueDocumentoLlevaTest extends CatalogTestCase
{
    private const CHILE = 88;

    protected function moduleKey(): string
    {
        return 'companies';
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('countries')->insertOrIgnore([[
            'id' => self::CHILE, 'slug' => Str::random(22), 'region_id' => 999,
            'name' => 'Chile', 'iso_code' => 'CL', 'currency' => 'CLP', 'timezone' => 'UTC',
            'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);

        $this->tipo(1, 'RUC', 11, 11, DocumentType::SOLO_CIFRAS);
        $this->tipo(self::CHILE, 'RUT', 8, 10, DocumentType::CIFRAS_Y_LETRAS);
    }

    private function tipo(int $pais, string $code, int $min, int $max, string $chars): DocumentType
    {
        return DocumentType::firstOrCreate(
            ['country_id' => $pais, 'scope' => DocumentType::EMPRESA, 'code' => $code],
            ['slug' => Str::random(22), 'name' => $code, 'min_length' => $min, 'max_length' => $max,
             'allowed_chars' => $chars, 'is_active' => true, 'created_by' => 1],
        );
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Company::create($this->base() + [
            'doc_type' => 'RUC', 'num_doc' => '20100000001',
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC',
        ]);
    }

    /** Cada país trae el suyo, y no el del vecino. */
    public function test_el_documento_de_empresa_sale_del_catalogo_del_pais(): void
    {
        $this->assertSame('RUC', DocumentType::deLaEmpresaDe(1));
        $this->assertSame('RUT', DocumentType::deLaEmpresaDe(self::CHILE));
    }

    /**
     * Sin catalogo, sin respuesta. Es mejor dejarlo vacio que inventarse un
     * «RUC» que le pondria a una empresa de otro sitio un documento peruano —
     * que es exactamente lo que hacia el `default` de la columna.
     */
    public function test_un_pais_sin_documento_de_empresa_no_devuelve_ninguno(): void
    {
        $this->assertNull(DocumentType::deLaEmpresaDe(999));
        $this->assertNull(DocumentType::deLaEmpresaDe(null));
    }

    /** Con dos en el catálogo no hay uno obvio, y tampoco se elige por nadie. */
    public function test_con_dos_documentos_de_empresa_no_se_elige_por_nadie(): void
    {
        $this->tipo(1, 'RUS', 11, 11, DocumentType::SOLO_CIFRAS);

        $this->assertNull(DocumentType::deLaEmpresaDe(1));
    }

    /**
     * La columna NO puede volver a tener valor por defecto.
     *
     * Es la raiz del fallo por los dos lados: tapaba que el migrador no
     * escribiera el tipo, y le colgaba un documento peruano a una empresa
     * chilena sin que nadie lo pidiera.
     */
    public function test_la_columna_no_tiene_valor_por_defecto(): void
    {
        $empresa = new Company();

        $this->assertNull(
            $empresa->doc_type,
            'La columna volvió a traer un tipo por defecto: a una empresa de otro país le pondría el documento equivocado.',
        );
    }

    /** Y una vez guardada, el listado la enseña con su tipo delante. */
    public function test_el_listado_enseña_el_tipo_junto_al_numero(): void
    {
        $this->unaFila();

        $this->actingAs($this->admin())
            ->get(route('business_management.companies.index'))
            ->assertInertia(function ($page) {
                $filas = $page->toArray()['props']['companies']['data'];

                $this->assertNotEmpty($filas, 'El listado llegó sin empresas.');
                $this->assertSame('RUC', $filas[0]['doc_type'], 'La empresa llegó al listado sin decir qué documento lleva.');
            });
    }
}
