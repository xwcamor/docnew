<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Models\AuditLog;
use App\Models\Region;
use App\Services\SystemManagement\RegionService;

/**
 * Cobertura del trait Auditable sobre Region. Cada mutación (create, update,
 * soft-delete, restore, force-delete) escribe un registro en audit_logs.
 * Previene regresiones silenciosas si alguien rompe el trait.
 */
class RegionAuditLogTest extends RegionTestCase
{
    public function test_create_writes_audit_log(): void
    {
        $user    = $this->actingAsSuperAdmin();
        $service = app(RegionService::class);

        $region = $service->create(['name' => 'Nueva', 'is_active' => true]);

        $log = AuditLog::where('auditable_type', Region::class)
            ->where('auditable_id', $region->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log, 'create() debe escribir audit log con event=created');
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('regions', $log->module);
        $this->assertEquals('Nueva', $log->new_values['name']);
    }

    public function test_update_writes_audit_log_with_changed_fields(): void
    {
        $this->actingAsSuperAdmin();
        $region  = Region::factory()->create(['name' => 'Antes', 'is_active' => true]);
        $service = app(RegionService::class);

        $service->update($region, ['name' => 'Después']);

        $log = AuditLog::where('auditable_type', Region::class)
            ->where('auditable_id', $region->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'update() debe escribir audit log con event=updated');
        $this->assertEquals('Antes',   $log->old_values['name']);
        $this->assertEquals('Después', $log->new_values['name']);
        // is_active no cambió — no debería aparecer en old/new.
        $this->assertArrayNotHasKey('is_active', $log->new_values);
    }

    public function test_soft_delete_writes_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $region  = Region::factory()->create();
        $service = app(RegionService::class);

        $service->delete($region, 'Razón válida');

        $log = AuditLog::where('auditable_type', Region::class)
            ->where('auditable_id', $region->id)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($log, 'delete() (soft) debe escribir audit log con event=deleted');
    }

    public function test_restore_writes_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $region  = Region::factory()->trashed()->create();
        $service = app(RegionService::class);

        $service->restore($region);

        $log = AuditLog::where('auditable_type', Region::class)
            ->where('auditable_id', $region->id)
            ->where('event', 'restored')
            ->first();

        $this->assertNotNull($log, 'restore() debe escribir audit log con event=restored');
    }

    public function test_force_delete_writes_audit_log_before_destroying(): void
    {
        $this->actingAsSuperAdmin();
        $region  = Region::factory()->trashed()->create(['name' => 'Final']);
        $regionId = $region->id;
        $service  = app(RegionService::class);

        $service->forceDelete($region, 'Limpieza definitiva');

        $log = AuditLog::where('auditable_type', Region::class)
            ->where('auditable_id', $regionId)
            ->where('event', 'force_deleted')
            ->first();

        $this->assertNotNull($log, 'forceDelete() debe escribir audit log que sobreviva al delete físico');
        $this->assertEquals('Final', $log->old_values['name']);
        $this->assertEquals('Limpieza definitiva', $log->note);

        // El registro físico desapareció — el audit log no.
        $this->assertDatabaseMissing('regions', ['id' => $regionId]);
    }

    public function test_update_with_no_real_changes_does_not_write_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $region  = Region::factory()->create(['name' => 'Same']);
        $service = app(RegionService::class);

        $before = AuditLog::where('auditable_id', $region->id)->count();

        // Update con los mismos valores — no debería escribir audit.
        $service->update($region, ['name' => 'Same']);

        $after = AuditLog::where('auditable_id', $region->id)->count();
        $this->assertEquals($before, $after, 'Update sin cambios reales no debe escribir audit');
    }
}
