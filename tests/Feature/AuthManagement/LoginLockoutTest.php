<?php

namespace Tests\Feature\AuthManagement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * LoginLockoutTest — valida el rate limiting de intentos fallidos de login.
 *
 * Settings que cablean este comportamiento:
 *   - security.max_login_attempts (default 5)
 *   - security.lockout_minutes    (default 15)
 *
 * La clave de throttle se normaliza por email+IP, por lo que dos atacantes
 * desde IPs distintas no se afectan entre si.
 */
class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        // RateLimiter usa el cache. RefreshDatabase no lo limpia entre tests.
        RateLimiter::clear($this->throttleKey('victim@example.com', '127.0.0.1'));
        RateLimiter::clear($this->throttleKey('VICTIM@example.com', '127.0.0.1'));

        $this->seedMinimalParents();
        $this->seedSecuritySettings(maxAttempts: 5, lockoutMinutes: 15);
    }

    public function test_allows_attempts_up_to_max_then_locks(): void
    {
        $this->createUser('victim@example.com', 'correct-password');

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->from(route('login'))->post(route('login.post'), [
                'email'    => 'victim@example.com',
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(302);
            $response->assertSessionHasErrors('email');
            $this->assertNotEquals(
                __('auth.locked', ['minutes' => 15]),
                (string) session('errors')->first('email'),
                "Attempt #{$i} debe ser fallo normal, no lockout todavía."
            );
        }

        // 6to intento: ya superado el limite, el mensaje cambia a "locked".
        $response = $this->from(route('login'))->post(route('login.post'), [
            'email'    => 'victim@example.com',
            'password' => 'wrong-password',
        ]);
        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
        $this->assertEquals(
            __('auth.locked', ['minutes' => 15]),
            (string) session('errors')->first('email'),
        );
    }

    public function test_locked_account_stays_locked_even_with_correct_password(): void
    {
        $this->createUser('victim@example.com', 'correct-password');

        // 5 intentos fallidos para llegar al limite
        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('login.post'), [
                'email'    => 'victim@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // Aunque mande password correcto, debe rechazar con mensaje de lockout
        $response = $this->from(route('login'))->post(route('login.post'), [
            'email'    => 'victim@example.com',
            'password' => 'correct-password',
        ]);
        $response->assertSessionHasErrors('email');
        $this->assertEquals(
            __('auth.locked', ['minutes' => 15]),
            (string) session('errors')->first('email'),
        );
        $this->assertGuest();
    }

    public function test_successful_login_clears_attempt_counter(): void
    {
        $this->createUser('victim@example.com', 'correct-password');

        // 4 fallos (uno menos del limite)
        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('login.post'), [
                'email'    => 'victim@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // Login correcto debe limpiar el contador
        $this->post(route('login.post'), [
            'email'    => 'victim@example.com',
            'password' => 'correct-password',
        ]);
        $this->assertAuthenticated();

        // Logout y volver a fallar — el contador debe arrancar de cero
        $this->post(route('logout'));

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->from(route('login'))->post(route('login.post'), [
                'email'    => 'victim@example.com',
                'password' => 'wrong-password',
            ]);
            $this->assertNotEquals(
                __('auth.locked', ['minutes' => 15]),
                (string) session('errors')->first('email'),
                "Tras login exitoso, el contador debe arrancar de cero. Falló en intento #{$i}."
            );
        }
    }

    public function test_case_variations_of_email_share_the_same_lock(): void
    {
        $this->createUser('victim@example.com', 'correct-password');

        // 5 fallos con MAYUSCULAS
        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('login.post'), [
                'email'    => 'VICTIM@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // 6to intento con minusculas — debe estar bloqueado tambien
        $response = $this->from(route('login'))->post(route('login.post'), [
            'email'    => 'victim@example.com',
            'password' => 'wrong-password',
        ]);
        $this->assertEquals(
            __('auth.locked', ['minutes' => 15]),
            (string) session('errors')->first('email'),
        );
    }

    public function test_inactive_user_cannot_login_even_with_correct_password(): void
    {
        User::factory()->create([
            'email'      => 'inactive@example.com',
            'password'   => Hash::make('correct-password'),
            'tenant_id'  => null,
            'country_id' => 1,
            'locale_id'  => 1,
            'is_active'  => false,
        ]);

        $response = $this->from(route('login'))->post(route('login.post'), [
            'email'    => 'inactive@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertEquals(
            __('auth.account_inactive'),
            (string) session('errors')->first('email'),
        );
        $this->assertGuest(); // credenciales válidas, pero NO inició sesión
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function throttleKey(string $email, string $ip): string
    {
        return Str::transliterate(Str::lower($email)) . '|' . $ip;
    }

    private function createUser(string $email, string $password): User
    {
        return User::factory()->create([
            'email'       => $email,
            'password'    => Hash::make($password),
            'tenant_id'   => null,
            'country_id'  => 1,
            'locale_id'   => 1,
            'is_active'   => true,
        ]);
    }

    private function seedMinimalParents(): void
    {
        DB::table('languages')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish',
            'iso_code' => 'es', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE',
            'name' => 'Español (PE)', 'language_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('regions')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22), 'name' => '__bootstrap__',
            'is_active' => false, 'deleted_at' => now(),
            'deleted_description' => 'Bootstrap',
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'region_id' => 999,
            'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN',
            'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    private function seedSecuritySettings(int $maxAttempts, int $lockoutMinutes): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'security.max_login_attempts'],
            [
                'slug' => Str::random(22), 'name' => 'Max login attempts',
                'type' => 'int', 'value' => (string) $maxAttempts,
                'group' => 'security', 'description' => 'Test',
                'is_secret' => false, 'is_active' => true, 'created_by' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
        DB::table('settings')->updateOrInsert(
            ['key' => 'security.lockout_minutes'],
            [
                'slug' => Str::random(22), 'name' => 'Lockout minutes',
                'type' => 'int', 'value' => (string) $lockoutMinutes,
                'group' => 'security', 'description' => 'Test',
                'is_secret' => false, 'is_active' => true, 'created_by' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }
}
