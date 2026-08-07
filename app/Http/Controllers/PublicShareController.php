<?php

namespace App\Http\Controllers;

use App\Mail\ShareOtpMail;
use App\Models\ReportShare;
use App\Services\Sharing\ReportShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * PublicShareController — portal PÚBLICO (sin login) para que un cliente externo
 * vea un diagnóstico compartido. Acceso: token en la URL + OTP enviado al correo.
 * Ver docs/COMPARTIR-REPORTES.md.
 *
 * Seguridad: todo se lee scoped al share (transformer_id/customer_id). El idioma
 * del portal/mails es el del share.
 */
class PublicShareController extends Controller
{
    public function __construct(private ReportShareService $svc)
    {
    }

    /** Resuelve el share por token o aborta. Setea el locale del share. */
    private function share(string $token): ReportShare
    {
        $share = ReportShare::where('token', $token)->first();
        abort_if(!$share, 404);
        app()->setLocale($share->locale);

        return $share;
    }

    private function hasAccess(ReportShare $share): bool
    {
        $exp = session("share_access.{$share->id}");
        return $exp !== null && $exp > now()->timestamp;
    }

    /** Pantalla de acceso: si ya tiene sesión, va al portal; sino, pide el código. */
    public function gate(string $token)
    {
        $share = $this->share($token);

        if (!$share->isActive()) {
            return response()->view('share.expired', ['share' => $share], 410);
        }
        if ($this->hasAccess($share)) {
            return redirect()->route('share.view', $share->token);
        }
        // Enlace sin destinatario: no hay correo al que mandar el código, así
        // que el token ES la credencial. Vale el mismo vencimiento.
        if ($share->isLinkOnly()) {
            session(["share_access.{$share->id}" => now()->addHours(24)->timestamp]);

            return redirect()->route('share.view', $share->token);
        }

        return view('share.gate', [
            'share' => $share,
            'logo'  => $this->svc->logoDataUri($share),
        ]);
    }

    /** Manda el OTP al correo del destinatario. */
    public function sendCode(string $token)
    {
        $share = $this->share($token);
        abort_unless($share->isActive(), 410);
        abort_if($share->isLinkOnly(), 404);

        $code = $this->svc->generateOtp($share);
        Mail::to($share->recipient_email)->locale($share->locale)->send(
            new ShareOtpMail($share, $code, $this->svc->logoDataUri($share))
        );

        return back()->with('codeSent', true);
    }

    /** Valida el OTP y abre la sesión del share (24 h). */
    public function verify(Request $request, string $token)
    {
        $share = $this->share($token);
        abort_unless($share->isActive(), 410);

        $request->validate(['code' => ['required', 'string']]);

        if (!$this->svc->verifyOtp($share, trim($request->input('code')))) {
            return back()->withErrors(['code' => __('sharing.otp_invalid')]);
        }

        session(["share_access.{$share->id}" => now()->addHours(24)->timestamp]);

        return redirect()->route('share.view', $share->token);
    }

    /** Portal de lectura: 1 trafo = resumen; flota = lista de trafos. */
    public function view(string $token)
    {
        $share = $this->share($token);
        if (!$share->isActive()) {
            return response()->view('share.expired', ['share' => $share], 410);
        }
        if (!$this->hasAccess($share)) {
            return redirect()->route('share.gate', $share->token);
        }

        $share->forceFill([
            'last_opened_at' => now(),
            'open_count'     => $share->open_count + 1,
        ])->saveQuietly();

        $logo = $this->svc->logoDataUri($share);

        if ($share->isFleet()) {
            // Listado: HI CACHEADO por fila (sin recalcular) → escala a flotas grandes.
            $rows = $this->svc->transformersInScope($share)->map(fn ($t) => $this->svc->fleetRow($t))->all();
            return view('share.fleet', compact('share', 'rows', 'logo'));
        }

        $t = $this->svc->transformersInScope($share)->first();
        abort_if(!$t, 404);

        return view('share.single', [
            'share'       => $share,
            'summary'     => $this->svc->summary($t),
            'detail'      => $this->svc->detail($t),
            'transformer' => $t,
            'logo'        => $logo,
        ]);
    }

    /**
     * Detalle completo de UN trafo del alcance (drill-down desde la flota):
     * misma vista que el share individual, con volver a la lista.
     */
    public function transformer(string $token, int $transformer)
    {
        $share = $this->share($token);
        if (!$share->isActive()) {
            return response()->view('share.expired', ['share' => $share], 410);
        }
        if (!$this->hasAccess($share)) {
            return redirect()->route('share.gate', $share->token);
        }

        $t = $this->svc->transformerInScope($share, $transformer);
        abort_if(!$t, 404);

        return view('share.single', [
            'share'       => $share,
            'summary'     => $this->svc->summary($t),
            'detail'      => $this->svc->detail($t),
            'transformer' => $t,
            'logo'        => $this->svc->logoDataUri($share),
            'backUrl'     => $share->isFleet() ? route('share.view', $share->token) : null,
        ]);
    }

    /**
     * Descarga el INFORME COMPLETO de un trafo del alcance (libre, ya validó
     * OTP). Mismo PDF oficial que genera la app, vía TransformerReportService:
     * sin gráficos de tendencia (no hay navegador logueado que los capture) y
     * sin firma de preparador (quien descarga es el cliente externo — regla
     * anti-suplantación); las firmas pre-autorizadas del flujo SÍ salen, y la
     * emisión queda auditada → el PDF es verificable en /verify.
     */
    public function pdf(string $token, int $transformer, \App\Services\Reports\TransformerReportService $reports)
    {
        $share = $this->share($token);
        abort_unless($share->isActive() && $this->hasAccess($share), 403);

        $t = $this->svc->transformerInScope($share, $transformer);
        abort_if(!$t, 404);

        // "Elaborado por" = quien EMITIÓ el link (responsable del laboratorio).
        // No estampamos su firma: él no descarga (lo hace el cliente externo),
        // así que solo va su nombre. Las firmas pre-autorizadas del flujo sí salen.
        $issuer = \App\Models\User::withTrashed()->find($share->created_by);

        // Gating por aprobación: si el workspace EXIGE aprobación, el cliente solo
        // puede bajar el PDF de un trafo cuyo informe ya fue APROBADO. Si no hay
        // informe aprobado, se bloquea (pendiente de aprobación). Las firmas del
        // PDF salen de quienes aprobaron esa solicitud.
        $approvalSigners = null;
        if (app(\App\Services\Reports\ReportApprovalService::class)->isRequired($t->tenant)) {
            $instance = \App\Models\ReportInstance::where('transformer_id', $t->id)
                ->where('status', 'approved')
                ->whereHas('request', fn ($q) => $q->where('status', 'approved'))
                ->with('request')
                ->latest('id')->first();
            abort_unless($instance, 403, __('approvals.pdf_pending'));

            // Informe CONGELADO: el cliente recibe el archivo exacto que se
            // firmó (acta inmutable), no un re-render con datos posteriores.
            if ($instance->frozen_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($instance->frozen_path)) {
                return \Illuminate\Support\Facades\Storage::disk('local')->download(
                    $instance->frozen_path,
                    $t->reportFilename('informe'),
                );
            }

            $approvalSigners = $reports->approvedSigners($instance->request);
        }

        $pdf = $reports->pdf($t, charts: [], preparer: null, generatedByName: $issuer?->name, auditExtra: [
            'source'    => 'share_portal',
            'share_id'  => $share->id,
            'recipient' => $share->recipient_email,
        ], approvalSigners: $approvalSigners);

        return $pdf->download($t->reportFilename('informe'));
    }
}
