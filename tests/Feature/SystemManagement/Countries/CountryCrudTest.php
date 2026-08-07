<?php

namespace Tests\Feature\SystemManagement\Countries;

use App\Models\Country;

/**
 * Smoke tests for the CRUD happy path. Verifies pages render and inserts/updates
 * actually persist. The harder cases (permissions, dedup, soft-delete) live in
 * sibling test files.
 */
class CountryCrudTest extends CountryTestCase
{
    public function test_index_renders_paginated_list(): void
    {
        $this->actingAsSuperAdmin();
        Country::factory()->count(15)->create();

        $response = $this->get(route('system_management.countries.index'));
        $response->assertOk();
    }

    public function test_create_page_renders_for_super(): void
    {
        $this->actingAsSuperAdmin();
        $response = $this->get(route('system_management.countries.create'));
        $response->assertOk();
    }

    public function test_store_persists_a_new_country(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $response = $this->post(
            route('system_management.countries.store'),
            $this->validCountryData(['name' => 'Oceanía']),
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('countries', [
            'name'       => 'Oceanía',
            'is_active'  => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_rejects_missing_name(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.countries.store'), []);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Country::count());
    }

    public function test_store_rejects_name_over_255_chars(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(
            route('system_management.countries.store'),
            $this->validCountryData(['name' => str_repeat('a', 256)]),
        );

        $response->assertSessionHasErrors('name');
    }

    public function test_show_renders_country_data(): void
    {
        $this->actingAsSuperAdmin();
        $country = Country::factory()->named('Asia')->create();

        $response = $this->get(route('system_management.countries.show', $country->slug));
        $response->assertOk();
    }

    public function test_edit_page_renders(): void
    {
        $this->actingAsSuperAdmin();
        $country = Country::factory()->create();

        $response = $this->get(route('system_management.countries.edit', $country->slug));
        $response->assertOk();
    }

    public function test_update_persists_changes(): void
    {
        $this->actingAsSuperAdmin();
        $country = Country::factory()->named('Antiguo')->create();

        $response = $this->put(
            route('system_management.countries.update', $country->slug),
            $this->validCountryData([
                'name'      => 'Nuevo',
                'iso_code'  => $country->iso_code,  // mantener el ISO actual del registro
                'currency'  => $country->currency,
                'timezone'  => $country->timezone,
                'region_id' => $country->region_id,
                'default_locale_id' => $country->default_locale_id,
                'is_active' => false,
            ]),
        );

        $response->assertRedirect();
        $country->refresh();
        $this->assertSame('Nuevo', $country->name);
        $this->assertFalse($country->is_active);
    }

    public function test_route_uses_slug_not_id_for_show(): void
    {
        $this->actingAsSuperAdmin();
        $country = Country::factory()->create();

        $url = route('system_management.countries.show', $country->slug);
        $this->assertStringContainsString($country->slug, $url);
        $this->assertStringNotContainsString('/' . $country->id, $url);
    }
}
