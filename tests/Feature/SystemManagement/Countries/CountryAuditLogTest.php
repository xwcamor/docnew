<?php

namespace Tests\Feature\SystemManagement\Countries;

use App\Models\AuditLog;
use App\Models\Country;
use App\Services\SystemManagement\CountryService;

/**
 * Cobertura del trait Auditable sobre Country. Cada mutación (create, update,
 * soft-delete, restore, force-delete) escribe un registro en audit_logs.
 * Previene regresiones silenciosas si alguien rompe el trait.
 */
class CountryAuditLogTest extends CountryTestCase
{
    public function test_create_writes_audit_log(): void
    {
        $user    = $this->actingAsSuperAdmin();
        $service = app(CountryService::class);

        $country = $service->create($this->validCountryData(['name' => 'Nueva', 'is_active' => true]));

        $log = AuditLog::where('auditable_type', Country::class)
            ->where('auditable_id', $country->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log, 'create() debe escribir audit log con event=created');
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('countries', $log->module);
        $this->assertEquals('Nueva', $log->new_values['name']);
    }

    public function test_update_writes_audit_log_with_changed_fields(): void
    {
        $this->actingAsSuperAdmin();
        $country  = Country::factory()->create(['name' => 'Antes', 'is_active' => true]);
        $service = app(CountryService::class);

        $service->update($country, ['name' => 'Después']);

        $log = AuditLog::where('auditable_type', Country::class)
            ->where('auditable_id', $country->id)
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
        $country  = Country::factory()->create();
        $service = app(CountryService::class);

        $service->delete($country, 'Razón válida');

        $log = AuditLog::where('auditable_type', Country::class)
            ->where('auditable_id', $country->id)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($log, 'delete() (soft) debe escribir audit log con event=deleted');
    }

    public function test_restore_writes_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $country  = Country::factory()->trashed()->create();
        $service = app(CountryService::class);

        $service->restore($country);

        $log = AuditLog::where('auditable_type', Country::class)
            ->where('auditable_id', $country->id)
            ->where('event', 'restored')
            ->first();

        $this->assertNotNull($log, 'restore() debe escribir audit log con event=restored');
    }

    public function test_force_delete_writes_audit_log_before_destroying(): void
    {
        $this->actingAsSuperAdmin();
        $country  = Country::factory()->trashed()->create(['name' => 'Final']);
        $countryId = $country->id;
        $service  = app(CountryService::class);

        $service->forceDelete($country, 'Limpieza definitiva');

        $log = AuditLog::where('auditable_type', Country::class)
            ->where('auditable_id', $countryId)
            ->where('event', 'force_deleted')
            ->first();

        $this->assertNotNull($log, 'forceDelete() debe escribir audit log que sobreviva al delete físico');
        $this->assertEquals('Final', $log->old_values['name']);
        $this->assertEquals('Limpieza definitiva', $log->note);

        // El registro físico desapareció — el audit log no.
        $this->assertDatabaseMissing('countries', ['id' => $countryId]);
    }

    public function test_update_with_no_real_changes_does_not_write_audit_log(): void
    {
        $this->actingAsSuperAdmin();
        $country  = Country::factory()->create(['name' => 'Same']);
        $service = app(CountryService::class);

        $before = AuditLog::where('auditable_id', $country->id)->count();

        // Update con los mismos valores — no debería escribir audit.
        $service->update($country, ['name' => 'Same']);

        $after = AuditLog::where('auditable_id', $country->id)->count();
        $this->assertEquals($before, $after, 'Update sin cambios reales no debe escribir audit');
    }
}
