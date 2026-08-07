<?php

namespace Tests\Feature\Sharing;

use App\Mail\ShareInviteMail;
use App\Mail\ShareOtpMail;
use App\Models\Customer;
use App\Models\OilType;
use App\Models\ReportShare;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShareReportTest extends TestCase
{
    use RefreshDatabase;

    protected Transformer $transformer;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['report_sharing' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['transformers.view', 'customers.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $admin->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);

        $this->customer = (new Customer())->forceFill([
            'slug' => 'c-' . uniqid(), 'name' => 'Cliente X', 'tenant_id' => 1, 'created_by' => $u->id,
        ]);
        $this->customer->save();

        $this->transformer = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S1', 'tag' => 'TR', 'customer_id' => $this->customer->id,
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => 1, 'created_by' => $u->id,
        ]);
        $this->transformer->save();
    }

    public function test_create_share_sends_invite(): void
    {
        Mail::fake();

        // Payload idéntico al del modal: manda el id que NO aplica como null.
        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'customer_id' => null, 'transformer_ids' => null,
            'recipient_email' => 'cliente@x.com', 'expiration_days' => '30', 'locale' => 'es',
        ])->assertStatus(201)->assertJsonPath('share.recipient_email', 'cliente@x.com');

        $share = ReportShare::firstOrFail();
        $this->assertSame('transformer', $share->scope_type);
        $this->assertSame($this->transformer->id, $share->transformer_id);
        Mail::assertQueued(ShareInviteMail::class);
    }

    /**
     * Enlace SIN destinatario: no se manda invitación y el portal abre con el
     * token solo (no hay correo al que mandar el código).
     */
    public function test_link_only_share_needs_no_code(): void
    {
        Mail::fake();

        $res = $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'customer_id' => null, 'transformer_ids' => null,
            'recipient_email' => null, 'expiration_days' => '30', 'locale' => 'es',
        ])->assertStatus(201)->assertJsonPath('share.link_only', true);

        Mail::assertNothingQueued();

        $share = ReportShare::firstOrFail();
        $this->assertNull($share->recipient_email);
        $this->assertTrue($share->isLinkOnly());

        // El gate no pide código: redirige derecho al portal.
        $this->get(route('share.gate', $share->token))
            ->assertRedirect(route('share.view', $share->token));
        $this->get(route('share.view', $share->token))->assertOk();

        // Y sigue venciendo como cualquier otro.
        $share->update(['expires_at' => now()->subDay()]);
        $this->get(route('share.gate', $share->token))->assertStatus(410);
    }

    /** Sin destinatario no hay a quién reenviarle: el endpoint lo corta. */
    public function test_link_only_share_cannot_be_resent(): void
    {
        Mail::fake();

        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'customer_id' => null, 'transformer_ids' => null,
            'recipient_email' => null, 'expiration_days' => '30', 'locale' => 'es',
        ])->assertStatus(201);

        $share = ReportShare::firstOrFail();
        $this->postJson(route('business_management.report_shares.resend', $share->id))->assertStatus(422);
        Mail::assertNothingQueued();
    }

    /** Revocar NO borra: la fila queda con su historial y se audita. */
    public function test_revoke_keeps_the_row_and_audits(): void
    {
        Mail::fake();

        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'customer_id' => null, 'transformer_ids' => null,
            'recipient_email' => 'cliente@x.com', 'expiration_days' => '30', 'locale' => 'es',
        ])->assertStatus(201);

        $share = ReportShare::firstOrFail();
        $this->deleteJson(route('business_management.report_shares.revoke', $share->id))->assertOk();

        $share->refresh();
        $this->assertNotNull($share->revoked_at, 'la fila debe seguir existiendo, revocada');
        $this->assertFalse($share->isActive());
        $this->assertDatabaseHas('audit_logs', [
            'event'        => 'report_share_revoked',
            'auditable_id' => $this->transformer->id,
        ]);
    }

    /**
     * El listado no manda las opciones del Select salvo que se pidan, pero el
     * TOTAL de la flota va siempre: el front lo necesita para saber si la
     * selección abarca todo. Sin él creería que sí y compartiría de más.
     */
    public function test_index_omits_options_but_always_sends_fleet_count(): void
    {
        $sin = $this->getJson(route('business_management.report_shares.index', [
            'scope_type' => 'fleet', 'scope_id' => $this->customer->id,
        ]))->assertOk();

        $sin->assertJsonPath('transformers', [])
            ->assertJsonPath('fleet_count', 1);

        $this->getJson(route('business_management.report_shares.index', [
            'scope_type' => 'fleet', 'scope_id' => $this->customer->id, 'with_transformers' => 1,
        ]))->assertOk()->assertJsonCount(1, 'transformers');
    }

    /** Los revocados solo salen si se piden, y con su fecha. */
    public function test_revoked_shares_are_listed_only_on_demand(): void
    {
        Mail::fake();

        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'customer_id' => null, 'transformer_ids' => null,
            'recipient_email' => 'cliente@x.com', 'expiration_days' => '30', 'locale' => 'es',
        ])->assertStatus(201);

        $share = ReportShare::firstOrFail();
        $this->deleteJson(route('business_management.report_shares.revoke', $share->id))->assertOk();

        $this->getJson(route('business_management.report_shares.index', [
            'scope_type' => 'transformer', 'scope_id' => $this->transformer->id,
        ]))->assertOk()->assertJsonCount(0, 'shares');

        $this->getJson(route('business_management.report_shares.index', [
            'scope_type' => 'transformer', 'scope_id' => $this->transformer->id, 'with_revoked' => 1,
        ]))->assertOk()->assertJsonCount(1, 'shares')
            ->assertJsonPath('shares.0.revoked_at', fn ($v) => $v !== null);
    }

    /** La nota es para el registro propio y viaja en el listado. */
    public function test_note_is_stored_and_returned(): void
    {
        Mail::fake();

        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'customer_id' => null, 'transformer_ids' => null, 'recipient_email' => null,
            'note' => 'Se lo pasé al jefe por WhatsApp',
            'expiration_days' => '1', 'locale' => 'es',
        ])->assertStatus(201)->assertJsonPath('share.note', 'Se lo pasé al jefe por WhatsApp');

        // 24 h: vencimiento corto, pensado para el enlace sin destinatario.
        $share = ReportShare::firstOrFail();
        $this->assertTrue($share->expires_at->lessThan(now()->addDays(2)));
    }

    /**
     * "Envíos de informes": historial cruzando clientes. Lista, filtra por
     * estado y busca (el LIKE tiene que correr también en SQLite, no solo en
     * el Postgres de producción).
     */
    public function test_share_log_lists_filters_and_searches(): void
    {
        Mail::fake();

        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'customer_id' => null, 'transformer_ids' => null,
            'recipient_email' => 'gerencia@cliente.com', 'note' => 'informe mensual',
            'expiration_days' => '30', 'locale' => 'es',
        ])->assertStatus(201);

        $this->get(route('business_management.report_shares_log.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('ReportShares/Index')
                ->where('rows.total', 1)
                ->where('rows.data.0.recipient_email', 'gerencia@cliente.com')
                ->where('rows.data.0.estado', 'active'));

        // Busca por la NOTA (es lo único que distingue a un enlace sin destinatario).
        $this->get(route('business_management.report_shares_log.index', ['q' => 'mensual']))
            ->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 1));

        $this->get(route('business_management.report_shares_log.index', ['q' => 'no-existe']))
            ->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 0));

        // Revocado desde el historial: no borra y sale del filtro "activos".
        $share = ReportShare::firstOrFail();
        $this->delete(route('business_management.report_shares_log.revoke', $share->id))
            ->assertRedirect();

        $this->assertNotNull($share->fresh()->revoked_at);
        $this->get(route('business_management.report_shares_log.index', ['estado' => 'active']))
            ->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 0));
        $this->get(route('business_management.report_shares_log.index', ['estado' => 'revoked']))
            ->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 1));
    }

    /**
     * Aislamiento multi-tenant de "Envíos de informes": el admin de un
     * workspace NO ve los enlaces de otro. ReportShare no usa BelongsToTenant
     * (el portal es público), así que el filtro es a mano y hay que probarlo.
     */
    public function test_share_log_is_scoped_to_the_tenant(): void
    {
        Mail::fake();

        // Un enlace del workspace propio (tenant 1).
        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'customer_id' => null, 'transformer_ids' => null,
            'recipient_email' => 'propio@x.com', 'expiration_days' => '30', 'locale' => 'es',
        ])->assertStatus(201);

        // Y uno de OTRO workspace, insertado directo (no debe verse nunca).
        DB::table('tenants')->insertOrIgnore([[
            'id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        $ajeno = (new Customer())->forceFill([
            'slug' => Str::random(22), 'tenant_id' => 2, 'name' => 'Cliente ajeno',
            'country_id' => 1, 'is_active' => true,
        ]);
        $ajeno->saveQuietly();
        ReportShare::create([
            'scope_type' => 'fleet', 'customer_id' => $ajeno->id, 'tenant_id' => 2,
            'recipient_email' => 'ajeno@otra-empresa.com', 'locale' => 'es',
            'expires_at' => now()->addDays(30), 'created_by' => 1,
        ]);

        $this->assertSame(2, ReportShare::count(), 'los dos enlaces existen en la BD');

        // El admin del tenant 1 solo ve el suyo.
        $this->get(route('business_management.report_shares_log.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('rows.total', 1)
                ->where('rows.data.0.recipient_email', 'propio@x.com'));

        // Ni siquiera buscándolo por su correo.
        $this->get(route('business_management.report_shares_log.index', ['q' => 'ajeno']))
            ->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 0));
    }

    /**
     * Copia en Word: el MISMO informe que el PDF pero, a diferencia de él, sin
     * código de informe ni imagen de firma — solo la línea para firmar a mano.
     * Es lo que impide que un archivo editable se haga pasar por el verificable.
     */
    public function test_word_draft_has_no_report_code_nor_signature_image(): void
    {
        $svc = app(\App\Services\Reports\TransformerReportService::class);
        $user = auth()->user();

        // 1) Con un firmante SIN firma cargada.
        \App\Models\ReportSigner::create([
            'tenant_id' => 1, 'relation' => 'approved',
            'title' => 'Jefe de Laboratorio', 'user_id' => $user->id, 'sort_order' => 1,
        ]);
        [$xml, $imagenesSinFirma] = $this->leerDocx($svc->word($this->transformer, [], $user));

        // El firmante sale, con la línea en blanco para firmar a mano.
        $this->assertStringContainsString('Jefe de Laboratorio', $xml);
        $this->assertStringContainsString('______', $xml);
        // Es el mismo informe: lleva las secciones numeradas del PDF.
        $this->assertStringContainsString('1. ', $xml);
        // El código de informe del PDF (INF-…) nunca aparece.
        $this->assertStringNotContainsString('INF-', $xml);

        // 2) Ahora el firmante SÍ tiene firma y auto-firma activada: en el PDF
        //    se estamparía. En el borrador, la cantidad de imágenes NO cambia.
        $user->forceFill(['signature' => 'firmas/demo.png', 'auto_sign_reports' => true])->save();
        [, $imagenesConFirma] = $this->leerDocx($svc->word($this->transformer->fresh(), [], $user->fresh()));

        $this->assertSame(
            $imagenesSinFirma,
            $imagenesConFirma,
            'la firma NO debe embeberse en el .docx: solo el anillo del índice y los gráficos',
        );

        // Auditado con su propio evento, no como informe verificable emitido.
        $this->assertDatabaseHas('audit_logs', ['event' => 'report_draft_generated', 'auditable_id' => $this->transformer->id]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'report_generated', 'auditable_id' => $this->transformer->id]);
    }

    /** @return array{0:string,1:int} [xml del documento, cantidad de imágenes] */
    private function leerDocx(string $ruta): array
    {
        $this->assertFileExists($ruta);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($ruta) === true, 'el .docx debe ser un zip válido (Word2007)');

        $xml = (string) $zip->getFromName('word/document.xml');
        $imagenes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'word/media/')) {
                $imagenes++;
            }
        }
        $zip->close();
        @unlink($ruta);

        return [$xml, $imagenes];
    }

    public function test_index_list_resend_extend_revoke(): void
    {
        Mail::fake();
        $share = $this->makeShare(['expires_at' => now()->addDays(5)]);

        // Listado por alcance.
        $this->getJson(route('business_management.report_shares.index', [
            'scope_type' => 'transformer', 'scope_id' => $this->transformer->id,
        ]))->assertOk()->assertJsonPath('shares.0.id', $share->id);

        // Reenviar → mail.
        $this->postJson(route('business_management.report_shares.resend', $share->id))->assertOk();
        Mail::assertQueued(ShareInviteMail::class);

        // Extender 90 días.
        $this->postJson(route('business_management.report_shares.extend', $share->id), ['days' => '90'])
            ->assertOk();
        $this->assertTrue($share->fresh()->expires_at->gt(now()->addDays(80)));

        // Revocar.
        $this->deleteJson(route('business_management.report_shares.revoke', $share->id))->assertOk();
        $this->assertNotNull($share->fresh()->revoked_at);

        // Revocado → ya no aparece en el listado.
        $this->getJson(route('business_management.report_shares.index', [
            'scope_type' => 'transformer', 'scope_id' => $this->transformer->id,
        ]))->assertOk()->assertJsonCount(0, 'shares');
    }

    public function test_otp_flow_grants_access_to_portal(): void
    {
        Mail::fake();
        $share = $this->makeShare();
        auth()->logout(); // el portal público lo usa un invitado (cliente externo)

        // Gate visible, sin sesión.
        $this->get(route('share.gate', $share->token))->assertOk()->assertSee('code', false);

        // Pedir código → mail con OTP.
        $code = null;
        $this->post(route('share.code', $share->token))->assertRedirect();
        Mail::assertSent(ShareOtpMail::class, function ($m) use (&$code) { $code = $m->code; return true; });
        $this->assertNotNull($code);

        // Código incorrecto → error.
        $this->post(route('share.verify', $share->token), ['code' => '000000'])->assertSessionHasErrors('code');

        // Código correcto → acceso y portal OK.
        $this->post(route('share.verify', $share->token), ['code' => $code])
            ->assertRedirect(route('share.view', $share->token));
        $this->get(route('share.view', $share->token))->assertOk()->assertSee($this->transformer->serial);
    }

    public function test_otp_throttle_is_per_share_and_friendly(): void
    {
        Mail::fake();
        $share = $this->makeShare();
        auth()->logout();

        // 3 envíos OK; el 4º NO es un 429 pelado: vuelve al gate con mensaje.
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('share.code', $share->token))->assertRedirect();
        }
        $this->post(route('share.code', $share->token))
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        // Otro share desde la misma IP NO queda bloqueado (clave por share+IP).
        $other = $this->makeShare(['recipient_email' => 'otro@x.com']);
        $this->post(route('share.code', $other->token))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('code');
    }

    public function test_expired_share_is_blocked(): void
    {
        $share = $this->makeShare(['expires_at' => now()->subDay()]);
        auth()->logout();
        $this->get(route('share.gate', $share->token))->assertStatus(410);
    }

    public function test_pdf_requires_access_and_scope(): void
    {
        $share = $this->makeShare();
        auth()->logout();

        // Sin sesión OTP → 403.
        $this->get(route('share.pdf', ['token' => $share->token, 'transformer' => $this->transformer->id]))
            ->assertStatus(403);

        // Con acceso, un trafo fuera del alcance → 404.
        session(["share_access.{$share->id}" => now()->addHour()->timestamp]);
        $other = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'OTHER', 'tag' => 'O', 'tenant_id' => 1, 'created_by' => 1,
        ]);
        $other->save();
        $this->get(route('share.pdf', ['token' => $share->token, 'transformer' => $other->id]))
            ->assertStatus(404);

        // El trafo del alcance → INFORME COMPLETO (mismo PDF oficial de la app),
        // auditado como emisión desde el portal → verificable en /verify.
        $res = $this->get(route('share.pdf', ['token' => $share->token, 'transformer' => $this->transformer->id]));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));

        $log = \App\Models\AuditLog::where('event', 'report_generated')
            ->where('auditable_id', $this->transformer->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('share_portal', $log->new_values['source'] ?? null);
        $this->assertSame($share->id, $log->new_values['share_id'] ?? null);
        $this->assertNull($log->user_id); // lo descargó el cliente externo, no un usuario
    }

    public function test_fleet_partial_scope(): void
    {
        // Segundo trafo del mismo cliente.
        $t2 = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S2', 'tag' => 'TR2', 'customer_id' => $this->customer->id,
            'tenant_id' => 1, 'created_by' => 1,
        ]);
        $t2->save();

        // Compartir SOLO el primer trafo de la flota. transformer_id=null como el modal.
        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'fleet', 'customer_id' => $this->customer->id, 'transformer_id' => null,
            'transformer_ids' => [$this->transformer->id],
            'recipient_email' => 'c@x.com', 'expiration_days' => '30', 'locale' => 'es',
        ])->assertStatus(201);

        $share = ReportShare::firstOrFail();
        $this->assertSame([$this->transformer->id], $share->transformer_ids);

        $svc = app(\App\Services\Sharing\ReportShareService::class);
        $this->assertCount(1, $svc->transformersInScope($share));
        $this->assertNotNull($svc->transformerInScope($share, $this->transformer->id));
        $this->assertNull($svc->transformerInScope($share, $t2->id)); // excluido
    }

    public function test_single_portal_shows_full_detail(): void
    {
        $this->seed(\Database\Seeders\CromasRulesSeeder::class);
        $this->seed(\Database\Seeders\FiquiRulesSeeder::class);
        $this->transformer->update(['voltage_kv' => 138]); // clase de tensión para fiquis

        // Muestra de cromas con H2 sobre el típico → tabla con valor y celda .over.
        (new \App\Models\Chromatographical())->forceFill([
            'transformer_id' => $this->transformer->id, 'tenant_id' => 1, 'created_by' => 1,
            'sample_date' => '2024-05-01',
            'h2' => 250, 'ch4' => 10, 'c2h4' => 10, 'c2h6' => 10, 'co' => 100, 'co2' => 1000, 'c2h2' => 0,
        ])->save();
        // Muestra de fisicoquímico con valores válidos → debe diagnosticar (no "Sin datos").
        (new \App\Models\Fiqui())->forceFill([
            'transformer_id' => $this->transformer->id, 'tenant_id' => 1, 'created_by' => 1,
            'sample_date' => '2024-05-01', 'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 12, 'pot' => 0.1,
        ])->save();

        $share = $this->makeShare();
        auth()->logout();
        session(["share_access.{$share->id}" => now()->addHour()->timestamp]);

        $this->get(route('share.view', $share->token))->assertOk()
            ->assertSee(__('transformers.report.dga'))          // sección de gases
            ->assertSee('250')                                  // el valor medido
            ->assertSee(__('transformers.report.oil_quality'))  // sección fisicoquímico
            ->assertSee(__('transformers.report.conclusions')); // conclusiones

        // Regresión (oilType->code envenenado por summary): el fiquis del portal
        // DEBE diagnosticar — antes salía "Sin datos" aunque tuviera muestras.
        $svc = app(\App\Services\Sharing\ReportShareService::class);
        $t = $svc->transformerInScope($share->fresh(), $this->transformer->id);
        $fiquis = collect($svc->summary($t)['components'])->firstWhere('code', 'fiquis');
        $this->assertTrue($fiquis['present'], 'El fisicoquímico debe contar como presente en el resumen del portal.');
        $this->assertNotNull($fiquis['condition']);
        $detail = $svc->detail($t);
        $this->assertNotNull($detail['fiquis'][0]['condition'] ?? null);
        $this->assertNotEmpty($detail['fiquisLimits']);
    }

    public function test_fleet_drilldown_respects_scope(): void
    {
        $t2 = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S2-FUERA', 'tag' => 'TR2', 'customer_id' => $this->customer->id,
            'tenant_id' => 1, 'created_by' => 1,
        ]);
        $t2->save();

        // Flota limitada SOLO al primer trafo.
        $share = $this->makeShare([
            'scope_type' => 'fleet', 'transformer_id' => null,
            'customer_id' => $this->customer->id, 'transformer_ids' => [$this->transformer->id],
        ]);
        auth()->logout();

        // Sin sesión OTP → al gate.
        $this->get(route('share.transformer', ['token' => $share->token, 'transformer' => $this->transformer->id]))
            ->assertRedirect(route('share.gate', $share->token));

        session(["share_access.{$share->id}" => now()->addHour()->timestamp]);

        // La flota muestra KPIs, buscador, filtro por estado y botón de detalle.
        $this->get(route('share.view', $share->token))->assertOk()
            ->assertSee(__('sharing.view_detail'))
            ->assertSee(__('sharing.kpi_avg_hi'))         // KPI
            ->assertSee('fleetSearch', false)             // buscador
            ->assertSee('id="condChips"', false)          // chips de filtro por estado
            ->assertSee(__('sharing.sort_worst_first'));  // orden
        $this->get(route('share.transformer', ['token' => $share->token, 'transformer' => $this->transformer->id]))
            ->assertOk()->assertSee($this->transformer->serial)->assertSee(__('sharing.back_to_fleet'));

        // Trafo fuera del alcance → 404.
        $this->get(route('share.transformer', ['token' => $share->token, 'transformer' => $t2->id]))
            ->assertStatus(404);
    }

    public function test_portal_reuses_real_charts_captured_logged_in(): void
    {
        $this->seed(\Database\Seeders\CromasRulesSeeder::class);
        (new \App\Models\Chromatographical())->forceFill([
            'transformer_id' => $this->transformer->id, 'tenant_id' => 1, 'created_by' => 1,
            'sample_date' => '2024-05-01', 'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50, 'co' => 500, 'co2' => 9000, 'c2h2' => 5,
        ])->save();

        $reports = app(\App\Services\Reports\TransformerReportService::class);
        $cache = app(\App\Services\Reports\ReportChartCache::class);
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        // El usuario genera el informe LOGUEADO con sus gráficos (echarts) → se cachean.
        $this->assertNull($cache->get($this->transformer));
        $reports->pdf($this->transformer, [['group' => 'cromas', 'label' => 'H2', 'dataURL' => $png]], $this->user());

        // El portal (sin gráficos del navegador) reusa EXACTAMENTE esos.
        $cached = $cache->get($this->transformer);
        $this->assertNotNull($cached);
        $this->assertSame('H2', $cached[0]['label']);
        $this->assertSame($png, $cached[0]['dataURL']);

        // Al agregar una muestra nueva, el caché queda viejo y se descarta.
        (new \App\Models\Chromatographical())->forceFill([
            'transformer_id' => $this->transformer->id, 'tenant_id' => 1, 'created_by' => 1,
            'sample_date' => '2024-09-01', 'h2' => 120, 'ch4' => 110, 'c2h4' => 210, 'c2h6' => 55, 'co' => 520, 'co2' => 9200, 'c2h2' => 6,
        ])->save();
        $this->assertNull($cache->get($this->transformer));
    }

    public function test_creating_share_caches_browser_charts(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $this->postJson(route('business_management.report_shares.store'), [
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id, 'customer_id' => null,
            'recipient_email' => 'c@x.com', 'expiration_days' => '30', 'locale' => 'es',
            'images' => [['group' => 'cromas', 'label' => 'C2H2', 'dataURL' => $png]],
        ])->assertStatus(201);

        // El portal ya tiene los gráficos reales cacheados, sin haber generado
        // el informe logueado antes.
        $cached = app(\App\Services\Reports\ReportChartCache::class)->get($this->transformer);
        $this->assertNotNull($cached);
        $this->assertSame('C2H2', $cached[0]['label']);
    }

    private function user(): User
    {
        return User::where('tenant_id', 1)->first();
    }

    private function makeShare(array $overrides = []): ReportShare
    {
        return ReportShare::create(array_merge([
            'scope_type' => 'transformer', 'transformer_id' => $this->transformer->id,
            'tenant_id' => 1, 'recipient_email' => 'cliente@x.com', 'locale' => 'es',
            'expires_at' => now()->addDays(30),
        ], $overrides));
    }
}
