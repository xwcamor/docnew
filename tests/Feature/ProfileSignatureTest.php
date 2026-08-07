<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Firma manuscrita del usuario: imagen PRIVADA (disk local), gestionada solo
 * por su dueño desde el perfil. Se estampa en los informes que él genera.
 */
class ProfileSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    public function test_user_uploads_views_and_removes_own_signature(): void
    {
        Storage::fake('local');
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($u);

        // Subir
        $this->post(route('profile.signature.update'), [
            'signature' => UploadedFile::fake()->image('firma.png', 400, 120),
        ])->assertRedirect();

        $u->refresh();
        $this->assertNotNull($u->signature);
        $this->assertStringStartsWith('signatures/' . $u->id . '/', $u->signature);
        Storage::disk('local')->assertExists($u->signature);

        // Ver la propia
        $this->get(route('profile.signature.show'))->assertOk();

        // Quitar
        $old = $u->signature;
        $this->delete(route('profile.signature.remove'))->assertRedirect();
        $u->refresh();
        $this->assertNull($u->signature);
        Storage::disk('local')->assertMissing($old);
    }

    public function test_user_updates_own_profile_photo(): void
    {
        Storage::fake('public');
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($u);

        $this->post(route('profile.photo.update'), [
            'photo' => UploadedFile::fake()->image('yo.jpg', 300, 300),
        ])->assertRedirect();

        $u->refresh();
        $this->assertNotNull($u->photo);
        Storage::disk('public')->assertExists($u->photo);

        // Reemplazo: borra la anterior.
        $old = $u->photo;
        $this->post(route('profile.photo.update'), [
            'photo' => UploadedFile::fake()->image('yo2.png', 300, 300),
        ])->assertRedirect();
        $u->refresh();
        $this->assertNotSame($old, $u->photo);
        Storage::disk('public')->assertMissing($old);

        // No-imagen rechazada.
        $this->post(route('profile.photo.update'), [
            'photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('photo');
    }

    public function test_autosign_consent_requires_signature_and_is_audited(): void
    {
        Storage::fake('local');
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($u);

        // Sin firma cargada no se puede activar.
        $this->put(route('profile.autosign.update'), ['auto_sign_reports' => true])
            ->assertSessionHasErrors('auto_sign_reports');

        // Con firma: activa, queda auditado el consentimiento.
        $this->post(route('profile.signature.update'), [
            'signature' => UploadedFile::fake()->image('firma.png', 400, 120),
        ]);
        $this->put(route('profile.autosign.update'), ['auto_sign_reports' => true])->assertRedirect();
        $this->assertTrue($u->refresh()->auto_sign_reports);
        $this->assertNotNull(\App\Models\AuditLog::where('event', 'report_autosign_granted')->where('user_id', $u->id)->first());

        // Quitar la firma revoca la auto-firma (no puede quedar colgada).
        $this->delete(route('profile.signature.remove'));
        $this->assertFalse($u->refresh()->auto_sign_reports);
    }

    public function test_force_delete_removes_personal_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($u);

        $this->post(route('profile.signature.update'), ['signature' => UploadedFile::fake()->image('f.png', 300, 100)]);
        $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('p.jpg', 200, 200)]);
        $u->refresh();
        $sig = $u->signature; $photo = $u->photo;
        Storage::disk('local')->assertExists($sig);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($photo);

        // Eliminación definitiva (LPDP): los archivos personales se borran.
        // (logout: en la práctica lo ejecuta un admin, no el propio usuario —
        // y el audit del delete no puede referenciar a un user ya borrado)
        auth()->logout();
        $u->forceDelete();
        Storage::disk('local')->assertMissing($sig);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($photo);
    }

    public function test_signature_rejects_non_image_and_requires_auth(): void
    {
        Storage::fake('local');
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        // Sin login → redirect a login.
        $this->post(route('profile.signature.update'), [])->assertRedirect();

        // Archivo no-imagen → error de validación.
        $this->actingAs($u);
        $this->post(route('profile.signature.update'), [
            'signature' => UploadedFile::fake()->create('firma.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('signature');

        // Sin firma cargada no hay vista previa (el handler global convierte
        // el 404 autenticado en redirect al dashboard).
        $this->get(route('profile.signature.show'))->assertRedirect();
    }
}
