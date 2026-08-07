<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\OilType;
use App\Models\TapChangerType;
use App\Models\Transformer;
use App\Models\TransformerType;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Renderiza las plantillas blade de export PDF de cada módulo de negocio con una
 * fila de datos. Guarda contra el bug de "la vista no existe / no compila" (que
 * tiraba excepción al exportar PDF en brands, conmutador, tipo de trafo y
 * transformers). No toca la BD: usa modelos sin persistir.
 */
class ExportPdfTemplatesTest extends TestCase
{
    public static function templateProvider(): array
    {
        return [
            'customers'         => ['business_management.customers.pdf.template', 'customers', Customer::class, ['id', 'name', 'cod', 'country', 'is_active', 'created_at']],
            'oil_types'         => ['business_management.oil_types.pdf.template', 'oil_types', OilType::class, ['id', 'name', 'code', 'is_active', 'created_at']],
            'brands'            => ['business_management.brands.pdf.template', 'brands', Brand::class, ['id', 'name', 'code', 'is_active', 'created_at']],
            'tap_changer_types' => ['business_management.tap_changer_types.pdf.template', 'tap_changer_types', TapChangerType::class, ['id', 'name', 'code', 'is_active', 'created_at']],
            'transformer_types' => ['business_management.transformer_types.pdf.template', 'transformer_types', TransformerType::class, ['id', 'name', 'code', 'is_active', 'created_at']],
            'transformers'      => ['business_management.transformers.pdf.template', 'transformers', Transformer::class, ['id', 'serial', 'tag', 'is_active', 'created_at']],
        ];
    }

    /** @dataProvider templateProvider */
    public function test_pdf_template_renders(string $view, string $collectionKey, string $modelClass, array $columns): void
    {
        $model = new $modelClass();
        $model->id = 1;
        $model->serial = 'SN-1';
        $model->tag = 'TR01';
        $model->name = 'Demo';
        $model->code = 'demo';
        $model->cod = 'RUC1';
        $model->is_active = true;

        $html = View::make($view, [
            $collectionKey   => collect([$model]),
            'columns'        => $columns,
            'title'          => 'Reporte',
            'filtersSummary' => [],
            'generatedBy'    => 'Tester',
            'totalCount'     => 1,
            'tz'             => 'UTC',
        ])->render();

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<td', $html); // la fila de datos se renderizó
    }
}
