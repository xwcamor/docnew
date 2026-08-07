<?php

namespace Tests\Feature\SystemManagement\Locales;

use App\Models\AuditLog;
use App\Models\Locale;
use App\Services\SystemManagement\LocaleService;

/**
 * Cobertura del trait Auditable sobre Locale. Cada mutación (create, update,
 * soft-delete, restore, force-delete) escribe un registro en audit_logs.
 * Previene regresiones silenciosas si alguien rompe el trait.
 */
class LocaleAuditLogTest extends LocaleTestCase
{
    public function test_create_writes_audit_log(): void
    {
        $user    = $this->actingAsSuperAdmin();
        $service = app(LocaleService::class);

        $locale = $service->create($this->validLocaleData(['name' => 'Nueva', 'is_active' => true]));

        $log = AuditLog::where('auditable_type', Locale::class)
            ->where('auditable_id', $locale->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log, 'create() debe escribir audit log con event=created');
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('locales', $log->module);
        $this->assertEquals('Nueva', $log->new_values['name']);
    }

    public function test_update_writes_audit_log_with_changed_fields(): void
    {
        $this->actingAsSuperAdmin();
        $locale  = Locale::factory()->create(['name' => 'Antes', 'is_active' => true]);
        $service = app(LocaleService::class);

        $service->update($locale, ['name' => 'Después']);

        $log = AuditLog::where('auditable_type', Locale::class)
            ->where('auditable_id', $locale->id)
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
        $locale  = Locale::factory()->create();
        $service = app(LocaleService::class);

        $service->delete($locale, 'Razón válida');

        $log = AuditLog::where('auditable_type', Locale::class)
            ->where('auditable_id', $locale->id)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($log, 'delete() (soft) debe escribir audit log con event=deleted');
    }

    public function test_restore_writes_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $locale  = Locale::factory()->trashed()->create();
        $service = app(LocaleService::class);

        $service->restore($locale);

        $log = AuditLog::where('auditable_type', Locale::class)
            ->where('auditable_id', $locale->id)
            ->where('event', 'restored')
            ->first();

        $this->assertNotNull($log, 'restore() debe escribir audit log con event=restored');
    }

    public function test_force_delete_writes_audit_log_before_destroying(): void
    {
        $this->actingAsSuperAdmin();
        $locale  = Locale::factory()->trashed()->create(['name' => 'Final']);
        $localeId = $locale->id;
        $service  = app(LocaleService::class);

        $service->forceDelete($locale, 'Limpieza definitiva');

        $log = AuditLog::where('auditable_type', Locale::class)
            ->where('auditable_id', $localeId)
            ->where('event', 'force_deleted')
            ->first();

        $this->assertNotNull($log, 'forceDelete() debe escribir audit log que sobreviva al delete físico');
        $this->assertEquals('Final', $log->old_values['name']);
        $this->assertEquals('Limpieza definitiva', $log->note);

        // El registro físico desapareció — el audit log no.
        $this->assertDatabaseMissing('locales', ['id' => $localeId]);
    }

    public function test_update_with_no_real_changes_does_not_write_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $locale  = Locale::factory()->create(['name' => 'Same']);
        $service = app(LocaleService::class);

        $before = AuditLog::where('auditable_id', $locale->id)->count();

        // Update con los mismos valores — no debería escribir audit.
        $service->update($locale, ['name' => 'Same']);

        $after = AuditLog::where('auditable_id', $locale->id)->count();
        $this->assertEquals($before, $after, 'Update sin cambios reales no debe escribir audit');
    }
}
