<?php

namespace Tests\Feature\BusinessManagement;

use App\Mail\ShareInviteMail;
use App\Models\ReportApproval;
use App\Models\ReportInstance;
use App\Models\ReportRequest;
use App\Models\ReportShare;
use App\Models\Tenant;
use App\Models\Transformer;
use App\Models\User;
use App\Notifications\ReportApprovalRequested;
use App\Notifications\ReportRequestApproved;
use App\Notifications\ReportRequestRejected;
use App\Services\Reports\ReportApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Flujo de aprobación de informes (etapa 2 de firmas, modelo batch): una
 * solicitud agrupa N informes; los firmantes la aprueban una vez; al emitirse
 * se auto-comparte si llevaba destinatario.
 */
class ReportApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin esto, LaravelLocalization redirige URL-sin-prefijo -> /es/... y
        // todo GET de este test veria un 302 (patron de RegionTestCase).
        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'require_report_approval' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'admin']);
    }

    private function user(): User
    {
        return User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
    }

    private function instanceRow(Transformer $t): array
    {
        return ['transformer' => $t, 'snapshot' => ['serial' => $t->serial], 'report_code' => 'INF-X', 'verify_code' => 'AAAA-BBBB-CCCC'];
    }

    public function test_send_for_approval_creates_request_and_notifies_signers(): void
    {
        Notification::fake();

        $preparer = $this->user();
        $signer   = $this->user();
        $this->actingAs($preparer);

        $tenant = Tenant::find(1);
        $tenant->reportSigners()->create(['user_id' => $signer->id, 'title' => 'Supervisor', 'relation' => 'approved', 'sort_order' => 1]);

        $t = Transformer::factory()->create(['tenant_id' => 1]);

        $request = app(ReportApprovalService::class)->createRequest(
            $tenant, $preparer, 'transformer', [$this->instanceRow($t)], label: $t->serial,
        );

        $this->assertSame('in_review', $request->status);
        $this->assertSame(1, ReportInstance::where('report_request_id', $request->id)->count());
        $this->assertSame(1, ReportApproval::where('report_request_id', $request->id)->where('status', 'pending')->count());

        Notification::assertSentTo($signer, ReportApprovalRequested::class);
    }

    public function test_full_approval_issues_request_and_auto_shares(): void
    {
        Notification::fake();
        Mail::fake();

        $preparer = $this->user();
        $signer   = $this->user();

        $tenant = Tenant::find(1);
        $tenant->reportSigners()->create(['user_id' => $signer->id, 'title' => 'Supervisor', 'relation' => 'approved', 'sort_order' => 1]);

        $t = Transformer::factory()->create(['tenant_id' => 1]);

        $this->actingAs($preparer);
        $request = app(ReportApprovalService::class)->createRequest(
            $tenant, $preparer, 'transformer', [$this->instanceRow($t)],
            label: $t->serial,
            shareParams: ['recipient_email' => 'cliente@empresa.com', 'expiration_days' => 30, 'locale' => 'es'],
        );

        // El firmante aprueba → la solicitud se emite y se auto-comparte.
        $this->actingAs($signer);
        $approval = $request->approvals()->first();
        app(ReportApprovalService::class)->approve($approval, $signer);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertNotNull($request->issued_at);
        $this->assertSame('approved', ReportInstance::where('report_request_id', $request->id)->first()->status);

        $share = ReportShare::where('tenant_id', 1)->where('transformer_id', $t->id)->first();
        $this->assertNotNull($share, 'el auto-compartir debe crear el enlace');
        $this->assertSame('cliente@empresa.com', $share->recipient_email);
        Mail::assertQueued(ShareInviteMail::class);

        // El SOLICITANTE recibe el aviso de que su solicitud fue aprobada/emitida.
        Notification::assertSentTo($preparer, ReportRequestApproved::class);
    }

    public function test_rejection_marks_request_and_instances_rejected(): void
    {
        Notification::fake();

        $preparer = $this->user();
        $signer   = $this->user();

        $tenant = Tenant::find(1);
        $tenant->reportSigners()->create(['user_id' => $signer->id, 'title' => 'Supervisor', 'relation' => 'reviewed', 'sort_order' => 1]);

        $t = Transformer::factory()->create(['tenant_id' => 1]);

        $this->actingAs($preparer);
        $request = app(ReportApprovalService::class)->createRequest($tenant, $preparer, 'transformer', [$this->instanceRow($t)], label: $t->serial);

        $this->actingAs($signer);
        app(ReportApprovalService::class)->reject($request->approvals()->first(), $signer, 'Faltan datos de fiquis');

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('rejected', ReportInstance::where('report_request_id', $request->id)->first()->status);

        // El SOLICITANTE recibe el aviso de rechazo (con motivo).
        Notification::assertSentTo($preparer, ReportRequestRejected::class);
    }

    public function test_resubmit_returns_request_to_review_and_renotifies_signers(): void
    {
        Notification::fake();

        $preparer = $this->user();
        $signer   = $this->user();

        $tenant = Tenant::find(1);
        $tenant->reportSigners()->create(['user_id' => $signer->id, 'title' => 'Supervisor', 'relation' => 'approved', 'sort_order' => 1]);

        $t = Transformer::factory()->create(['tenant_id' => 1]);

        $this->actingAs($preparer);
        $request = app(ReportApprovalService::class)->createRequest($tenant, $preparer, 'transformer', [$this->instanceRow($t)], label: $t->serial);

        // Rechazo y luego el solicitante reenvía.
        $this->actingAs($signer);
        app(ReportApprovalService::class)->reject($request->approvals()->first(), $signer, 'Corregir datos');

        $this->actingAs($preparer);
        app(ReportApprovalService::class)->resubmit($request->fresh(), $preparer);

        $request->refresh();
        $this->assertSame('in_review', $request->status);
        $this->assertSame('in_review', ReportInstance::where('report_request_id', $request->id)->first()->status);
        $this->assertSame(1, $request->approvals()->where('status', 'pending')->count());
        $this->assertNull($request->approvals()->first()->reason);

        // Se vuelve a avisar a los firmantes.
        Notification::assertSentTo($signer, ReportApprovalRequested::class);
    }

    public function test_resubmit_blocked_for_non_owner_or_non_rejected(): void
    {
        $preparer = $this->user();
        $other    = $this->user();

        $tenant = Tenant::find(1);
        $t = Transformer::factory()->create(['tenant_id' => 1]);

        $this->actingAs($preparer);
        $request = app(ReportApprovalService::class)->createRequest($tenant, $preparer, 'transformer', [$this->instanceRow($t)], label: $t->serial);

        // En revisión (no rechazada): no se puede reenviar.
        try {
            app(ReportApprovalService::class)->resubmit($request->fresh(), $preparer);
            $this->fail('debió abortar: la solicitud no está rechazada');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_issue_freezes_pdf_with_hash_and_samples_snapshot(): void
    {
        Notification::fake();
        Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');

        $preparer = $this->user();
        $signer   = $this->user();
        $tenant   = Tenant::find(1);
        $tenant->reportSigners()->create(['user_id' => $signer->id, 'title' => 'Supervisor', 'relation' => 'approved', 'sort_order' => 1]);

        $t = Transformer::factory()->create(['tenant_id' => 1]);
        // Una muestra de cromas: debe quedar en el array de auditoria del snapshot.
        $t->chromatographicals()->create(['sample_date' => '2026-01-15', 'h2' => 20, 'ch4' => 15, 'tenant_id' => 1]);

        $this->actingAs($preparer);
        $request = app(ReportApprovalService::class)->createRequest(
            $tenant, $preparer, 'transformer', [$this->instanceRow($t)], label: $t->serial,
        );

        $this->actingAs($signer);
        app(ReportApprovalService::class)->approve($request->approvals()->first(), $signer);

        $instance = ReportInstance::where('report_request_id', $request->id)->first();
        $this->assertNotNull($instance->frozen_path, 'el PDF debe congelarse al emitir');
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($instance->frozen_path);

        $bytes = \Illuminate\Support\Facades\Storage::disk('local')->get($instance->frozen_path);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(hash('sha256', $bytes), $instance->frozen_hash);
        $this->assertNotNull($instance->frozen_at);

        // Array de auditoria: las muestras que respaldaron la firma.
        $this->assertSame('2026-01-15', $instance->snapshot['samples']['cromas'][0]['sample_date']);
        $this->assertEquals(20, $instance->snapshot['samples']['cromas'][0]['h2']);
    }

    public function test_download_serves_the_frozen_file_bytes(): void
    {
        Notification::fake();
        Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');

        $preparer = $this->user();
        $signer   = $this->user();
        $tenant   = Tenant::find(1);
        $tenant->reportSigners()->create(['user_id' => $signer->id, 'title' => 'Supervisor', 'relation' => 'approved', 'sort_order' => 1]);
        $t = Transformer::factory()->create(['tenant_id' => 1]);

        $this->actingAs($preparer);
        $request = app(ReportApprovalService::class)->createRequest(
            $tenant, $preparer, 'transformer', [$this->instanceRow($t)], label: $t->serial,
        );
        $this->actingAs($signer);
        app(ReportApprovalService::class)->approve($request->approvals()->first(), $signer);

        $instance = ReportInstance::where('report_request_id', $request->id)->first();
        $frozen = \Illuminate\Support\Facades\Storage::disk('local')->get($instance->frozen_path);

        // El solicitante descarga: deben llegar EXACTAMENTE los bytes congelados.
        $this->actingAs($preparer);
        $response = $this->get(route('report_requests.download', $instance));
        $response->assertOk();
        $this->assertSame(hash('sha256', $frozen), hash('sha256', $response->streamedContent()));
    }

    public function test_purge_frozen_respects_retention_and_keeps_audit(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\DB::table('settings')->insert([
            'slug' => Str::random(22), 'key' => 'reports.frozen_retention_years', 'name' => 'ret',
            'type' => 'int', 'value' => '2', 'group' => 'downloads', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $t = Transformer::factory()->create(['tenant_id' => 1]);
        \Illuminate\Support\Facades\Storage::disk('local')->put('frozen-reports/1/old.pdf', '%PDF-old');
        \Illuminate\Support\Facades\Storage::disk('local')->put('frozen-reports/1/new.pdf', '%PDF-new');

        $old = ReportInstance::create([
            'tenant_id' => 1, 'transformer_id' => $t->id, 'status' => 'approved',
            'report_code' => 'A', 'verify_code' => 'A', 'snapshot' => ['x' => 1],
            'frozen_path' => 'frozen-reports/1/old.pdf', 'frozen_hash' => hash('sha256', '%PDF-old'),
            'frozen_at' => now()->subYears(3),
        ]);
        $new = ReportInstance::create([
            'tenant_id' => 1, 'transformer_id' => $t->id, 'status' => 'approved',
            'report_code' => 'B', 'verify_code' => 'B', 'snapshot' => ['x' => 2],
            'frozen_path' => 'frozen-reports/1/new.pdf', 'frozen_hash' => hash('sha256', '%PDF-new'),
            'frozen_at' => now()->subMonths(6),
        ]);

        $this->artisan('reports:purge-frozen')->assertSuccessful();

        // El viejo pierde el ARCHIVO pero conserva hash y snapshot (auditoria).
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('frozen-reports/1/old.pdf');
        $old->refresh();
        $this->assertNull($old->frozen_path);
        $this->assertSame(hash('sha256', '%PDF-old'), $old->frozen_hash);
        $this->assertSame(['x' => 1], $old->snapshot);

        // El reciente queda intacto.
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('frozen-reports/1/new.pdf');
        $this->assertNotNull($new->fresh()->frozen_path);
    }

}
