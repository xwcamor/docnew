<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Mi perfil»: que la pantalla abra y que lo que se escribe se guarde.
 *
 * Es la pantalla transversal que ve todo el mundo y que nadie revisa. Se
 * comprueba la fila de la base, no solo que la respuesta sea un 302.
 */
class ProfileScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español (PE)', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Contratista 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web'], ['description' => 'Usuario de campo']);
    }

    private function makeUser(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
            'password' => Hash::make('actual2026clave'),
        ], $attrs));
        $user->assignRole('user');

        return $user;
    }

    public function test_la_pantalla_de_perfil_abre(): void
    {
        $this->actingAs($this->makeUser());

        $this->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Show')
                ->has('profile.name')
                ->has('profile.email')
                ->where('profile.has_password', true));
    }

    public function test_sin_sesion_el_perfil_redirige_al_login(): void
    {
        $this->get(route('profile.show'))->assertRedirect();
    }

    /** Guardar el nombre y la zona horaria cambia la fila. */
    public function test_guardar_los_datos_cambia_la_fila(): void
    {
        $user = $this->makeUser(['name' => 'Nombre viejo']);
        $this->actingAs($user);

        $this->from(route('profile.show'))
            ->put(route('profile.update'), [
                'name'     => 'Juan Pérez Quispe',
                'timezone' => 'America/Lima',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Juan Pérez Quispe', $user->name);
        $this->assertSame('America/Lima', $user->timezone);
    }

    /** Un nombre vacío se rechaza en su campo y no borra el que había. */
    public function test_el_nombre_es_obligatorio_y_avisa_en_su_campo(): void
    {
        $user = $this->makeUser(['name' => 'Nombre bueno']);
        $this->actingAs($user);

        $this->from(route('profile.show'))
            ->put(route('profile.update'), ['name' => '', 'timezone' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Nombre bueno', $user->fresh()->name);
    }

    /** Una zona horaria que no está en la lista se rechaza. */
    public function test_una_zona_horaria_inventada_se_rechaza(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->from(route('profile.show'))
            ->put(route('profile.update'), ['name' => 'Juan', 'timezone' => 'Marte/Olympus'])
            ->assertSessionHasErrors('timezone');
    }

    /** Vaciar la zona horaria devuelve al usuario a la del workspace. */
    public function test_dejar_la_zona_vacia_vuelve_a_heredar(): void
    {
        $user = $this->makeUser(['timezone' => 'America/Lima']);
        $this->actingAs($user);

        $this->put(route('profile.update'), ['name' => $user->name, 'timezone' => ''])
            ->assertRedirect();

        $this->assertNull($user->fresh()->timezone);
    }

    /** Cambiar la contraseña: pide la actual y guarda la nueva. */
    public function test_cambiar_la_contrasena_guarda_la_nueva(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->from(route('profile.show'))
            ->put(route('profile.update_password'), [
                'current_password'      => 'actual2026clave',
                'password'              => 'nueva2026clave',
                'password_confirmation' => 'nueva2026clave',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nueva2026clave', $user->fresh()->password));
    }

    /** Con la contraseña actual equivocada no cambia nada y lo dice. */
    public function test_la_contrasena_actual_equivocada_se_avisa_en_su_campo(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->from(route('profile.show'))
            ->put(route('profile.update_password'), [
                'current_password'      => 'no-es-esta',
                'password'              => 'nueva2026clave',
                'password_confirmation' => 'nueva2026clave',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('actual2026clave', $user->fresh()->password));
    }

    /**
     * La exigencia de la contraseña es la MISMA que la del flujo de «olvidé
     * mi contraseña»: 8 caracteres, con letras y números. Si las dos pantallas
     * piden cosas distintas, la más floja es la que manda.
     */
    public function test_la_contrasena_nueva_exige_letras_y_numeros(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->from(route('profile.show'))
            ->put(route('profile.update_password'), [
                'current_password'      => 'actual2026clave',
                'password'              => 'solosonletras',
                'password_confirmation' => 'solosonletras',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('actual2026clave', $user->fresh()->password));
    }
}
