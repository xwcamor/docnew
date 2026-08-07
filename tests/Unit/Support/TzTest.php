<?php

namespace Tests\Unit\Support;

use App\Models\Country;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests del helper centralizado de timezone.
 *
 * Tz::for($user) resuelve el TZ efectivo siguiendo la jerarquía:
 *   1. user.timezone (override propio)
 *   2. user.tenant.timezone
 *   3. user.country.timezone
 *   4. config('app.timezone') ó UTC
 *
 * Tz::format() convierte fechas UTC a ese TZ con formato dd-mm-Y H:i.
 */
class TzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Tz::forget();

        // Bootstrap mínimo: language, locale, region (placeholder), country
        // y tenant base. UserFactory necesita country_id y locale_id NOT NULL.
        DB::table('languages')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'Spanish', 'iso_code' => 'es',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'code' => 'es_AR', 'name' => 'Español (Argentina)',
            'language_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('regions')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22),
            'name' => '__bootstrap_region__',
            'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
            'deleted_description' => 'Bootstrap fixture.',
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'region_id' => 999, 'name' => 'Argentina',
            'iso_code' => 'AR', 'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'default_locale_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    /** Devuelve un User con FKs mínimos. */
    protected function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'country_id' => 1,
            'locale_id'  => 1,
        ], $attrs));
    }

    public function test_resolves_user_timezone_first(): void
    {
        $tenant = Tenant::factory()->create(['timezone' => 'UTC']);
        $user   = $this->makeUser([
            'tenant_id' => $tenant->id,
            'timezone'  => 'America/Lima',
        ]);

        // user.timezone gana sobre tenant.timezone.
        $this->assertSame('America/Lima', Tz::for($user));
    }

    public function test_falls_back_to_tenant_timezone(): void
    {
        $tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        $user   = $this->makeUser([
            'tenant_id' => $tenant->id,
            'timezone'  => null,
        ]);

        $this->assertSame('Europe/Madrid', Tz::for($user));
    }

    public function test_falls_back_to_country_timezone(): void
    {
        // Caso: user sin tenant (super) o tenant sin TZ — cae al país.
        $country = Country::factory()->create(['timezone' => 'Asia/Tokyo']);
        $user    = $this->makeUser([
            'tenant_id'  => null,
            'country_id' => $country->id,
            'timezone'   => null,
        ]);

        $this->assertSame('Asia/Tokyo', Tz::for($user));
    }

    public function test_falls_back_to_app_default(): void
    {
        // Sin tenant, sin country, sin override → config('app.timezone').
        // El user en la práctica siempre tiene country_id (FK NOT NULL en
        // este proyecto), pero la jerarquía igualmente termina en config()
        // si el lookup no rinde valor. Forzamos eso seteando explícitamente
        // los tres niveles previos como vacíos: borramos el user del FK.
        $user = $this->makeUser(['tenant_id' => null, 'timezone' => null]);
        // Simulamos "sin country" anulando el FK directamente desde DB,
        // saltando el constraint si el schema lo permite. Como countries.id
        // es NOT NULL en el schema actual de users, usamos en su lugar
        // un país recién creado con TZ vacío (string) — Tz lo trata como
        // vacío via `empty()`.
        DB::table('countries')->updateOrInsert(
            ['id' => 998],
            [
                'slug' => Str::random(22),
                'region_id' => 999, 'name' => 'NoTzCountry',
                'iso_code' => 'XX', 'currency' => 'XXX',
                'timezone' => '', // string vacío — Tz::resolveFor lo descarta con empty()
                'default_locale_id' => 1, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
        $user->country_id = 998;
        $user->save();

        $this->assertSame(config('app.timezone', 'UTC'), Tz::for($user));
    }

    public function test_format_converts_to_user_tz(): void
    {
        // 11:00 UTC en Lima (UTC-5) = 06:00.
        $tenant = Tenant::factory()->create(['timezone' => 'UTC']);
        $user   = $this->makeUser([
            'tenant_id' => $tenant->id,
            'timezone'  => 'America/Lima',
        ]);

        $formatted = Tz::format('2026-05-15 11:00:00', $user);
        $this->assertSame('15-05-2026 06:00', $formatted);
    }

    public function test_format_returns_null_for_null(): void
    {
        $user = $this->makeUser(['timezone' => 'UTC']);

        $this->assertNull(Tz::format(null, $user));
        $this->assertNull(Tz::format('', $user));
    }

    public function test_format_handles_string_iso(): void
    {
        // ISO 8601 con 'Z' debe ser tratado como UTC y convertido al TZ del user.
        $tenant = Tenant::factory()->create(['timezone' => 'UTC']);
        $user   = $this->makeUser([
            'tenant_id' => $tenant->id,
            'timezone'  => 'America/Lima',
        ]);

        $formatted = Tz::format('2026-05-15T11:00:00Z', $user);
        $this->assertSame('15-05-2026 06:00', $formatted);
    }
}
