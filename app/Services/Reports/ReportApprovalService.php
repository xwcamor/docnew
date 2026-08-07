<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use App\Models\ReportApproval;
use App\Models\ReportInstance;
use App\Models\ReportRequest;
use App\Models\ReportShare;
use App\Models\Tenant;
use App\Models\Transformer;
use App\Models\User;
use App\Services\Sharing\ReportShareService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Motor del flujo de aprobación de informes (etapa 2 de firmas), modelo BATCH.
 *
 * Opt-in por workspace (`tenants.require_report_approval`). OFF = el informe se
 * genera y firma como hoy (este servicio no interviene). ON = el preparador
 * "envía a aprobación" UNA solicitud que agrupa N informes (1 trafo o flota).
 * Cada firmante interno aprueba la SOLICITUD una sola vez → se aprueban todos
 * sus informes de golpe. Si la solicitud nació de un "compartir", al aprobarse
 * activa el share y manda el enlace al cliente (auto-compartir).
 *
 * Regla de firma (uniforme en todo el sistema): la IMAGEN de la firma se estampa
 * solo si el usuario tiene firma cargada Y activó auto-firma en su perfil. Sin
 * eso, sale solo su nombre + cargo (constancia). El check de aprobación del
 * workspace NO controla las firmas: solo fuerza este flujo de solicitudes.
 *
 * Auditoría: report_sent_for_approval / report_approved / report_rejected /
 * report_issued.
 */
class ReportApprovalService
{
    public function __construct(private ReportShareService $shares) {}

    /**
     * ¿El workspace exige el flujo de aprobación? Es POR TENANT: cada admin lo
     * decide en "Mi workspace". Sin tenant (super) → false.
     */
    public function isRequired(?Tenant $tenant = null): bool
    {
        $tenant ??= auth()->user()?->tenant;
        return (bool) ($tenant?->require_report_approval ?? false);
    }

    /**
     * Crea una solicitud de aprobación que agrupa N informes.
     *
     * @param array $instances  [ ['transformer'=>Transformer, 'snapshot'=>array,
     *                             'report_code'=>?string, 'verify_code'=>?string], ... ]
     * @param ?array $shareParams  ['recipient_email','expiration_days','locale',
     *                             'transformer_ids'?] → auto-compartir al aprobar.
     */
    public function createRequest(
        Tenant $tenant,
        User $requester,
        string $scope,
        array $instances,
        ?int $customerId = null,
        ?string $label = null,
        ?array $shareParams = null,
    ): ReportRequest {
        return DB::transaction(function () use ($tenant, $requester, $scope, $instances, $customerId, $label, $shareParams) {
            $request = ReportRequest::create([
                'tenant_id'       => $tenant->id,
                'requester_id'    => $requester->id,
                'scope'           => $scope,
                'customer_id'     => $customerId,
                'label'           => $label,
                'status'          => 'in_review',
                'recipient_email' => $shareParams['recipient_email'] ?? null,
                'expiration_days' => $shareParams['expiration_days'] ?? null,
                'locale'          => $shareParams['locale'] ?? null,
            ]);

            foreach ($instances as $row) {
                /** @var Transformer $t */
                $t = $row['transformer'];
                ReportInstance::create([
                    'tenant_id'         => $tenant->id,
                    'report_request_id' => $request->id,
                    'transformer_id'    => $t->id,
                    'preparer_id'       => $requester->id,
                    'status'            => 'in_review',
                    'report_code'       => $row['report_code'] ?? null,
                    'verify_code'       => $row['verify_code'] ?? null,
                    'snapshot'          => $row['snapshot'] ?? [],
                ]);
            }

            // Una aprobación por firmante INTERNO (con usuario). Externos = línea
            // a mano en el PDF, no entran al flujo.
            $signers = $tenant->reportSigners()
                ->whereNotNull('user_id')->orderBy('sort_order')->get();

            foreach ($signers as $s) {
                ReportApproval::create([
                    'report_request_id' => $request->id,
                    'report_signer_id'  => $s->id,
                    'user_id'           => $s->user_id,
                    'title'             => $s->title,
                    'relation'          => $s->relation ?: 'approved',
                    'status'            => 'pending',
                ]);
            }

            $this->audit('report_sent_for_approval', $request, [
                'count'   => count($instances),
                'signers' => $signers->map(fn ($s) => ['title' => $s->title, 'user_id' => $s->user_id])->all(),
            ]);

            // Aviso in-app a cada firmante (campana, categoría 'approval').
            // withoutGlobalScopes: el modelo User esconde usuarios en listados;
            // aquí cargamos destinatarios por id (ya validados como firmantes).
            if ($signers->isNotEmpty()) {
                User::withoutGlobalScopes()->whereIn('id', $signers->pluck('user_id'))->get()
                    ->each(fn ($u) => $u->notify(new \App\Notifications\ReportApprovalRequested($request)));
            }

            // Sin firmantes internos → no hay quién apruebe; se emite directo.
            if ($signers->isEmpty()) {
                $this->issue($request->fresh());
            }

            return $request;
        });
    }

    /**
     * Un firmante APRUEBA la solicitud. Estampa su firma (si tiene firma + auto-
     * firma). Si con eso todos aprobaron, se emite (oficial).
     */
    public function approve(ReportApproval $approval, User $user): void
    {
        DB::transaction(function () use ($approval, $user) {
            $approval->update([
                'status'    => 'approved',
                'signature' => $this->signatureDataUri($user),
                'acted_at'  => now(),
            ]);

            $request = $approval->reportRequest;
            $this->audit('report_approved', $request, ['by' => $user->id, 'title' => $approval->title]);

            if ($request->fresh()->allApproved()) {
                $this->issue($request->fresh());
            }
        });
    }

    /** Un firmante RECHAZA con motivo: la solicitud vuelve al preparador. */
    public function reject(ReportApproval $approval, User $user, string $reason): void
    {
        DB::transaction(function () use ($approval, $user, $reason) {
            $approval->update(['status' => 'rejected', 'reason' => $reason, 'acted_at' => now()]);
            $request = $approval->reportRequest;
            $request->update(['status' => 'rejected']);
            $request->instances()->update(['status' => 'rejected']);
            $this->audit('report_rejected', $request, ['by' => $user->id, 'title' => $approval->title, 'reason' => $reason]);

            // Avisar al solicitante que su solicitud volvió (con el motivo).
            $this->notifyRequester($request, new \App\Notifications\ReportRequestRejected($request->fresh(), $reason));
        });
    }

    /**
     * Reenviar una solicitud RECHAZADA a aprobación (el preparador la corrige y
     * la manda de nuevo). Resetea las aprobaciones a pendiente y vuelve a avisar
     * a los firmantes. Solo el solicitante, y solo si está rechazada.
     */
    public function resubmit(ReportRequest $request, User $actor): void
    {
        abort_unless($request->requester_id === $actor->id && $request->status === 'rejected', 403);

        DB::transaction(function () use ($request) {
            $request->update(['status' => 'in_review']);
            $request->instances()->update(['status' => 'in_review']);
            $request->approvals()->update([
                'status'    => 'pending',
                'reason'    => null,
                'signature' => null,
                'acted_at'  => null,
            ]);

            $this->audit('report_sent_for_approval', $request, [
                'count'     => $request->instances()->count(),
                'resubmit'  => true,
            ]);

            $signerUserIds = $request->approvals()->pluck('user_id')->filter()->all();
            if ($signerUserIds) {
                User::withoutGlobalScopes()->whereIn('id', $signerUserIds)->get()
                    ->each(fn ($u) => $u->notify(new \App\Notifications\ReportApprovalRequested($request->fresh())));
            }
        });
    }

    /** Emite la solicitud (todos aprobaron) → oficial + auto-compartir si aplica. */
    protected function issue(ReportRequest $request): void
    {
        $request->update(['status' => 'approved', 'issued_at' => now()]);
        $request->instances()->update(['status' => 'approved', 'issued_at' => now()]);

        $this->audit('report_issued', $request, [
            'count'     => $request->instances()->count(),
            'approvers' => $request->approvals()->where('status', 'approved')->pluck('title')->all(),
        ]);

        // Congelar los PDF emitidos (en cola: una flota son varios dompdf de
        // segundos). Tras el commit para que el job vea los estados nuevos.
        \App\Jobs\FreezeApprovedReports::dispatch($request->id)->afterCommit();

        $this->autoShare($request->fresh());

        // Avisar al solicitante que su solicitud fue aprobada/emitida. Solo si
        // pasó por firmantes (si no hubo, la emitió él mismo en su acción y ya
        // lo sabe — evitamos ruido).
        if ($request->approvals()->exists()) {
            $this->notifyRequester($request->fresh(), new \App\Notifications\ReportRequestApproved($request->fresh()));
        }
    }

    /**
     * Notifica al solicitante de la solicitud. Carga el User sin global scopes
     * (el modelo esconde usuarios en listados; aquí es un destinatario por id).
     */
    protected function notifyRequester(ReportRequest $request, \Illuminate\Notifications\Notification $notification): void
    {
        $requester = User::withoutGlobalScopes()->find($request->requester_id);
        $requester?->notify($notification);
    }

    /**
     * Auto-compartir: si la solicitud llevaba destinatario, al emitirse crea el
     * share oficial y manda el enlace al cliente (no hace falta volver a entrar).
     */
    protected function autoShare(ReportRequest $request): void
    {
        if (!$request->recipient_email || $request->report_share_id) {
            return;
        }

        $transformerIds = $request->instances()->pluck('transformer_id')->all();
        $isFleet = $request->isFleet();

        // Flota: subconjunto solo si NO abarca toda la flota del cliente.
        $partial = null;
        if ($isFleet && $request->customer_id) {
            $owned = Transformer::where('customer_id', $request->customer_id)->pluck('id')->all();
            if (count($transformerIds) < count($owned)) {
                $partial = array_values($transformerIds);
            }
        }

        $share = ReportShare::create([
            'scope_type'      => $isFleet ? 'fleet' : 'transformer',
            'transformer_id'  => $isFleet ? null : ($transformerIds[0] ?? null),
            'customer_id'     => $isFleet ? $request->customer_id : null,
            'transformer_ids' => $partial,
            'tenant_id'       => $request->tenant_id,
            'recipient_email' => $request->recipient_email,
            'locale'          => $request->locale ?: 'es',
            'expires_at'      => now()->addDays((int) ($request->expiration_days ?: 30)),
            'created_by'      => $request->requester_id,
        ]);

        $request->update(['report_share_id' => $share->id]);
        $share->auditShared();

        $issuer = User::withTrashed()->find($request->requester_id);
        Mail::to($share->recipient_email)->locale($share->locale)->queue(
            new \App\Mail\ShareInviteMail(
                $share,
                route('share.gate', $share->token),
                $this->shares->logoDataUri($share),
                $issuer?->name ?? ''
            )
        );
    }

    /** Relaciones comunes de las tarjetas de aprobación. */
    protected function approvalCardWith(): array
    {
        return ['reportRequest' => fn ($q) => $q->with([
            'requester:id,name',
            'customer:id,name',
            'approvals:id,report_request_id,status',
            'instances:id,report_request_id,transformer_id',
            'instances.transformer:id,slug,serial,tag',
        ])];
    }

    /** Aprobaciones PENDIENTES de un usuario (bandeja "Aprobaciones"), paginadas. */
    public function pendingFor(int $userId): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return ReportApproval::where('user_id', $userId)
            ->where('status', 'pending')
            ->whereHas('reportRequest', fn ($q) => $q->where('status', 'in_review'))
            ->with($this->approvalCardWith())
            ->orderByDesc('id')
            ->paginate(12);
    }

    /** Aprobaciones ya RESUELTAS por el usuario (pestaña "Completados"), paginadas. */
    public function historyFor(int $userId): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return ReportApproval::where('user_id', $userId)
            ->whereIn('status', ['approved', 'rejected'])
            ->with($this->approvalCardWith())
            ->orderByDesc('acted_at')
            ->paginate(12);
    }

    /** Conteo de pendientes (badge de pestaña, sin cargar filas). */
    public function pendingCountFor(int $userId): int
    {
        return ReportApproval::where('user_id', $userId)
            ->where('status', 'pending')
            ->whereHas('reportRequest', fn ($q) => $q->where('status', 'in_review'))
            ->count();
    }

    /** Conteo de completadas (badge de pestaña, sin cargar filas). */
    public function historyCountFor(int $userId): int
    {
        return ReportApproval::where('user_id', $userId)
            ->whereIn('status', ['approved', 'rejected'])
            ->count();
    }

    /**
     * Firma del usuario → data uri, SOLO si tiene firma cargada Y activó auto-
     * firma (regla uniforme; la aprobación no la salta). Sin eso → null (sale
     * nombre + cargo, sin imagen).
     */
    protected function signatureDataUri(?User $user): ?string
    {
        if (!$user?->signature || !$user->auto_sign_reports) {
            return null;
        }
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('local');
            if (!$disk->exists($user->signature)) {
                return null;
            }
            return 'data:' . $disk->mimeType($user->signature) . ';base64,' . base64_encode($disk->get($user->signature));
        } catch (\Throwable) {
            return null;
        }
    }

    protected function audit(string $event, ReportRequest $request, array $values): void
    {
        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => $event,
            'auditable_type' => ReportRequest::class,
            'auditable_id'   => $request->id,
            'module'         => 'transformers',
            'old_values'     => null,
            'new_values'     => array_merge(['report_request_id' => $request->id, 'label' => $request->label], $values),
            'url'            => request()?->fullUrl(),
            'ip_address'     => request()?->ip(),
            'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
        ]);
    }
}
