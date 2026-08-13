<?php

namespace Tests\Feature\AuthManagement;

use App\Models\AuditLog;
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
 * Quien entra al sistema queda escrito.
 *
 * El historial guardaba con detalle lo que cada uno CAMBIA y no guardaba nada
 * de las sesiones: ni quien entro, ni desde que IP, ni cuantas veces se fallo
 * la contrasena antes de acertar. Es la primera pregunta de cualquier revision
 * de accesos, y en un sistema donde una cuenta cierra planes de seguridad no es
 * una curiosidad.
 *
 * Lo que se comprueba aqui, y por que cada cosa:
 *
 *   - entrar, salir y fallar dejan fila, con IP y navegador;
 *   - **la contrasena no aparece en ninguna parte** — el evento `Failed` de
 *     Laravel trae las credenciales enteras y con la contrasena en claro
 *     dentro, asi que esto no es paranoia, es el riesgo real de la funcion;
 *   - el freno por intentos se anota y se le cuelga a su dueno;
 *   - una cuenta dada de baja con la contrasena correcta NO figura como que
 *     entro, porque no entro;
 *   - si el super apaga el historial, esto tampoco escribe.
 *
 * @see \App\Listeners\RegistraElAccesoAlSistema
 */
class AuditoriaDeAccesoTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'la-buena-de-verdad';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        // El contador de intentos vive en cache y RefreshDatabase no lo toca.
        RateLimiter::clear($this->llaveDeFreno('marta@example.com'));

        $this->sembrarPadres();
        $this->encenderHistorial(true);
    }

    public function test_entrar_deja_fila_con_ip_y_navegador(): void
    {
        $marta = $this->crearUsuario('marta@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => '200.48.225.130'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (tablet de cuadrilla)'])
            ->post(route('login.post'), [
                'email'    => 'marta@example.com',
                'password' => self::CLAVE,
            ]);

        $this->assertAuthenticated();

        $fila = AuditLog::where('event', 'login')->sole();

        $this->assertSame($marta->id, $fila->user_id);
        $this->assertSame('users', $fila->module);
        $this->assertSame(User::class, $fila->auditable_type);
        $this->assertSame($marta->id, $fila->auditable_id);
        $this->assertSame('200.48.225.130', $fila->ip_address);
        $this->assertStringContainsString('tablet de cuadrilla', (string) $fila->user_agent);
    }

    public function test_salir_deja_su_propia_fila(): void
    {
        $marta = $this->crearUsuario('marta@example.com');

        $this->actingAs($marta)->post(route('logout'));

        $fila = AuditLog::where('event', 'logout')->sole();
        $this->assertSame($marta->id, $fila->user_id);
    }

    public function test_un_intento_fallido_guarda_el_correo_y_jamas_la_contrasena(): void
    {
        $marta = $this->crearUsuario('marta@example.com');

        $this->post(route('login.post'), [
            'email'    => 'marta@example.com',
            'password' => 'Tr3sTristesTigres!',
        ]);

        $this->assertGuest();

        $fila = AuditLog::where('event', 'login_failed')->sole();

        // La fila apunta a la cuenta: el proveedor SI encontro a la persona, lo
        // que fallo fue la contrasena. Es lo que permite ver «a esta cuenta le
        // estan probando claves» sin cruzar nada a mano.
        $this->assertSame($marta->id, $fila->user_id);
        $this->assertSame(['intento' => 'marta@example.com'], $fila->new_values);

        $this->assertNoHayRastroDe('Tr3sTristesTigres!');
    }

    public function test_un_correo_que_no_existe_tambien_se_anota(): void
    {
        $this->post(route('login.post'), [
            'email'    => 'nadie@example.com',
            'password' => 'loQueSea1',
        ]);

        $fila = AuditLog::where('event', 'login_failed')->sole();

        // Sin dueno al que colgarsela, pero el correo probado se guarda: una
        // rafaga de correos inventados desde una IP es exactamente la senal
        // que se quiere poder ver.
        $this->assertNull($fila->user_id);
        $this->assertSame(['intento' => 'nadie@example.com'], $fila->new_values);
    }

    public function test_el_freno_por_intentos_se_anota_y_se_le_cuelga_a_su_dueno(): void
    {
        $marta = $this->crearUsuario('marta@example.com');
        $this->limitarIntentosA(3);

        // Los tres que consume el limite: fallos normales, sin freno todavia.
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('login.post'), [
                'email'    => 'marta@example.com',
                'password' => 'noEsEsta' . $i,
            ]);
        }

        $this->assertSame(0, AuditLog::where('event', 'login_lockout')->count());

        // El cuarto ya no llega a probarse: la cuenta esta frenada.
        $this->post(route('login.post'), [
            'email'    => 'marta@example.com',
            'password' => self::CLAVE,
        ]);

        $fila = AuditLog::where('event', 'login_lockout')->sole();

        // Con dueno a proposito: el historial que ve el admin de un workspace
        // esta acotado a la gente de SU workspace, asi que una fila sin usuario
        // solo la veria el super.
        $this->assertSame($marta->id, $fila->user_id);
        $this->assertSame(['intento' => 'marta@example.com'], $fila->new_values);
    }

    public function test_una_cuenta_dada_de_baja_no_figura_como_que_entro(): void
    {
        $inactiva = $this->crearUsuario('baja@example.com', activa: false);

        $this->post(route('login.post'), [
            'email'    => 'baja@example.com',
            'password' => self::CLAVE,
        ]);

        $this->assertGuest();

        // El controlador deja pasar el intento y echa a la persona en la linea
        // siguiente, asi que Laravel dispara Login y Logout igualmente. Anotar
        // eso como una sesion normal seria falso: lo que hubo fue un rechazo.
        $this->assertSame(0, AuditLog::where('event', 'login')->count());
        $this->assertSame(0, AuditLog::where('event', 'logout')->count());

        $fila = AuditLog::where('event', 'login_blocked')->sole();
        $this->assertSame($inactiva->id, $fila->user_id);
    }

    public function test_con_el_historial_apagado_no_se_escribe_nada(): void
    {
        $this->crearUsuario('marta@example.com');
        $this->encenderHistorial(false);

        $this->post(route('login.post'), [
            'email'    => 'marta@example.com',
            'password' => self::CLAVE,
        ]);
        $this->assertAuthenticated();

        $this->post(route('login.post'), [
            'email'    => 'marta@example.com',
            'password' => 'equivocada',
        ]);

        // Apagarlo tiene que apagarlo entero: seria incoherente que dejara de
        // registrar quien edita un plan y siguiera registrando quien entra.
        $this->assertSame(0, AuditLog::whereIn('event', [
            'login', 'logout', 'login_failed', 'login_lockout', 'login_blocked',
        ])->count());
    }

    // ─── Ayudas ─────────────────────────────────────────────────────────────

    /** Ni en las columnas de texto ni dentro de los JSON de valores. */
    private function assertNoHayRastroDe(string $secreto): void
    {
        foreach (AuditLog::all() as $fila) {
            $this->assertStringNotContainsString(
                $secreto,
                json_encode($fila->getAttributes(), JSON_UNESCAPED_UNICODE),
                "La contraseña acabó guardada en audit_logs #{$fila->id}.",
            );
        }
    }

    private function llaveDeFreno(string $correo, string $ip = '127.0.0.1'): string
    {
        return Str::transliterate(Str::lower($correo)) . '|' . $ip;
    }

    private function crearUsuario(string $correo, bool $activa = true): User
    {
        return User::factory()->create([
            'email'      => $correo,
            'password'   => Hash::make(self::CLAVE),
            'tenant_id'  => null,
            'country_id' => 1,
            'locale_id'  => 1,
            'is_active'  => $activa,
        ]);
    }

    private function encenderHistorial(bool $encendido): void
    {
        $this->ajuste('features.audit_log_enabled', 'bool', $encendido ? 'true' : '', 'features');
    }

    private function limitarIntentosA(int $intentos): void
    {
        $this->ajuste('security.max_login_attempts', 'int', (string) $intentos, 'security');
    }

    private function ajuste(string $clave, string $tipo, string $valor, string $grupo): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $clave],
            [
                'slug' => Str::random(22), 'name' => $clave, 'type' => $tipo,
                'value' => $valor, 'group' => $grupo, 'description' => 'Test',
                'is_secret' => false, 'is_active' => true, 'created_by' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        \App\Models\Setting::flushCache();
    }

    private function sembrarPadres(): void
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
