<?php

namespace Tests\Feature\Notifications;

use App\Models\Download;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * La bandeja de notificaciones.
 *
 * La campana de la barra superior mezcla dos cosas: los archivos exportados
 * (`downloads`) y los avisos del sistema (tabla `notifications`). Su botón
 * «Ver todas» lleva a esta página, y la página solo pintaba los archivos: el
 * usuario veía un contador en la campana y, al entrar, «No tienes
 * notificaciones». Estas pruebas fijan que las dos fuentes están.
 */
class NotificationsIndexTest extends TestCase
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

    public function test_la_bandeja_abre(): void
    {
        $this->actingAs($this->makeUser());
        $this->get(route('notifications.index'))->assertOk();
    }

    /** Un aviso del sistema aparece en la página, no solo en la campana. */
    public function test_los_avisos_del_sistema_aparecen_en_la_bandeja(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->pushAppNotification($user, 'Contraseña cambiada', 'Se cambió desde «olvidé mi contraseña».');

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 1)
                ->where('notifications.data.0.kind', 'app')
                ->where('notifications.data.0.title', 'Contraseña cambiada')
                ->where('notifications.data.0.status', 'unread'));
    }

    /** Los dos tipos conviven y el más nuevo va primero. */
    public function test_archivos_y_avisos_se_mezclan_ordenados_por_fecha(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->pushAppNotification($user, 'Aviso viejo', 'x', now()->subHours(3));
        $download = Download::create([
            'type' => 'excel', 'filename' => 'personas.xlsx', 'path' => 'exports/personas.xlsx',
            'disk' => 'local', 'user_id' => $user->id, 'status' => 'ready',
            'expires_at' => now()->addDay(),
        ]);
        // `created_at` no es fillable: se fija aparte para ordenar la mezcla.
        $download->forceFill(['created_at' => now()->subHour()])->saveQuietly();
        $this->pushAppNotification($user, 'Aviso nuevo', 'y', now());

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('notifications.data', 3)
                ->where('notifications.data.0.title', 'Aviso nuevo')
                ->where('notifications.data.1.kind', 'download')
                ->where('notifications.data.2.title', 'Aviso viejo'));
    }

    /** Cada uno ve lo suyo: los avisos de otro no se cuelan. */
    public function test_no_se_ven_los_avisos_de_otro_usuario(): void
    {
        $mio  = $this->makeUser('mio@empresa.pe');
        $otro = $this->makeUser('otro@empresa.pe');

        $this->pushAppNotification($otro, 'No es tuyo', 'x');

        $this->actingAs($mio)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notifications.data', 0));
    }

    /** Marcar como leído deja el aviso leído en la base. */
    public function test_marcar_como_leido_cambia_la_fila(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $id = $this->pushAppNotification($user, 'Aviso', 'x');

        $this->post(route('notifications.app.read', $id))->assertRedirect();

        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    /** Quitar un aviso lo borra; la página vuelve a quedar vacía. */
    public function test_quitar_un_aviso_lo_borra(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $id = $this->pushAppNotification($user, 'Aviso', 'x');

        $this->delete(route('notifications.delete', "app-{$id}"))->assertRedirect();

        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    /** Sin sesión no se entra a la bandeja. */
    public function test_sin_sesion_redirige_al_login(): void
    {
        $this->get(route('notifications.index'))->assertRedirect();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeUser(string $email = 'usuario@empresa.pe'): User
    {
        return User::factory()->create([
            'email' => $email, 'tenant_id' => null,
            'country_id' => 1, 'locale_id' => 1, 'is_active' => true,
        ]);
    }

    private function pushAppNotification(User $user, string $title, string $body, $at = null): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id'              => $id,
            'type'            => 'App\\Notifications\\PasswordChangedNotification',
            'notifiable_type' => User::class,
            'notifiable_id'   => $user->id,
            'data'            => json_encode(['category' => 'security', 'title' => $title, 'body' => $body]),
            'read_at'         => null,
            'created_at'      => $at ?? now(),
            'updated_at'      => $at ?? now(),
        ]);

        return $id;
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
