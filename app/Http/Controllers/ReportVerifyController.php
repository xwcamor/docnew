<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Transformer;

/**
 * Portal público de verificación de informes (el destino del QR de la carátula).
 *
 * No expone datos del cliente: solo confirma que un código de verificación
 * corresponde a un informe emitido por el sistema y muestra los metadatos de
 * emisión (código de informe, fecha, empresa emisora, serial, HI, aprobador).
 * La fuente de verdad es el audit log `report_generated` que se escribe al
 * emitir cada informe. Sin auth, con throttle (el código de 48 bits no es
 * enumerable en la práctica).
 */
class ReportVerifyController extends Controller
{
    public function __invoke(?string $code = null)
    {
        // Página pública sin usuario: el idioma sale de ?lang, o del navegador
        // (Accept-Language), o el default del sistema. Multi-idioma (es/en).
        $lang = request('lang');
        if (!in_array($lang, ['es', 'en'], true)) {
            $lang = str_starts_with(strtolower((string) request()->server('HTTP_ACCEPT_LANGUAGE')), 'en') ? 'en' : config('app.locale', 'es');
        }
        app()->setLocale(in_array($lang, ['es', 'en'], true) ? $lang : 'es');

        // Acepta el código con o sin guiones, en cualquier caja (viene de un QR
        // o tipeado a mano desde la carátula impresa).
        $raw = (string) ($code ?? request('code', ''));
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $raw));
        $formatted = strlen($hex) === 12 ? implode('-', str_split($hex, 4)) : null;

        $log = null;
        if ($formatted) {
            $log = AuditLog::where('event', 'report_generated')
                ->where('new_values->verify_code', $formatted)
                ->latest('created_at')
                ->first();
        }

        $transformer = $log
            ? Transformer::withTrashed()->with('tenant:id,name')->find($log->auditable_id)
            : null;

        return view('share.verify-report', [
            'queried'     => $raw !== '',
            'found'       => $log !== null,
            'code'        => $formatted ?? ($raw !== '' ? strtoupper($raw) : null),
            'log'         => $log,
            'transformer' => $transformer,
        ]);
    }
}
