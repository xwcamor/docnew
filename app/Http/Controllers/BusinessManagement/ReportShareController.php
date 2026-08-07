<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Mail\ShareInviteMail;
use App\Models\Customer;
use App\Models\ReportShare;
use App\Models\Transformer;
use App\Services\Sharing\ReportShareService;
use App\Support\Tz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * ReportShareController — gestión de enlaces para compartir diagnósticos
 * (lado interno, autenticado). Crear, listar, reenviar, extender y revocar.
 * El modal de "Compartir" consume estos endpoints por JSON.
 * Ver docs/COMPARTIR-REPORTES.md.
 */
class ReportShareController extends Controller
{
    public function __construct(private ReportShareService $svc)
    {
    }

    /** Enlaces (no revocados) de un alcance (trafo o flota). JSON para el modal. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', 'in:transformer,fleet'],
            'scope_id'   => ['required', 'integer'],
            'with_revoked' => ['nullable', 'boolean'],
        ]);

        // Resolución scoped al tenant (BelongsToTenant): si no es suyo → 404.
        $column = $data['scope_type'] === 'transformer' ? 'transformer_id' : 'customer_id';
        if ($data['scope_type'] === 'transformer') {
            Transformer::findOrFail($data['scope_id']);
        } else {
            Customer::findOrFail($data['scope_id']);
        }

        // Los revocados se piden aparte: por defecto estorban, pero tienen que
        // poder verse (la fila y su auditoría quedan; antes no había dónde mirarlas).
        $base = ReportShare::where('scope_type', $data['scope_type'])
            ->where($column, $data['scope_id'])
            ->when(!($data['with_revoked'] ?? false), fn ($q) => $q->whereNull('revoked_at'));

        $total  = (clone $base)->count();
        $shares = $base->orderByDesc('created_at')->limit(self::LIST_LIMIT)->get();

        // Opciones del Select de flota: se cargan SOLO si se piden. Son la flota
        // entera del cliente (cientos de filas) y desde que la selección se hace
        // en el índice casi nunca se abre ese Select.
        $transformers = [];
        $fleetCount   = null;
        if ($data['scope_type'] === 'fleet') {
            // El TOTAL va siempre (es un count): el front lo necesita para saber
            // si la selección abarca toda la flota. Sin esto, al no cargar las
            // opciones creería que sí y compartiría de más.
            $fleetCount = Transformer::where('customer_id', $data['scope_id'])->count();

            if ($request->boolean('with_transformers')) {
                $transformers = Transformer::where('customer_id', $data['scope_id'])
                    ->orderBy('serial')->get(['id', 'serial', 'tag'])
                    ->map(fn ($t) => ['id' => $t->id, 'label' => $t->serial ?: $t->tag])->all();
            }
        }

        // Etiquetas de TODOS los enlaces en una sola consulta (antes era una por
        // enlace: 25 enlaces = 25 consultas).
        $labels = $this->labelsFor($shares);

        return response()->json([
            'shares'       => $shares->map(fn ($s) => $this->present($s, $labels[$s->id] ?? null))->all(),
            'transformers' => $transformers,
            'fleet_count'  => $fleetCount,
            // El front avisa cuándo la lista viene cortada: un corte callado se
            // lee como "esto es todo lo que hay".
            'total'        => $total,
            'limit'        => self::LIST_LIMIT,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type'        => ['required', 'in:transformer,fleet'],
            // nullable: el id que NO aplica al alcance llega como null desde el modal;
            // sin nullable, la regla integer falla contra ese null. required_if sigue
            // exigiendo el id correcto según el scope.
            'transformer_id'    => ['nullable', 'required_if:scope_type,transformer', 'integer'],
            'customer_id'       => ['nullable', 'required_if:scope_type,fleet', 'integer'],
            'transformer_ids'   => ['nullable', 'array'],
            'transformer_ids.*' => ['integer'],
            // Opcional: sin destinatario se genera un enlace que el usuario
            // reparte por su cuenta (sin código al correo). Ver isLinkOnly().
            'recipient_email'   => ['nullable', 'email', 'max:190'],
            'note'              => ['nullable', 'string', 'max:250'],
            // 1 día: pensado para el enlace sin destinatario, que es el más
            // expuesto (no pide código). Ver docs/COMPARTIR-REPORTES.md.
            'expiration_days'   => ['required', 'in:1,7,30,90'],
            'locale'            => ['required', 'in:es,en'],
            // Gráficos capturados por el navegador (echarts) al compartir: se
            // cachean para que el PDF del portal salga idéntico al de la app,
            // sin depender de que el informe se haya generado antes.
            'images'            => ['nullable', 'array'],
        ]);

        $transformerIds = null;
        if ($data['scope_type'] === 'transformer') {
            $t = Transformer::findOrFail($data['transformer_id']);
            [$transformerId, $customerId, $tenantId] = [$t->id, null, $t->tenant_id];
        } else {
            $c = Customer::findOrFail($data['customer_id']);
            [$transformerId, $customerId, $tenantId] = [null, $c->id, $c->tenant_id];

            // Flota parcial: subconjunto VÁLIDO de trafos del cliente. Si abarca
            // todos (o viene vacío), se guarda null = toda la flota.
            $owned = Transformer::where('customer_id', $c->id)->pluck('id')->all();
            $picked = array_values(array_intersect($data['transformer_ids'] ?? [], $owned));
            if (!empty($picked) && count($picked) < count($owned)) {
                $transformerIds = $picked;
            }
        }

        // Si el workspace EXIGE aprobación, el "compartir" no crea el enlace ya:
        // manda los informes a aprobación con el destinatario guardado. Al
        // aprobarse, el flujo activa el share y envía el enlace solo (auto-
        // compartir). Ver ReportApprovalService::autoShare.
        $approvals = app(\App\Services\Reports\ReportApprovalService::class);
        if ($approvals->isRequired(\App\Models\Tenant::find($tenantId))) {
            // El auto-compartir manda el enlace AL DESTINATARIO al aprobarse; sin
            // correo la solicitud se aprobaría y no generaría nada. Se corta acá
            // en vez de dejar el callejón sin salida.
            if (blank($data['recipient_email'] ?? null)) {
                return response()->json(['message' => __('sharing.link_only_needs_approval_email')], 422);
            }

            return $this->sendToApproval($request, $data, $tenantId, $transformerId, $customerId, $transformerIds, $approvals);
        }

        $share = ReportShare::create([
            'scope_type'      => $data['scope_type'],
            'transformer_id'  => $transformerId,
            'customer_id'     => $customerId,
            'transformer_ids' => $transformerIds,
            'tenant_id'       => $tenantId,
            'recipient_email' => $data['recipient_email'],
            'note'            => $data['note'] ?? null,
            'locale'          => $data['locale'],
            'expires_at'      => now()->addDays((int) $data['expiration_days']),
            'created_by'      => auth()->id(),
        ]);

        // Sin destinatario no hay invitación que mandar: el enlace se copia.
        if (!$share->isLinkOnly()) {
            $this->sendInvite($share);
        }
        $share->auditShared();

        // Cachea los gráficos reales del trafo compartido (si el navegador los
        // mandó) → el portal reusa estos, no unos redibujados con GD.
        if ($data['scope_type'] === 'transformer' && !empty($request->input('images'))) {
            $charts = collect($request->input('images'))
                ->filter(fn ($i) => is_array($i) && is_string($i['dataURL'] ?? null)
                    && str_starts_with($i['dataURL'], 'data:image/png;base64,'))
                ->map(fn ($i) => ['label' => (string) ($i['label'] ?? ''), 'group' => (string) ($i['group'] ?? ''), 'dataURL' => $i['dataURL']])
                ->values()->all();
            app(\App\Services\Reports\ReportChartCache::class)->store($t, $charts);
        }

        return response()->json(['share' => $this->present($share)], 201);
    }

    /**
     * Compartir CON aprobación exigida: arma una solicitud que agrupa los
     * informes del alcance (1 trafo o N de flota) y guarda los datos del envío.
     * Los firmantes la aprueban en "Aprobaciones"; al emitirse, el enlace se
     * manda solo al destinatario (auto-compartir). NO se crea share todavía.
     */
    private function sendToApproval(Request $request, array $data, int $tenantId, ?int $transformerId, ?int $customerId, ?array $transformerIds, \App\Services\Reports\ReportApprovalService $approvals): JsonResponse
    {
        $tenant  = \App\Models\Tenant::find($tenantId);
        $reports = app(\App\Services\Reports\TransformerReportService::class);

        if ($data['scope_type'] === 'transformer') {
            $transformers = Transformer::where('id', $transformerId)->get();
            $scope = 'transformer';
            $label = $transformers->first()?->serial ?: $transformers->first()?->tag;
        } else {
            $q = Transformer::where('customer_id', $customerId);
            if (!empty($transformerIds)) {
                $q->whereIn('id', $transformerIds);
            }
            $transformers = $q->get();
            $scope = 'fleet';
            $label = trans_choice('approvals.fleet_label', $transformers->count(), [
                'customer' => Customer::find($customerId)?->name ?: '—',
                'count'    => $transformers->count(),
            ]);
        }

        // Gráficos del navegador: solo aplican al trafo single (en flota no hay
        // captura por trafo; el PDF usa el render server-side).
        $charts = [];
        if ($scope === 'transformer') {
            $charts = collect($request->input('images', []))
                ->filter(fn ($i) => is_array($i) && is_string($i['dataURL'] ?? null)
                    && str_starts_with($i['dataURL'], 'data:image/png;base64,'))
                ->map(fn ($i) => ['label' => (string) ($i['label'] ?? ''), 'group' => (string) ($i['group'] ?? ''), 'dataURL' => $i['dataURL']])
                ->values()->all();
        }

        $instances = [];
        foreach ($transformers as $i => $t) {
            $d = $reports->approvalData($t, $i === 0 ? $charts : []);
            $instances[] = [
                'transformer' => $t,
                'snapshot'    => $d['snapshot'],
                'report_code' => $d['report_code'],
                'verify_code' => $d['verify_code'],
            ];
        }

        $approvals->createRequest(
            tenant: $tenant,
            requester: $request->user(),
            scope: $scope,
            instances: $instances,
            customerId: $scope === 'fleet' ? $customerId : null,
            label: $label,
            shareParams: [
                'recipient_email' => $data['recipient_email'],
                'expiration_days' => (int) $data['expiration_days'],
                'locale'          => $data['locale'],
            ],
        );

        return response()->json([
            'approval' => true,
            'message'  => trans_choice('approvals.share_sent', count($instances), ['count' => count($instances)]),
        ], 202);
    }

    public function resend(ReportShare $share): JsonResponse
    {
        $this->authorizeOwnership($share);
        // Sin destinatario no hay a quién reenviarle: ese enlace lo reparte el
        // usuario. El front esconde el botón; esto cubre el llamado directo.
        abort_if($share->isLinkOnly(), 422, __('sharing.no_resend_link_only'));
        $this->sendInvite($share);

        return response()->json(['ok' => true]);
    }

    public function extend(Request $request, ReportShare $share): JsonResponse
    {
        $this->authorizeOwnership($share);
        $days = (int) $request->validate(['days' => ['required', 'in:1,7,30,90']])['days'];
        $share->update(['expires_at' => now()->addDays($days)]);

        return response()->json(['share' => $this->present($share)]);
    }

    public function revoke(ReportShare $share): JsonResponse
    {
        $this->authorizeOwnership($share);
        $share->update(['revoked_at' => now()]);
        $share->auditRevoked();

        return response()->json(['ok' => true]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function authorizeOwnership(ReportShare $share): void
    {
        $u = auth()->user();
        abort_unless($u->hasRole('super') || $share->tenant_id === $u->tenant_id, 403);
    }

    private function sendInvite(ReportShare $share): void
    {
        // Encolado: el envío no bloquea la respuesta de crear/reenviar el share
        // (requiere queue:work). El OTP, en cambio, va sincrónico — el cliente lo
        // espera en la pantalla del gate.
        Mail::to($share->recipient_email)->locale($share->locale)->queue(
            new ShareInviteMail($share, route('share.gate', $share->token), $this->svc->logoDataUri($share), auth()->user()->name)
        );
    }

    private function present(ReportShare $share, ?array $labels = null): array
    {
        return [
            'id'             => $share->id,
            'recipient_email'=> $share->recipient_email,
            'locale'         => $share->locale,
            'url'            => route('share.gate', $share->token),
            // Formateadas en la zona del usuario (ver Tz). Mandar el ISO crudo
            // dejaba la conversión al navegador, que puede estar en otra zona.
            'expires_at'     => Tz::formatDate($share->expires_at),
            'expired'        => $share->expires_at->isPast(),
            'last_opened_at' => $share->last_opened_at ? Tz::formatDate($share->last_opened_at) : null,
            'open_count'     => $share->open_count,
            'partial_count'  => $share->isFleet() && !empty($share->transformer_ids) ? count($share->transformer_ids) : null,
            // Cuándo se envió y QUÉ se envió: sin esto no había forma de saber
            // qué transformadores recibió el cliente en cada enlace.
            'created_at'     => Tz::format($share->created_at),
            'sent_labels'    => $labels ?? $this->sentLabels($share),
            'link_only'      => $share->isLinkOnly(),
            'note'           => $share->note,
            'revoked_at'     => $share->revoked_at ? Tz::format($share->revoked_at) : null,
        ];
    }

    /**
     * Cuántas etiquetas se mandan por enlace. La UI muestra 6 y despliega el
     * resto; mandar las 400 de una flota grande hinchaba el JSON sin que nadie
     * las leyera. El TOTAL real viaja aparte en partial_count.
     */
    private const SENT_LABELS_MAX = 20;

    /** Cuántos enlaces lista el modal. Lo que exceda se ve en /report_shares. */
    private const LIST_LIMIT = 50;

    /**
     * Etiquetas de los transformadores incluidos en cada enlace, resueltas en
     * UNA consulta para toda la lista.
     *
     * @param  \Illuminate\Support\Collection<int,ReportShare>  $shares
     * @return array<int,array<int,string>>  [share_id => etiquetas]
     */
    private function labelsFor($shares): array
    {
        $ids = $shares
            ->filter(fn ($s) => $s->isFleet() && !empty($s->transformer_ids))
            ->flatMap(fn ($s) => $s->transformer_ids)
            ->unique()->values()->all();

        if (empty($ids)) {
            return [];
        }

        // Orden por serial acá: así el recorte a SENT_LABELS_MAX se queda con
        // las primeras del listado y no con un subconjunto arbitrario.
        $byId = Transformer::whereIn('id', $ids)
            ->orderBy('serial')
            ->get(['id', 'serial', 'tag'])
            ->mapWithKeys(fn ($t) => [$t->id => $t->serial ?: $t->tag])
            ->filter();

        $out = [];
        foreach ($shares as $s) {
            if (!$s->isFleet() || empty($s->transformer_ids)) {
                continue;
            }
            $out[$s->id] = $byId->only($s->transformer_ids)
                ->take(self::SENT_LABELS_MAX)->values()->all();
        }

        return $out;
    }

    /** Igual que labelsFor pero para un enlace suelto (respuesta de store). */
    private function sentLabels(ReportShare $share): ?array
    {
        if (!$share->isFleet() || empty($share->transformer_ids)) {
            return null;
        }

        return $this->labelsFor(collect([$share]))[$share->id] ?? [];
    }
}
