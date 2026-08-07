<?php

namespace Tests\Feature\SystemManagement\Tenants;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\SystemManagement\TenantService;

/**
 * Cobertura del trait Auditable sobre Tenant. Cada mutación (create, update,
 * soft-delete, restore, force-delete) escribe un registro en audit_logs.
 * Previene regresiones silenciosas si alguien rompe el trait.
 */
class TenantAuditLogTest extends TenantTestCase
{
    public function test_create_writes_audit_log(): void
    {
        $user    = $this->actingAsSuperAdmin();
        $service = app(TenantService::class);

        // TenantService::create() exige admin_* — un workspace sin admin es
        // estado inconsistente.
        $tenant = $service->create([
            'name'           => 'Nueva',
            'is_active'      => true,
            'admin_name'     => 'Admin Nueva',
            'admin_email'    => 'admin_nueva@example.com',
            'admin_password' => 'secret123',
        ]);

        $log = AuditLog::where('auditable_type', Tenant::class)
            ->where('auditable_id', $tenant->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log, 'create() debe escribir audit log con event=created');
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('tenants', $log->module);
        $this->assertEquals('Nueva', $log->new_values['name']);
    }

    public function test_update_writes_audit_log_with_changed_fields(): void
    {
        $this->actingAsSuperAdmin();
        $tenant  = Tenant::factory()->create(['name' => 'Antes', 'is_active' => true]);
        $service = app(TenantService::class);

        $service->update($tenant, ['name' => 'Después']);

        $log = AuditLog::where('auditable_type', Tenant::class)
            ->where('auditable_id', $tenant->id)
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
        $tenant  = Tenant::factory()->create();
        $service = app(TenantService::class);

        $service->delete($tenant, 'Razón válida');

        $log = AuditLog::where('auditable_type', Tenant::class)
            ->where('auditable_id', $tenant->id)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($log, 'delete() (soft) debe escribir audit log con event=deleted');
    }

    public function test_restore_writes_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $tenant  = Tenant::factory()->trashed()->create();
        $service = app(TenantService::class);

        $service->restore($tenant);

        $log = AuditLog::where('auditable_type', Tenant::class)
            ->where('auditable_id', $tenant->id)
            ->where('event', 'restored')
            ->first();

        $this->assertNotNull($log, 'restore() debe escribir audit log con event=restored');
    }

    public function test_force_delete_writes_audit_log_before_destroying(): void
    {
        $this->actingAsSuperAdmin();
        $tenant  = Tenant::factory()->trashed()->create(['name' => 'Final']);
        $tenantId = $tenant->id;
        $service  = app(TenantService::class);

        $service->forceDelete($tenant, 'Limpieza definitiva');

        $log = AuditLog::where('auditable_type', Tenant::class)
            ->where('auditable_id', $tenantId)
            ->where('event', 'force_deleted')
            ->first();

        $this->assertNotNull($log, 'forceDelete() debe escribir audit log que sobreviva al delete físico');
        $this->assertEquals('Final', $log->old_values['name']);
        $this->assertEquals('Limpieza definitiva', $log->note);

        // El registro físico desapareció — el audit log no.
        $this->assertDatabaseMissing('tenants', ['id' => $tenantId]);
    }

    public function test_update_with_no_real_changes_does_not_write_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $tenant  = Tenant::factory()->create(['name' => 'Same']);
        $service = app(TenantService::class);

        $before = AuditLog::where('auditable_id', $tenant->id)->count();

        // Update con los mismos valores — no debería escribir audit.
        $service->update($tenant, ['name' => 'Same']);

        $after = AuditLog::where('auditable_id', $tenant->id)->count();
        $this->assertEquals($before, $after, 'Update sin cambios reales no debe escribir audit');
    }
}
