<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\ReportInstance;
use App\Models\ReportRequest;
use App\Services\Reports\ReportApprovalService;
use App\Services\Reports\TransformerReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Response;

/**
 * Bandeja "Mis solicitudes": lo que el usuario actual envió a aprobación, con
 * su estado (en revisión / aprobada / rechazada), progreso de firmas y motivo
 * de rechazo. Permite descargar el PDF ya emitido y reenviar las rechazadas.
 *
 * Es el otro lado del flujo: "Aprobaciones" = lo que firmo; aquí = lo que pedí.
 * Cada usuario solo ve SUS solicitudes (requester_id), con el scope de tenant
 * heredado del modelo.
 */
class ReportRequestController extends Controller
{
    public function __construct(private ReportApprovalService $service) {}

    public function index(\Illuminate\Http\Request $request): Response
    {
        // Una pestaña = un estado. Solo se carga la PÁGINA de la pestaña activa
        // (no las 500 de una): los contadores salen de una query agregada barata.
        $statuses = ['review' => 'in_review', 'approved' => 'approved', 'rejected' => 'rejected'];
        $tab = $request->query('tab');
        $tab = isset($statuses[$tab]) ? $tab : 'review';

        $base = ReportRequest::where('requester_id', Auth::id());

        $byStatus = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $counts = [
            'review'   => (int) ($byStatus['in_review'] ?? 0),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
        ];

        $page = (clone $base)
            ->where('status', $statuses[$tab])
            ->with([
                'approvals:id,report_request_id,user_id,title,relation,status,reason,acted_at',
                'instances:id,report_request_id,transformer_id,status',
                'instances.transformer:id,slug,serial,tag',
                'customer:id,name',
            ])
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return inertia('ReportRequests/Index', [
            'requests' => [
                'data'         => collect($page->items())->map(fn ($r) => $this->card($r))->values(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'counts' => $counts,
            'tab'    => $tab,
        ]);
    }

    /** Descarga el PDF EMITIDO de un informe aprobado de una solicitud propia. */
    public function download(ReportInstance $instance, TransformerReportService $reports)
    {
        $request = $instance->request;
        abort_unless(
            $request && $request->requester_id === Auth::id()
                && $request->status === 'approved'
                && $instance->transformer,
            403,
        );

        // Si el informe ya está CONGELADO, se sirve el archivo exacto que se
        // emitió (acta inmutable). El render en vivo queda solo como fallback
        // para la ventana de segundos entre aprobar y que el job congele.
        if ($instance->frozen_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($instance->frozen_path)) {
            return \Illuminate\Support\Facades\Storage::disk('local')->response(
                $instance->frozen_path,
                $instance->transformer->reportFilename('informe'),
            );
        }

        $pdf = $reports->pdf(
            $instance->transformer,
            charts: [],
            preparer: Auth::user(),
            approvalSigners: $reports->approvedSigners($request),
            reviewState: null,
        );

        return $pdf->stream($instance->transformer->reportFilename('informe'));
    }

    /** Reenvía a aprobación una solicitud rechazada (vuelve a in_review). */
    public function resubmit(ReportRequest $reportRequest): RedirectResponse
    {
        $this->service->resubmit($reportRequest, Auth::user());

        return back()->with('success', __('approvals.resubmit_ok'));
    }

    /** Tarjeta de una solicitud propia. */
    private function card(ReportRequest $r): array
    {
        $approved = $r->approvals->where('status', 'approved')->count();
        $rejection = $r->approvals->firstWhere('status', 'rejected');

        return [
            'id'             => $r->id,
            'label'          => $r->label,
            'scope'          => $r->scope,
            'status'         => $r->status,
            'customer'       => $r->customer?->name,
            'recipient'      => $r->recipient_email,
            'shared'         => (bool) $r->report_share_id,
            'created_at'     => $r->created_at?->toIso8601String(),
            'issued_at'      => $r->issued_at?->toIso8601String(),
            'progress'       => ['approved' => $approved, 'total' => $r->approvals->count()],
            'reject_reason'  => $rejection?->reason,
            'rejected_by'    => $rejection?->title,
            'signers'        => $r->approvals->map(fn ($a) => [
                'title'    => $a->title,
                'relation' => __('approvals.relation.' . ($a->relation ?: 'approved')),
                'status'   => $a->status,
            ])->values(),
            'transformers'   => $r->instances->map(fn ($ins) => [
                'instance_id' => $ins->id,
                'slug'        => $ins->transformer?->slug,
                'serial'      => $ins->transformer?->serial,
                'tag'         => $ins->transformer?->tag,
                'status'      => $ins->status,
            ])->values(),
        ];
    }
}
