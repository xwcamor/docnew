<?php

namespace Tests\Feature\BusinessManagement;

use App\Imports\BusinessManagement\Companies\CompaniesImport;
use App\Models\Company;
use Illuminate\Support\Collection;

/**
 * El import de empresas contra lo que la tabla exige de verdad.
 *
 * `companies.num_doc` y `companies.country_id` son NOT NULL. El importador
 * trataba el RUC como opcional —resto del clon, donde el campo equivalente si
 * lo era— y mandaba un NULL a la base: la transaccion entera se caia y el
 * usuario veia «no se pudo procesar el archivo» sin saber en que fila.
 */
class CompanyImportTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'companies';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Company::create($this->base() + [
            'name' => 'HITACHI', 'complete_name' => 'Hitachi Energy Perú S.A.', 'num_doc' => '20512345678',
        ]);
    }

    public function test_una_fila_sin_ruc_se_rechaza_y_no_tumba_el_resto_del_fichero(): void
    {
        $this->actingAs($this->admin());

        $import = new CompaniesImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'HITACHI', 'num_doc' => '20512345678'],
            ['name' => 'SIN RUC', 'num_doc' => ''],
            ['name' => 'LIMTEK',  'num_doc' => '20487654321'],
        ]));

        $this->assertSame(2, $import->created, 'las filas buenas se dan de alta igual');
        $this->assertCount(1, $import->errors);
        // Fila 3 del Excel: la 1 es la cabecera.
        $this->assertSame(3, $import->errors[0]['row'], 'el error apunta a la fila del fichero');

        $this->assertDatabaseHas('companies', ['name' => 'HITACHI', 'num_doc' => '20512345678']);
        $this->assertDatabaseHas('companies', ['name' => 'LIMTEK',  'num_doc' => '20487654321']);
        $this->assertDatabaseMissing('companies', ['name' => 'SIN RUC']);
    }

    /** El RUC del Excel llega con guiones; se guarda como lo guarda la pantalla. */
    public function test_el_ruc_del_fichero_se_guarda_sin_espacios_ni_guiones(): void
    {
        $this->actingAs($this->admin());

        $import = new CompaniesImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'LIMTEK', 'num_doc' => '20-4876 54321'],
        ]));

        $this->assertDatabaseHas('companies', ['name' => 'LIMTEK', 'num_doc' => '20487654321']);
    }

    /** 20 es lo que aguanta la columna; antes se dejaban pasar hasta 40. */
    public function test_un_ruc_mas_largo_que_la_columna_se_rechaza_antes_de_llegar_a_la_base(): void
    {
        $this->actingAs($this->admin());

        $import = new CompaniesImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'LARGA', 'num_doc' => str_repeat('9', 25)],
        ]));

        $this->assertSame(0, $import->created);
        $this->assertCount(1, $import->errors);
        $this->assertDatabaseMissing('companies', ['name' => 'LARGA']);
    }

    /** La razon social es la del documento: el fichero puede traerla. */
    public function test_la_razon_social_del_fichero_se_guarda(): void
    {
        $this->actingAs($this->admin());

        $import = new CompaniesImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'LIMTEK', 'num_doc' => '20487654321', 'complete_name' => 'Limtek Servicios Integrales S.A.'],
        ]));

        $this->assertSame(1, $import->created);
        $this->assertDatabaseHas('companies', [
            'name'          => 'LIMTEK',
            'complete_name' => 'Limtek Servicios Integrales S.A.',
        ]);
    }

    /** Si no viene, se copia el nombre corto hasta que alguien la complete. */
    public function test_sin_razon_social_en_el_fichero_se_usa_el_nombre_corto(): void
    {
        $this->actingAs($this->admin());

        $import = new CompaniesImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'LIMTEK', 'num_doc' => '20487654321'],
        ]));

        $this->assertDatabaseHas('companies', ['name' => 'LIMTEK', 'complete_name' => 'LIMTEK']);
    }

    /** El pais sale del usuario y es NOT NULL en la tabla: se guarda. */
    public function test_el_pais_de_las_altas_es_el_del_usuario_que_importa(): void
    {
        $this->actingAs($this->admin());

        $import = new CompaniesImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'LIMTEK', 'num_doc' => '20487654321'],
        ]));

        $this->assertDatabaseHas('companies', ['name' => 'LIMTEK', 'country_id' => 1, 'tenant_id' => 1]);
    }

    /** Modo actualizar: refresca RUC y razon social de la que ya existe. */
    public function test_en_modo_actualizar_se_refresca_el_ruc_y_la_razon_social(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->unaFila();

        $import = new CompaniesImport(mode: 'update_or_create', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'hitachi', 'num_doc' => '20599999999', 'complete_name' => 'Hitachi Energy Perú S.A.C.'],
        ]));

        $this->assertSame(1, $import->updated);
        $this->assertDatabaseHas('companies', [
            'id'            => $empresa->id,
            'num_doc'       => '20599999999',
            'complete_name' => 'Hitachi Energy Perú S.A.C.',
        ]);
    }
}
