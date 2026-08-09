<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Red de seguridad del scaffold `make:module`. El comando clona el módulo Brand
 * (master) y, para inyectar los campos de --fields, depende de "anchors" de texto
 * EXACTOS en los archivos de Brand. Si alguien edita Brand y rompe un anchor, el
 * scaffold genera módulos rotos SIN avisar (es el bug de drift que motivó re-basar
 * de Customer a Brand).
 *
 * Este test NO corre el scaffold (no toca BD ni archivos). Solo verifica que los
 * archivos de Brand sigan conteniendo los anchors de los que el comando depende.
 * Si falla: o Brand cambió y hay que re-sincronizar los anchors en
 * MakeModuleCommand.php (injectDomainFields / injectFieldsFrontend), o se movió un
 * archivo del inventario que el scaffold clona.
 */
class MakeModuleScaffoldTest extends TestCase
{
    /** @return array<string,string> ruta => substring que DEBE existir */
    private function anchors(): array
    {
        return [
            // Inventario base que el comando clona.
            'app/Models/Brand.php'                                   => 'class Brand extends Model',
            'app/Http/Controllers/BusinessManagement/BrandController.php' => 'class BrandController',
            'app/Services/BusinessManagement/BrandService.php'      => 'class BrandService',
            'config/brands.php'                                     => '',
            'resources/lang/es/brands.php'                          => '',
            'resources/js/Pages/Brands/Form.vue'                    => '',
            'resources/js/Pages/Brands/Show.vue'                    => '',
            'resources/js/Pages/Brands/config/columns.js'           => '',
            'database/factories/BrandFactory.php'                   => '',
        ];
    }

    public function test_brand_template_files_exist(): void
    {
        foreach ($this->anchors() as $rel => $needle) {
            $this->assertFileExists(base_path($rel), "Falta el archivo template del scaffold: {$rel}");
            if ($needle !== '') {
                $this->assertStringContainsString($needle, File::get(base_path($rel)), "{$rel} ya no contiene '{$needle}'");
            }
        }
    }

    public function test_brand_model_keeps_scaffold_anchors(): void
    {
        $model = File::get(base_path('app/Models/Brand.php'));
        // El re-base a Brand asume estos traits + el fillable que arranca con slug,name.
        $this->assertStringContainsString('BelongsToTenantOrGlobal', $model);
        $this->assertStringContainsString('Lockable', $model, 'Brand debe traer Lockable (el scaffold inyecta columnas de lock).');
        // Anchor de fillable que usa injectDomainFields para meter los --fields.
        $this->assertMatchesRegularExpression("/'slug',\s*'name',/", $model, 'Anchor de fillable roto (injectDomainFields no inyectaría los --fields).');
        // Anchor de casts (is_active) — toleramos espacios variables (alineación).
        $this->assertMatchesRegularExpression("/'is_active'\s*=>\s*'boolean',/", $model, 'Anchor de casts roto.');
    }

    public function test_brand_migration_has_name_anchor(): void
    {
        $migs = File::glob(base_path('database/migrations/*_create_brands_table.php'));
        $this->assertNotEmpty($migs, 'No existe la migración create_brands_table (master del scaffold).');
        $mig = File::get($migs[0]);
        // injectDomainFields ancla los campos tras esta línea exacta.
        $this->assertStringContainsString("\$table->string('name')->index();", $mig, 'Anchor de migración roto (injectDomainFields).');
    }

    public function test_brand_frontend_keeps_field_injection_anchors(): void
    {
        $form = File::get(base_path('resources/js/Pages/Brands/Form.vue'));
        // useForm: injectFieldsFrontend ancla los defaults tras la línea de name.
        $this->assertStringContainsString("props.brand?.name ?? ''", $form, 'Anchor useForm de Form.vue roto.');

        $show = File::get(base_path('resources/js/Pages/Brands/Show.vue'));
        // Show usa spec-cell (no Ant Descriptions); injectFieldsFrontend ancla aquí.
        $this->assertStringContainsString('class="spec-cell__value">{{ brand.name }}</span>', $show, 'Anchor spec-cell de Show.vue roto.');

        $cols = File::get(base_path('resources/js/Pages/Brands/config/columns.js'));
        $this->assertStringContainsString("key: 'name'", $cols, 'Anchor de columns.js roto.');
    }

    /**
     * Las dos anclas de los campos propios: pruebas e importador.
     *
     * Sin ellas, un módulo declarado con `--fields="price:decimal"` nace con
     * todas sus pruebas en rojo («The Price field is required») y con un
     * importador que revienta en la primera fila con un NOT NULL. Lo segundo no
     * lo ve nadie hasta que un cliente sube su primer Excel.
     *
     * Son comentarios: un formateador o un despiste los borra sin que nada se
     * queje. Por eso están fijadas aquí.
     */
    public function test_brand_conserva_las_anclas_de_los_campos_propios(): void
    {
        $prueba = File::get(base_path('tests/Feature/BusinessManagement/BrandCrudTest.php'));

        $this->assertStringContainsString('return []; // make:module:campos-propios', $prueba,
            'Ancla de camposPropios() en BrandCrudTest rota: los módulos nuevos nacerían con todas las pruebas en rojo.');

        // Todo payload de alta/edición tiene que pasar por `campos()`, o el
        // módulo clonado no recibirá sus campos propios en esa prueba.
        $this->assertStringNotContainsString("brands.store'), [", $prueba,
            'Hay un payload que no pasa por $this->campos().');
        $this->assertStringNotContainsString('Brand::create([', $prueba,
            'Hay un Brand::create que no pasa por $this->campos().');

        $import = File::get(base_path('app/Imports/BusinessManagement/Brands/BrandsImport.php'));

        $this->assertStringContainsString('return []; // make:module:campos-propios-import', $import,
            'Ancla de camposPropios() en BrandsImport rota: el importador del módulo nuevo reventaría con un NOT NULL.');
    }

    /**
     * El generador emite la ruta de «alta rápida».
     *
     * El controlador clonado SIEMPRE trae `quickStore()`. La plantilla de rutas
     * no la emitía, así que cada módulo generado nacía con un método público al
     * que no llegaba ninguna ruta — y su prueba clonada fallaba con
     * `RouteNotFoundException`.
     */
    public function test_el_generador_emite_la_ruta_de_alta_rapida(): void
    {
        $generador = File::get(base_path('app/Console/Commands/MakeModuleCommand.php'));

        $this->assertStringContainsString(".quick_store'", $generador,
            'La plantilla de rutas de make:module no emite quick_store.');
    }
}
