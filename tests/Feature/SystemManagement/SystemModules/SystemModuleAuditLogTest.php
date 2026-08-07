<?php

namespace Tests\Feature\SystemManagement\SystemModules;

use App\Models\AuditLog;
use App\Models\SystemModule;
use App\Services\SystemManagement\SystemModuleService;

/**
 * Cobertura del trait Auditable sobre SystemModule. Cada mutación (create, update,
 * soft-delete, restore, force-delete) escribe un registro en audit_logs.
 * Previene regresiones silenciosas si alguien rompe el trait.
 */
class SystemModuleAuditLogTest extends SystemModuleTestCase
{
    public function test_create_writes_audit_log(): void
    {
        $user    = $this->actingAsSuperAdmin();
        $service = app(SystemModuleService::class);

        $system_module = $service->create(['name' => 'Nueva', 'is_active' => true]);

        $log = AuditLog::where('auditable_type', SystemModule::class)
            ->where('auditable_id', $system_module->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log, 'create() debe escribir audit log con event=created');
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('system_modules', $log->module);
        $this->assertEquals('Nueva', $log->new_values['name']);
    }

    public function test_update_writes_audit_log_with_changed_fields(): void
    {
        $this->actingAsSuperAdmin();
        // SystemModule.setNameAttribute aplica Str::studly(Str::singular()) →
        // "Patient" se guarda tal cual, "Patients" se singulariza a "Patient".
        $system_module  = SystemModule::factory()->create(['name' => 'Cat', 'is_active' => true]);
        $service = app(SystemModuleService::class);

        $service->update($system_module, ['name' => 'Dog']);

        $log = AuditLog::where('auditable_type', SystemModule::class)
            ->where('auditable_id', $system_module->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'update() debe escribir audit log con event=updated');
        $this->assertEquals('Cat', $log->old_values['name']);
        $this->assertEquals('Dog', $log->new_values['name']);
        // is_active no cambió — no debería aparecer en old/new.
        $this->assertArrayNotHasKey('is_active', $log->new_values);
    }

    public function test_soft_delete_writes_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $system_module  = SystemModule::factory()->create();
        $service = app(SystemModuleService::class);

        $service->delete($system_module, 'Razón válida');

        $log = AuditLog::where('auditable_type', SystemModule::class)
            ->where('auditable_id', $system_module->id)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($log, 'delete() (soft) debe escribir audit log con event=deleted');
    }

    public function test_restore_writes_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $system_module  = SystemModule::factory()->trashed()->create();
        $service = app(SystemModuleService::class);

        $service->restore($system_module);

        $log = AuditLog::where('auditable_type', SystemModule::class)
            ->where('auditable_id', $system_module->id)
            ->where('event', 'restored')
            ->first();

        $this->assertNotNull($log, 'restore() debe escribir audit log con event=restored');
    }

    public function test_force_delete_writes_audit_log_before_destroying(): void
    {
        $this->actingAsSuperAdmin();
        $system_module  = SystemModule::factory()->trashed()->create(['name' => 'Final']);
        $system_moduleId = $system_module->id;
        $service  = app(SystemModuleService::class);

        $service->forceDelete($system_module, 'Limpieza definitiva');

        $log = AuditLog::where('auditable_type', SystemModule::class)
            ->where('auditable_id', $system_moduleId)
            ->where('event', 'force_deleted')
            ->first();

        $this->assertNotNull($log, 'forceDelete() debe escribir audit log que sobreviva al delete físico');
        $this->assertEquals('Final', $log->old_values['name']);
        $this->assertEquals('Limpieza definitiva', $log->note);

        // El registro físico desapareció — el audit log no.
        $this->assertDatabaseMissing('system_modules', ['id' => $system_moduleId]);
    }

    public function test_update_with_no_real_changes_does_not_write_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $system_module  = SystemModule::factory()->create(['name' => 'Same']);
        $service = app(SystemModuleService::class);

        $before = AuditLog::where('auditable_id', $system_module->id)->count();

        // Update con los mismos valores — no debería escribir audit.
        $service->update($system_module, ['name' => 'Same']);

        $after = AuditLog::where('auditable_id', $system_module->id)->count();
        $this->assertEquals($before, $after, 'Update sin cambios reales no debe escribir audit');
    }
}
