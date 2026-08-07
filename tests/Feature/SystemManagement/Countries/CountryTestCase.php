<?php

namespace Tests\Feature\SystemManagement\Countries;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Base test case para Countries — mismo patrón que RegionTestCase / LanguageTestCase
 * pero con un placeholder country soft-deleted en id=999 (al que apuntan los
 * users de los tests vía country_id), de modo que Country::count() arranca en 0
 * y los tests pueden crear países con ids 1..N sin colisión.
 */
abstract class CountryTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        $this->seedParentRows();
        $this->seedRolesAndPermissions();
    }

    protected function seedParentRows(): void
    {
        // Language real (necesario para locales.language_id FK).
        DB::table('languages')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'Spanish', 'iso_code' => 'es',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);

        // Locale real (necesario para users.locale_id Y countries.default_locale_id FK).
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'code' => 'es_AR', 'name' => 'Español (Argentina)',
            'language_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);

        // Region real (necesaria para countries.region_id FK al crear países en tests).
        DB::table('regions')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'América del Sur',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);

        // Bootstrap country en id=999 — soft-deleted. users.country_id apunta aquí,
        // pero Country::count() y queries normales lo ignoran (SoftDeletes global scope).
        // iso_code 'ZZ' está reservado por ISO 3166 para "user-assigned" — seguro.
        DB::table('countries')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22),
            'region_id' => 1,
            'default_locale_id' => 1,
            'name' => '__bootstrap_country__',
            'iso_code' => 'ZZ',
            'currency' => 'XXX',
            'timezone' => 'UTC',
            'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
            'deleted_description' => 'Bootstrap fixture for tests.',
        ]]);

        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'Test Tenant', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    protected function seedRolesAndPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view', 'show', 'create', 'edit', 'delete', 'export'] as $action) {
            Permission::firstOrCreate(['name' => "countries.{$action}", 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        Role::firstOrCreate(['name' => 'admin',       'guard_name' => 'web'], ['description' => 'Test admin']);
        Role::firstOrCreate(['name' => 'user',        'guard_name' => 'web'], ['description' => 'Test user']);
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 999,
            'locale_id'  => 1,
        ]);
        $user->assignRole('super');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 999,
            'locale_id'  => 1,
        ]);
        $user->assignRole('admin');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsRegularUser(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 999,
            'locale_id'  => 1,
        ]);
        $user->assignRole('user');
        $this->actingAs($user);
        return $user;
    }

    /**
     * Payload mínimo válido para crear/editar un Country. Cada llamada genera
     * iso_code único para no colisionar entre tests serialmente. Permite override.
     *
     * Uso:
     *   $this->post(route(...), $this->validCountryData(['name' => 'Mi país']));
     */
    protected static int $isoCounter = 0;

    protected function validCountryData(array $overrides = []): array
    {
        // Códigos ISO secuenciales AA, AB, …, ZZ — evita colisiones intra-test.
        $n = ++self::$isoCounter;
        $first = chr(ord('A') + intdiv($n - 1, 26) % 26);
        $second = chr(ord('A') + ($n - 1) % 26);
        $iso = $first . $second;

        return array_merge([
            'name'              => 'Test Country ' . $n,
            'iso_code'          => $iso,
            'currency'          => 'USD',
            'timezone'          => 'UTC',
            'region_id'         => 1,
            'default_locale_id' => 1,
        ], $overrides);
    }
}
