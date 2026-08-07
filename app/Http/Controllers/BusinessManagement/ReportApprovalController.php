<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\ReportApproval;
use App\Models\ReportInstance;
use App\Services\Reports\ReportApprovalService;
use App\Services\Reports\TransformerReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Response;

/**
 * Bandeja "Aprobaciones": solicitudes de informes pendientes de firma del
 * usuario + historial, y las acciones aprobar/rechazar (etapa 2 de firmas,
 * modelo batch — una solicitud agrupa N informes). Cada usuario solo ve/actúa
 * sobre SUS aprobaciones (no hay menú para quien no es firmante).
 */
class ReportApprovalController extends Controller
{
    public function __construct(private ReportApprovalService $service) {}

    public function index(\Illuminate\Http\Request $request): Response
    {
        // Solo se pagina la pestaña activa; los contadores son COUNT baratos.
        $tab = in_array($request->query('tab'), ['pending', 'done'], true) ? $request->query('tab') : 'pending';
        $uid = Auth::id();

        $page = $tab === 'done'
            ? $this->service->historyFor($uid)
            : $this->service->pendingFor($uid);

        return inertia('Approvals/Index', [
            'rows' => [
                'data'         => collect($page->items())->map(fn ($a) => $this->card($a))->values(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'counts' => [
                'pending' => $this->service->pendingCountFor($uid),
                'done'    => $this->service->historyCountFor($uid),
            ],
            'tab' => $tab,
        ]);
    }

    public function approve(ReportApproval $approval): RedirectResponse
    {
        $this->authorizeAction($approval);
        $this->service->approve($approval, Auth::user());

        return back()->with('success', __('approvals.approved_ok'));
    }

    public function reject(Request $request, ReportApproval $approval): RedirectResponse
    {
        $this->authorizeAction($approval);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->service->reject($approval, Auth::user(), $data['reason']);

        return back()->with('success', __('approvals.rejected_ok'));
    }

    /**
     * Borrador (EN REVISIÓN) de un informe de la solicitud, para que el firmante
     * lo lea antes de aprobar. Sin firmas (todavía no firmó nadie).
     */
    public function draft(ReportInstance $instance, TransformerReportService $reports)
    {
        // Solo un firmante de esa solicitud puede ver el borrador.
        $isApprover = ReportApproval::where('report_request_id', $instance->report_request_id)
            ->where('user_id', Auth::id())->exists();
        abort_unless($isApprover && $instance->transformer, 403);

        $pdf = $reports->pdf($instance->transformer, charts: [], preparer: null, reviewState: 'in_review');

        return $pdf->stream($instance->transformer->reportFilename('borrador'));
    }

    /** Tarjeta de una solicitud (desde la fila de aprobación del usuario). */
    private function card(ReportApproval $a): array
    {
        $req = $a->reportRequest;
        $approved = $req->approvals->where('status', 'approved')->count();

        return [
            'approval_id'    => $a->id,
            'status'         => $a->status,
            'relation'       => __('approvals.relation.' . ($a->relation ?: 'approved')),
            'title'          => $a->title,
            'reason'         => $a->reason,
            'acted_at'       => $a->acted_at?->toIso8601String(),
            'label'          => $req->label,
            'scope'          => $req->scope,
            'request_status' => $req->status,
            'requester'      => $req->requester?->name,
            'customer'       => $req->customer?->name,
            'created_at'     => $req->created_at?->toIso8601String(),
            'progress'       => ['approved' => $approved, 'total' => $req->approvals->count()],
            'transformers'   => $req->instances->map(fn ($ins) => [
                'instance_id' => $ins->id,
                'serial'      => $ins->transformer?->serial,
                'tag'         => $ins->transformer?->tag,
            ])->values(),
        ];
    }

    /** Solo el firmante asignado puede actuar, y solo si está pendiente/en revisión. */
    private function authorizeAction(ReportApproval $approval): void
    {
        abort_unless(
            $approval->user_id === Auth::id()
                && $approval->status === 'pending'
                && $approval->reportRequest->status === 'in_review',
            403,
        );
    }
}
