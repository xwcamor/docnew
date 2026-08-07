<?php

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\Region;
use App\Models\User;
use App\Models\UserFavorite;
use App\Services\SystemManagement\RegionService;
use Tests\Feature\SystemManagement\Regions\RegionTestCase;

/**
 * Unit tests del RegionService — la capa que el controller delega. Cubre
 * create/update/delete/restore/forceDelete con efectos colaterales:
 *  - created_by autopopulado
 *  - delete escribe deleted_by + reason + is_active=false
 *  - restore limpia metadata
 *  - forceDelete escribe audit log antes del delete físico
 */
class RegionServiceTest extends RegionTestCase
{
    private RegionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RegionService();
    }

    public function test_create_assigns_authenticated_user_as_creator(): void
    {
        $user = $this->actingAsSuperAdmin();

        $region = $this->service->create(['name' => 'Norte', 'is_active' => true]);

        $this->assertEquals($user->id, $region->created_by);
        $this->assertEquals('Norte', $region->name);
        $this->assertTrue($region->is_active);
    }

    public function test_update_mutates_only_passed_fields(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->create(['name' => 'Old', 'is_active' => true]);

        $updated = $this->service->update($region, ['name' => 'New']);

        $this->assertEquals('New', $updated->name);
        $this->assertTrue($updated->is_active); // sin tocar
    }

    public function test_delete_soft_deletes_and_records_reason_and_user(): void
    {
        $user   = $this->actingAsSuperAdmin();
        $region = Region::factory()->create(['is_active' => true]);

        $this->service->delete($region, 'Ya no se usa');

        $fresh = Region::withTrashed()->find($region->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertEquals('Ya no se usa', $fresh->deleted_description);
        $this->assertEquals($user->id, $fresh->deleted_by);
        $this->assertFalse($fresh->is_active);
    }

    public function test_restore_clears_deletion_metadata(): void
    {
        $user = $this->actingAsSuperAdmin();
        $region = Region::factory()->trashed()->create([
            'deleted_by'          => $user->id,
            'deleted_description' => 'Borrado por error',
        ]);

        $this->service->restore($region);

        $fresh = $region->fresh();
        $this->assertNull($fresh->deleted_at);
        $this->assertNull($fresh->deleted_by);
        $this->assertNull($fresh->deleted_description);
    }

    public function test_force_delete_writes_audit_log_before_destroying(): void
    {
        $user   = $this->actingAsSuperAdmin();
        $region = Region::factory()->trashed()->create([
            'name'      => 'PurgeMe',
            'is_active' => false,
        ]);

        $regionId = $region->id;
        $this->service->forceDelete($region, 'Limpieza definitiva');

        // El registro físico debe haber desaparecido.
        $this->assertDatabaseMissing('regions', ['id' => $regionId]);

        // Pero el audit log queda como prueba.
        $log = AuditLog::where('auditable_type', Region::class)
            ->where('auditable_id', $regionId)
            ->where('event', 'force_deleted')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('Limpieza definitiva', $log->note);
        $this->assertEquals('PurgeMe', $log->old_values['name']);
    }

    public function test_force_delete_cleans_favorites(): void
    {
        $user = $this->actingAsSuperAdmin();
        $region = Region::factory()->trashed()->create();

        UserFavorite::create([
            'user_id'          => $user->id,
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);

        $this->service->forceDelete($region, 'Limpieza');

        $this->assertDatabaseMissing('user_favorites', [
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);
    }
}
