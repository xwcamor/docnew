<?php

namespace Tests\Feature\AuthManagement;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * «Olvidé mi contraseña», de punta a punta.
 *
 * No es un flujo secundario: las 26 cuentas que vinieron del sistema anterior
 * se reconstruyeron con una contraseña aleatoria que no conoce nadie, así que
 * este es el ÚNICO camino de entrada que tienen. Si se rompe, no entra nadie.
 *
 * Se comprueba que las tres pantallas abren, que el correo sale, que el enlace
 * deja poner la contraseña, que la fila de la base queda cambiada de verdad y
 * que la exigencia de la contraseña es la misma que en «Mi perfil».
 */
class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        $this->seedMinimalParents();
    }

    /** Las tres pantallas de acceso abren. La de login es la primera que se ve. */
    public function test_las_pantallas_de_acceso_abren(): void
    {
        $this->get(route('login'))->assertOk();
        $this->get(route('password.request'))->assertOk();
        $this->get(route('password.reset', 'un-token-cualquiera'))->assertOk();
    }

    /** Pedir el enlace manda el correo con el token de verdad. */
    public function test_pedir_el_enlace_envia_el_correo(): void
    {
        Notification::fake();
        $user = $this->createUser('operario@empresa.pe', 'clave-aleatoria-1');

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'operario@empresa.pe'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPasswordNotification::class);

        // El token queda guardado: sin fila aquí el enlace no valdría nada.
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    /**
     * El camino completo: enlace → contraseña nueva → la fila de la base
     * cambia → se puede entrar con ella.
     */
    public function test_el_enlace_deja_poner_la_contrasena_y_entrar_con_ella(): void
    {
        $user = $this->createUser('supervisor@empresa.pe', 'clave-aleatoria-1');
        $anterior = $user->password;

        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', $token))->assertOk();

        $this->from(route('password.reset', $token))
            ->post(route('password.update'), [
                'token'                 => $token,
                'email'                 => 'supervisor@empresa.pe',
                'password'              => 'obra2026segura',
                'password_confirmation' => 'obra2026segura',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();

        // La fila cambió de verdad, no solo la respuesta fue un 302.
        $user->refresh();
        $this->assertNotSame($anterior, $user->password);
        $this->assertTrue(Hash::check('obra2026segura', $user->password));

        // Y el token se consumió: un enlace no sirve dos veces.
        $this->assertDatabaseCount('password_reset_tokens', 0);

        // Con la contraseña nueva se entra.
        $this->post(route('login.post'), [
            'email'    => 'supervisor@empresa.pe',
            'password' => 'obra2026segura',
        ]);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * La exigencia es la MISMA que la de «Mi perfil»: 8 caracteres con letras
     * y números. Antes aquí bastaba `min:8`, así que por el único camino que
     * tienen los usuarios migrados se colaba una contraseña de solo letras
     * mientras la propia pantalla prometía «que lleve letras y números».
     */
    public function test_la_contrasena_nueva_exige_letras_y_numeros(): void
    {
        $user = $this->createUser('tecnico@empresa.pe', 'clave-aleatoria-1');
        $token = Password::broker()->createToken($user);

        $this->from(route('password.reset', $token))
            ->post(route('password.update'), [
                'token'                 => $token,
                'email'                 => 'tecnico@empresa.pe',
                'password'              => 'solosonletras',
                'password_confirmation' => 'solosonletras',
            ])
            ->assertSessionHasErrors('password');

        $this->assertFalse(Hash::check('solosonletras', $user->fresh()->password));

        // Demasiado corta tampoco pasa.
        $this->from(route('password.reset', $token))
            ->post(route('password.update'), [
                'token'                 => $token,
                'email'                 => 'tecnico@empresa.pe',
                'password'              => 'abc123',
                'password_confirmation' => 'abc123',
            ])
            ->assertSessionHasErrors('password');
    }

    /** Si las dos contraseñas no coinciden, lo dice en su campo. */
    public function test_las_dos_contrasenas_tienen_que_coincidir(): void
    {
        $user = $this->createUser('capataz@empresa.pe', 'clave-aleatoria-1');
        $token = Password::broker()->createToken($user);

        $this->from(route('password.reset', $token))
            ->post(route('password.update'), [
                'token'                 => $token,
                'email'                 => 'capataz@empresa.pe',
                'password'              => 'obra2026segura',
                'password_confirmation' => 'otra2026distinta',
            ])
            ->assertSessionHasErrors('password');
    }

    /** Un token inventado no cambia nada y avisa en el campo del correo. */
    public function test_un_enlace_invalido_no_cambia_la_contrasena(): void
    {
        $user = $this->createUser('otro@empresa.pe', 'clave-aleatoria-1');
        $anterior = $user->password;

        $this->from(route('password.reset', 'token-inventado'))
            ->post(route('password.update'), [
                'token'                 => 'token-inventado',
                'email'                 => 'otro@empresa.pe',
                'password'              => 'obra2026segura',
                'password_confirmation' => 'obra2026segura',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame($anterior, $user->fresh()->password);
    }

    /**
     * Restablecer la contraseña rota el `remember_token`: la cookie de
     * «Recuérdame» que había quedado en otro equipo deja de valer. Restablecer
     * una contraseña sin echar esa sesión no protege de nada.
     */
    public function test_restablecer_invalida_las_sesiones_recordadas(): void
    {
        $user = $this->createUser('jefe@empresa.pe', 'clave-aleatoria-1');
        $user->forceFill(['remember_token' => 'token-viejo-de-otro-equipo'])->save();

        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token'                 => $token,
            'email'                 => 'jefe@empresa.pe',
            'password'              => 'obra2026segura',
            'password_confirmation' => 'obra2026segura',
        ])->assertRedirect(route('login'));

        $this->assertNotSame('token-viejo-de-otro-equipo', $user->fresh()->remember_token);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function createUser(string $email, string $password): User
    {
        return User::factory()->create([
            'email'      => $email,
            'password'   => Hash::make($password),
            'tenant_id'  => null,
            'country_id' => 1,
            'locale_id'  => 1,
            'is_active'  => true,
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
}
