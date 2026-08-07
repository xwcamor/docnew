<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aceptación REGISTRADA de Términos/Privacidad (LPDP Ley 29733): versión +
 * timestamp en el usuario, audit log con IP, y re-aceptación al subir la
 * versión vigente (setting legal.terms_version).
 */
class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'ES', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'T1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('settings')->insertOrIgnore([['key' => 'legal.terms_version', 'slug' => Str::random(22), 'name' => 'v', 'type' => 'string', 'value' => '1.0', 'group' => 'app', 'description' => '', 'is_secret' => false, 'is_active' => true, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]]);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    public function test_arco_data_export_and_deletion_request(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
        $admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $admin->assignRole('admin');

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1, 'name' => 'Pepe Datos']);
        $this->actingAs($u);

        // Acepta términos PRIMERO: el export debe serializar la fecha (regresión:
        // sin cast datetime, terms_accepted_at llegaba string y el export daba 500).
        $this->post(route('legal.accept'));
        // Re-autentica con una instancia FRESCA de BD: actingAs reusa el objeto
        // en memoria (con Carbon) y ocultaba el bug — en prod el user se hidrata
        // de BD y sin cast el campo llega string.
        $this->actingAs(User::find($u->id));

        // ACCESO: descarga JSON con sus datos + queda auditada.
        $res = $this->get(route('profile.data_export'))->assertOk();
        $this->assertStringContainsString('datos-personales-', $res->headers->get('content-disposition'));
        $this->assertSame('Pepe Datos', $res->json('profile.name'));
        $this->assertSame('1.0', $res->json('legal.terms_accepted_version'));
        $this->assertNotNull($res->json('legal.terms_accepted_at'));
        $this->assertNotNull(AuditLog::where('event', 'personal_data_exported')->where('user_id', $u->id)->first());

        // CANCELACIÓN: solicitud auditada + notifica al admin del workspace.
        \Illuminate\Support\Facades\Notification::fake();
        $this->post(route('profile.deletion_request'))->assertRedirect();
        $this->assertNotNull(AuditLog::where('event', 'account_deletion_requested')->where('user_id', $u->id)->first());
        \Illuminate\Support\Facades\Notification::assertSentTo($admin, \App\Notifications\AccountDeletionRequested::class);

        // Idempotente: repetir NO duplica audit ni re-notifica; mensaje "ya registrada".
        $this->post(route('profile.deletion_request'))
            ->assertRedirect()
            ->assertSessionHas('success', __('profile.deletion_already_requested'));
        $this->assertSame(1, AuditLog::where('event', 'account_deletion_requested')->where('user_id', $u->id)->count());
        \Illuminate\Support\Facades\Notification::assertSentToTimes($admin, \App\Notifications\AccountDeletionRequested::class, 1);
    }

    /**
     * Regresión (caso "joe"): si el solicitante es el ÚNICO admin del
     * workspace, la notificación cae al fallback de supers — y HideSuperScope
     * los ocultaba (lista vacía → nadie notificado). Sin el fix, este test falla.
     */
    public function test_deletion_request_by_only_admin_notifies_super(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 's']);
        \App\Scopes\HideSuperScope::flushCache();

        $super = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $super->assignRole('super');

        // Único admin del workspace pide su propia eliminación.
        $admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        // SIN fake: asegura que la notificación queda en la tabla `notifications`
        // (lo que la campana del super realmente lee).
        $this->post(route('profile.deletion_request'))->assertRedirect();
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id'   => $super->id,
            'type'            => \App\Notifications\AccountDeletionRequested::class,
        ]);
    }

    public function test_acceptance_is_recorded_and_versioned(): void
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($u);

        // Sin aceptar → el front recibe legal.accepted=false.
        $this->assertNull($u->terms_accepted_version);

        // Acepta → versión + timestamp + audit con IP.
        $this->post(route('legal.accept'))->assertRedirect();
        $u->refresh();
        $this->assertSame('1.0', $u->terms_accepted_version);
        $this->assertNotNull($u->terms_accepted_at);

        $log = AuditLog::where('event', 'terms_accepted')->where('user_id', $u->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('1.0', $log->new_values['version'] ?? null);
        $this->assertNotNull($log->ip_address);

        // El super sube la versión (vía modelo, como el módulo Settings —
        // dispara flushCache) → debe re-aceptar.
        \App\Models\Setting::where('key', 'legal.terms_version')->first()->update(['value' => '1.1']);
        $this->post(route('legal.accept'))->assertRedirect();
        $this->assertSame('1.1', $u->refresh()->terms_accepted_version);
        $this->assertSame(2, AuditLog::where('event', 'terms_accepted')->where('user_id', $u->id)->count());
    }
}
