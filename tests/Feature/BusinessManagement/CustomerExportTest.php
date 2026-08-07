<?php

namespace Tests\Feature\BusinessManagement;

use App\Jobs\BusinessManagement\Customers\GenerateCustomersCsvJob;
use App\Jobs\BusinessManagement\Customers\GenerateCustomersExcelJob;
use App\Models\Download;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Endpoints de export del modulo Customers. Verifica que los formatos async
 * (CSV/Excel/PDF/Word) despachan el Job correcto al queue. El detalle del
 * contenido se cubre en tests dedicados al Job (no estan aca).
 */
class CustomerExportTest extends CustomerTestCase
{
    /** Solo el super puede exportar id y slug (identificadores internos). */
    public function test_super_can_export_id_and_slug(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();

        $response = $this->post(route('business_management.customers.export_excel'), [
            'columns' => ['id', 'slug', 'name'],
            'scope'   => 'all',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        Bus::assertDispatched(GenerateCustomersExcelJob::class);
    }

    /** Un no-super NO puede exportar id ni slug: el backend lo rechaza. */
    public function test_non_super_cannot_export_id_or_slug(): void
    {
        Bus::fake();
        $this->actingAsTenantAdmin(1);

        $response = $this->post(route('business_management.customers.export_excel'), [
            'columns' => ['id', 'name'],
            'scope'   => 'all',
        ]);

        $response->assertSessionHasErrors('columns.0');
        Bus::assertNotDispatched(GenerateCustomersExcelJob::class);
    }

    public function test_export_excel_queues_job(): void
    {
        Bus::fake();
        $this->actingAsTenantAdmin(1);

        $response = $this->post(route('business_management.customers.export_excel'), [
            'columns' => ['name', 'cod'],
            'scope'   => 'all',
        ]);

        $response->assertRedirect();
        Bus::assertDispatched(GenerateCustomersExcelJob::class);
    }

    public function test_export_csv_queues_job(): void
    {
        Bus::fake();
        $this->actingAsTenantAdmin(1);

        $response = $this->post(route('business_management.customers.export_csv'), [
            'columns' => ['name', 'cod'],
            'scope'   => 'all',
        ]);

        $response->assertRedirect();
        Bus::assertDispatched(GenerateCustomersCsvJob::class);
    }

    /**
     * Regresión: el diálogo manda por defecto address + los conteos de jerarquía.
     * El allowlist de columnas del controller debe aceptarlos (si no, el POST
     * vuelve con errores de validación y el job nunca se despacha → "no exporta").
     */
    public function test_export_accepts_address_and_hierarchy_count_columns(): void
    {
        Bus::fake();
        $this->actingAsTenantAdmin(1);

        $response = $this->post(route('business_management.customers.export_excel'), [
            'columns' => [
                'name', 'cod', 'country', 'address', 'is_active',
                'locations_count', 'areas_count', 'substations_count', 'transformers_count',
                'created_at',
            ],
            'scope'   => 'all',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        Bus::assertDispatched(GenerateCustomersExcelJob::class);
    }

    /**
     * El CSV debe exportar los datos COMPLETOS: cod, país, dirección y los
     * conteos de la jerarquía (ubicaciones/áreas/subestaciones/transformadores).
     */
    public function test_csv_export_includes_full_data_and_hierarchy_counts(): void
    {
        Storage::fake('local');
        $user = $this->actingAsTenantAdmin(1);

        // Cliente con jerarquía: 1 ubicación → 2 áreas → 3 subestaciones.
        \DB::table('customers')->insert([
            'id' => 7001, 'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 2,
            'cod' => 'RUC-FULL', 'name' => 'Cliente Completo', 'address' => 'Av. Siempre Viva 123',
            'is_active' => true, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('customer_locations')->insert(['id' => 7001, 'slug' => Str::random(22), 'customer_id' => 7001, 'name' => 'Sede', 'tenant_id' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([7001, 7002] as $aid) {
            \DB::table('customer_areas')->insert(['id' => $aid, 'slug' => Str::random(22), 'customer_location_id' => 7001, 'name' => 'Area ' . $aid, 'tenant_id' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([7001, 7002, 7003] as $i => $sid) {
            \DB::table('customer_substations')->insert(['id' => $sid, 'slug' => Str::random(22), 'customer_area_id' => 7001, 'name' => 'Sub ' . $sid, 'tenant_id' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $columns = ['id', 'name', 'cod', 'country', 'address', 'is_active',
            'locations_count', 'areas_count', 'substations_count', 'transformers_count'];
        $job = new GenerateCustomersCsvJob($user->id, ['scope' => 'all', 'columns' => $columns]);
        $job->handle();

        $download = Download::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('ready', $download->status);

        $csv = Storage::disk('local')->get($download->path);
        $this->assertStringContainsString('Cliente Completo', $csv);
        $this->assertStringContainsString('RUC-FULL', $csv);
        $this->assertStringContainsString('Av. Siempre Viva 123', $csv);
        // Fila con los conteos: 1 ubicación, 2 áreas, 3 subestaciones, 0 trafos.
        $this->assertMatchesRegularExpression('/Cliente Completo.*,1,2,3,0/', $csv);
    }

    /**
     * El export con scope 'filtered' debe respetar el orden de la vista: si los
     * filtros traen sort=name&direction=asc, las filas salen A→Z (no por id).
     */
    public function test_export_respects_current_sort(): void
    {
        Storage::fake('local');
        $user = $this->actingAsTenantAdmin(1);

        // id mayor = nombre que va primero alfabéticamente (para distinguir de id desc).
        \DB::table('customers')->insert([
            ['id' => 8001, 'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 2, 'cod' => 'C1', 'name' => 'Zeta',  'is_active' => true, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8002, 'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 2, 'cod' => 'C2', 'name' => 'Alpha', 'is_active' => true, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $job = new GenerateCustomersCsvJob($user->id, [
            'scope'   => 'filtered',
            'columns' => ['name'],
            'filters' => ['sort' => 'name', 'direction' => 'asc'],
        ]);
        $job->handle();

        $download = Download::where('user_id', $user->id)->latest('id')->first();
        $csv = Storage::disk('local')->get($download->path);

        // Alpha (id 8002) debe aparecer ANTES que Zeta (id 8001) → respeta name asc.
        $this->assertLessThan(strpos($csv, 'Zeta'), strpos($csv, 'Alpha'));
    }

    /**
     * Regresión: el export NO debe incluir la papelera (soft-deleted). El job
     * usaba withoutGlobalScopes() que tumbaba también el scope de SoftDeletes →
     * exportaba los borrados (344 en vez de 174). Debe excluirlos.
     */
    public function test_export_excludes_soft_deleted(): void
    {
        Storage::fake('local');
        $user = $this->actingAsTenantAdmin(1);

        \DB::table('customers')->insert([
            ['id' => 9101, 'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 2, 'cod' => 'AC', 'name' => 'ActivoX', 'is_active' => true,  'created_by' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['id' => 9102, 'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 2, 'cod' => 'PB', 'name' => 'PapeleraX', 'is_active' => false, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
        ]);

        $job = new GenerateCustomersCsvJob($user->id, ['scope' => 'all', 'columns' => ['name']]);
        $job->handle();

        $download = Download::where('user_id', $user->id)->latest('id')->first();
        $csv = Storage::disk('local')->get($download->path);

        $this->assertStringContainsString('ActivoX', $csv);
        $this->assertStringNotContainsString('PapeleraX', $csv);
    }

    public function test_import_template_downloads_xlsx(): void
    {
        $this->actingAsTenantAdmin(1);

        $response = $this->get(route('business_management.customers.import_template'));

        $response->assertOk();
        // Maatwebsite\Excel manda el archivo como attachment.
        $contentType = $response->headers->get('content-type', '');
        $disposition = $response->headers->get('content-disposition', '');
        $this->assertTrue(
            str_contains($disposition, 'attachment') || str_contains($contentType, 'spreadsheet'),
            'La respuesta debe ser una descarga (attachment o xlsx).'
        );
    }
}
