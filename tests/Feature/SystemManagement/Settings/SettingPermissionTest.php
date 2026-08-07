<?php

namespace Tests\Feature\SystemManagement\Settings;

use App\Models\Setting;

/**
 * Verifies route protection for the Settings module.
 *
 * Settings is a master table → super ONLY. Even tenant admins must NOT
 * be able to read, create, edit, delete or export settings. Workers/users
 * must not even reach the index.
 *
 * Note on 403 vs 302: the project's custom exception handler converts
 * AccessDenied/UnauthorizedException to a flash + redirect (so the user lands
 * on a friendly dashboard instead of a raw 403 page). Tests therefore assert
 * `assertRedirect()` plus the absence of side-effects, not `assertForbidden()`.
 */
class SettingPermissionTest extends SettingTestCase
{
    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $response = $this->get(route('system_management.settings.index'));
        $response->assertRedirect();
    }

    public function test_regular_users_are_redirected_away_from_index(): void
    {
        $this->actingAsRegularUser();
        $response = $this->get(route('system_management.settings.index'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_admin_users_are_redirected_away_from_index(): void
    {
        // Admin (tenant master) is NOT super and must NOT see master tables.
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.settings.index'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_super_can_access_index(): void
    {
        $this->actingAsSuperAdmin();
        $response = $this->get(route('system_management.settings.index'));
        $response->assertOk();
    }

    public function test_admin_cannot_create_settings(): void
    {
        $this->actingAsAdmin();
        $response = $this->post(route('system_management.settings.store'), ['name' => 'X']);
        $response->assertRedirect();
        $this->assertSame(0, Setting::count(), 'No setting should have been created.');
    }

    public function test_admin_cannot_export(): void
    {
        $this->actingAsAdmin();
        $response = $this->post(route('system_management.settings.export_excel'), [
            'columns' => ['name'],
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_admin_cannot_import(): void
    {
        $this->actingAsAdmin();
        $response = $this->post(route('system_management.settings.import'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_admin_cannot_access_trash(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.settings.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
