<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use App\Models\Transformer;
use App\Models\User;
use App\Services\Diagnostics\ChromatographyEngine;
use App\Services\Diagnostics\HealthIndexService;
use App\Support\Transformers\TransformerShowPayload;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * TransformerReportService — genera el INFORME consolidado del transformador
 * (el PDF oficial) desde cualquier punto de entrada:
 *
 *  - App (TransformerController::report): con gráficos capturados por el
 *    navegador (echarts) y firma del preparador (el usuario que genera).
 *  - Portal compartido (PublicShareController::pdf): sin gráficos (no hay
 *    navegador logueado que los capture) y sin firma de preparador (el que
 *    descarga es el cliente externo — regla anti-suplantación); las firmas
 *    pre-autorizadas del flujo de firmantes SÍ salen.
 *
 * Toda emisión queda auditada (report_generated) con sus códigos — por eso
 * los PDFs descargados del portal también son verificables en /verify.
 */
class TransformerReportService
{
    public function __construct(
        private TransformerShowPayload $payload,
        private HealthIndexService $hi,
        private ChromatographyEngine $cromas,
        private ReportChartRenderer $chartRenderer,
        private ReportChartCache $chartCache,
    ) {
    }

    /**
     * Construye el PDF del informe y escribe el audit de emisión.
     *
     * @param array $charts      [{label, group, dataURL}] capturados por el front (puede ser vacío)
     * @param ?User $preparer    usuario que genera (estampa su firma); null = portal externo
     * @param ?string $generatedByName  nombre impreso en "Preparado por" (default: $preparer->name)
     * @param array $auditExtra  claves extra para new_values del audit (ej. source/share_id)
     */
    public function pdf(Transformer $transformer, array $charts = [], ?User $preparer = null, ?string $generatedByName = null, array $auditExtra = [], ?array $approvalSigners = null, ?string $reviewState = null): \Barryvdh\DomPDF\PDF
    {
        $transformer->loadMissing([
            'tenant:id,name,logo,address,report_disclaimer',
            'tenant.reportSigners.user:id,name,signature,auto_sign_reports',
            'oilType', 'transformerType', 'brand:id,name', 'tapChangerType:id,name',
            'connectionType:id,name', 'preservation:id,name',
            'customer:id,name,cod,address,country_id,logo', 'customer.country:id,name',
            'substation' => fn ($q) => $q->withTrashed()->with(['area' => fn ($a) => $a->withTrashed()->with(['location' => fn ($l) => $l->withTrashed()])]),
        ]);

        $data = $this->payload->build($transformer);
        $hi = $this->hi->evaluate($transformer)->toArray();

        // Norma aplicada en cromas: sale del rule_set resuelto (DATOS, no código).
        $std = $this->cromas->appliedStandard($transformer);
        $cromasStandard = $std ? trim($std['name'] . ($std['version'] ? ' (' . $std['version'] . ')' : '')) : null;

        // Límite típico por gas (tope de la banda score 1 = concentración típica IEC).
        $gasLimits = [];
        foreach (($data['cromasLimits'] ?? []) as $gas => $bands) {
            $b1 = collect($bands)->firstWhere('score', 1);
            $gasLimits[$gas] = $b1['to'] ?? null;
        }
        // Límite por parámetro fisicoquímico (dirección Máx/Mín según banda mejor).
        // OJO con los parámetros de modo "limit" (rig877, pot100): sus 3 bandas
        // son verde/ámbar/rojo donde el ámbar es la TOLERANCIA ±10% heredada del
        // viejo (solo colorea, no es norma). La cabecera del PDF debe decir el
        // LÍMITE DE LA NORMA (frontera del rojo: 25 kV / 5 %), no el borde verde
        // con tolerancia (27.5 / 4.5) — eso hacía parecer fuera de norma muestras
        // que IEEE C57.106 acepta. Los "scored" (4 bandas) siguen mostrando el
        // primer umbral real de la tabla (frontera de la banda mejor).
        $fiquisLimits = [];
        foreach (($data['fiquisLimits'] ?? []) as $key => $bands) {
            if (empty($bands)) {
                continue;
            }
            $first   = $bands[0];
            $last    = $bands[count($bands) - 1];
            $isLimit = count($bands) === 3 && (float) ($bands[1]['sev'] ?? -1) === 0.5;
            if ((float) ($first['sev'] ?? 1) === 0.0 && $first['to'] !== null) {
                // menor = mejor → "Máx". En modo limit, la norma es donde empieza el ROJO.
                $fiquisLimits[$key] = ['dir' => 'max', 'value' => (float) ($isLimit ? $last['from'] : $first['to'])];
            } elseif ((float) ($last['sev'] ?? 1) === 0.0) {
                // mayor = mejor → "Mín". En modo limit, la norma es donde termina el ROJO.
                $fiquisLimits[$key] = ['dir' => 'min', 'value' => (float) ($isLimit ? $first['to'] : $last['from'])];
            }
        }

        // Código de informe (referencia trazable serial+fecha) + huella de verificación.
        $reportCode = 'INF-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($transformer->serial ?: $transformer->tag ?: $transformer->id)) . '-' . now()->format('Ymd');
        $verifyCode = $this->verifyCode($transformer, $reportCode, $hi['index'] ?? null);

        // Firmantes del informe (bloque data-driven: relación + cargo + nombre).
        //  - Flujo de aprobación emitido: las firmas vienen de quienes APROBARON.
        //  - Caso normal: de los slots del workspace, con la regla uniforme — la
        //    IMAGEN se estampa solo si el dueño tiene firma cargada Y activó
        //    auto-firma; si no, sale solo nombre + cargo (constancia).
        // En estado "en revisión" no se estampa ninguna imagen (aún no firman).
        $signers = $approvalSigners !== null
            ? $approvalSigners
            : ($transformer->tenant?->reportSigners ?? collect())->map(fn ($s) => [
                'relation'  => __('approvals.relation.' . ($s->relation ?: 'approved')),
                'title'     => $s->title,
                'name'      => $s->displayName(),
                'signature' => ($reviewState === null && ($s->user?->auto_sign_reports ?? false))
                    ? $this->signatureDataUri($s->user) : null,
            ])->values()->all();

        // Gráficos del informe. Prioridad:
        //  1. Los que captura el navegador (echarts) cuando el usuario genera el
        //     informe logueado → se usan Y se cachean para el portal.
        //  2. En el portal (sin navegador): los MISMOS gráficos cacheados, para
        //     que el PDF salga idéntico al de la app.
        //  3. Si nunca se generó logueado (sin caché): render server-side GD.
        if (!empty($charts)) {
            $this->chartCache->store($transformer, $charts);
        } else {
            $charts = $this->chartCache->get($transformer)
                ?? $this->chartRenderer->charts(
                    $transformer, $data['cromas'], $data['fiquis'], $data['fiquisColumns'],
                    $data['cromasLimits'] ?? [], $data['fiquisLimits'] ?? [], $data['furanos']
                );
        }

        // Gauge (anillo) del HI para la carátula.
        $HEX = ['green' => '#1D7044', 'lime' => '#5AA82E', 'yellow' => '#E9A23B', 'orange' => '#E2661E', 'red' => '#C8281D'];
        $CK  = ['Muy Bueno' => 'muy_bueno', 'Bueno' => 'bueno', 'Medio' => 'medio', 'Malo' => 'malo', 'Muy Malo' => 'muy_malo'];
        $hiGauge = $this->gaugeDataUri(
            $hi['index'],
            $HEX[$hi['color'] ?? ''] ?? '#B6BCC4',
            $hi['index'] !== null ? round($hi['index']) . '%' : '—',
            ($hi['condition'] ?? null) !== null ? mb_strtoupper(\App\Support\Diagnostics\ConditionLabel::forCondition($hi['condition'])) : '',
        );

        $pdf = Pdf::loadView('business_management.transformers.pdf.report', [
            'transformer'  => $transformer,
            'hi'           => $hi,
            'cromas'       => $data['cromas'],
            'fiquis'       => $data['fiquis'],
            'fiquisColumns'=> $data['fiquisColumns'],
            'furanos'      => $data['furanos'],
            'fpots'        => $data['fpots'] ?? [],
            'summary'      => $data['diagnosisSummary'] ?? [],
            'dgaStatus'    => $data['cromasDgaStatus'] ?? null,
            'furanosTrend' => $data['furanosTrend'] ?? null,
            'tenant'       => $transformer->tenant,
            'tenantLogo'   => $this->logoDataUri($transformer->tenant?->logo),
            'customerLogo' => $this->logoDataUri($transformer->customer?->logo),
            'charts'       => $charts,
            'cromasStandard' => $cromasStandard,
            'gasLimits'    => $gasLimits,
            'fiquisLimits' => $fiquisLimits,
            // Fecha de emisión en la TZ del workspace (no la del servidor):
            // consistente lo descargue quien lo descargue (lab, portal).
            'generatedAt'  => now()->setTimezone($transformer->tenant?->timezone ?: config('app.timezone', 'UTC')),
            'generatedBy'  => $generatedByName ?? $preparer?->name,
            'signers'      => $signers,
            // 'in_review' → el PDF sale marcado EN REVISIÓN y sin firmas (es el
            // borrador que ven los aprobadores). null → informe normal/oficial.
            'reviewState'  => $reviewState,
            'reportCode'   => $reportCode,
            'verifyCode'   => $verifyCode,
            'verifyQr'     => $this->verifyQrDataUri($verifyCode),
            'hiGauge'      => $hiGauge,
        ]);

        // page_text() del footer (nº de página real) corre como PHP embebido.
        $pdf->setOption('isPhpEnabled', true);

        // Toda emisión queda auditada: es el rastro de los informes que salen
        // a clientes, y la fuente de verdad del portal de verificación.
        AuditLog::create([
            'user_id'        => $preparer?->id,
            'event'          => 'report_generated',
            'auditable_type' => Transformer::class,
            'auditable_id'   => $transformer->id,
            'module'         => 'transformers',
            'old_values'     => null,
            'new_values'     => array_merge([
                'report_code'  => $reportCode,
                'verify_code'  => $verifyCode,
                'health_index' => $hi['index'] === null ? null : round($hi['index'], 1),
                'approver'     => collect($signers)->pluck('name')->filter()->implode(', ') ?: null,
                'signers'      => collect($signers)->map(fn ($s) => [
                    'title' => $s['title'], 'name' => $s['name'], 'auto_signed' => $s['signature'] !== null,
                ])->all(),
            ], $auditExtra),
            'url'        => request()->fullUrl(),
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);

        return $pdf;
    }

    /**
     * El MISMO informe que el PDF, en .docx editable. Solo dos diferencias:
     * sin QR ni código de verificación, y sin NINGUNA imagen de firma — queda
     * la línea para firmar a mano. Ver TransformerReportWord.
     *
     * Reusa el mismo payload que el PDF, así que el contenido no se desincroniza.
     *
     * @return string  Ruta del .docx generado (temporal; el controlador lo envía
     *                 con deleteFileAfterSend).
     */
    public function word(Transformer $transformer, array $charts = [], ?User $preparer = null): string
    {
        $transformer->loadMissing([
            'tenant:id,name,logo,timezone,address,report_disclaimer',
            'tenant.reportSigners.user:id,name',
            'oilType', 'transformerType', 'brand:id,name',
            'customer:id,name,logo',
            'substation' => fn ($q) => $q->withTrashed(),
        ]);

        $data = $this->payload->build($transformer);
        $hi   = $this->hi->evaluate($transformer)->toArray();

        // Firmantes SIN imagen: la clave 'signature' ni se calcula acá. Un
        // archivo editable no puede portar una rúbrica.
        $signers = ($transformer->tenant?->reportSigners ?? collect())->map(fn ($s) => [
            'relation' => __('approvals.relation.' . ($s->relation ?: 'approved')),
            'title'    => $s->title,
            'name'     => $s->displayName(),
        ])->values()->all();

        // Mismos gráficos que el PDF: los del navegador si vinieron, si no los
        // cacheados, y como último recurso el render server-side.
        if (!empty($charts)) {
            $this->chartCache->store($transformer, $charts);
        } else {
            $charts = $this->chartCache->get($transformer)
                ?? $this->chartRenderer->charts(
                    $transformer, $data['cromas'], $data['fiquis'], $data['fiquisColumns'],
                    $data['cromasLimits'] ?? [], $data['fiquisLimits'] ?? [], $data['furanos']
                );
        }

        // Mismo anillo del HI y misma norma de cromas que la carátula del PDF.
        $HEX = ['green' => '#1D7044', 'lime' => '#5AA82E', 'yellow' => '#E9A23B', 'orange' => '#E2661E', 'red' => '#C8281D'];
        $hiGauge = $this->gaugeDataUri(
            $hi['index'],
            $HEX[$hi['color'] ?? ''] ?? '#B6BCC4',
            $hi['index'] !== null ? round($hi['index']) . '%' : '—',
            ($hi['condition'] ?? null) !== null ? mb_strtoupper(\App\Support\Diagnostics\ConditionLabel::forCondition($hi['condition'])) : '',
        );
        $std = $this->cromas->appliedStandard($transformer);
        $cromasStandard = $std ? trim($std['name'] . ($std['version'] ? ' (' . $std['version'] . ')' : '')) : null;

        $tz = $transformer->tenant?->timezone ?: config('app.timezone', 'UTC');
        $ruta = (new \App\Exports\BusinessManagement\Transformers\TransformerReportWord())->generate(
            transformer: $transformer,
            data:        $data,
            hi:          $hi,
            charts:      $charts,
            signers:     $signers,
            generatedBy: $preparer?->name ?? '—',
            generatedAt: now()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
            tenantLogo:  $this->logoDataUri($transformer->tenant?->logo),
            hiGauge:     $hiGauge,
            cromasStandard: $cromasStandard,
            gasLimits:      $this->limitesDeGas($data),
            fiquisLimits:   $this->limitesFiqui($data),
            // El PDF lo muestra en el pie; no es el código de verificación (ese
            // sí queda fuera, junto con el QR).
            reportCode:     'INF-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($transformer->serial ?: $transformer->tag ?: $transformer->id)) . '-' . now()->format('Ymd'),
        );

        // Evento propio: esta copia no lleva códigos ni firmas estampadas, así
        // que mezclarla con report_generated ensuciaría el rastro de los
        // informes verificables que salieron a un cliente.
        AuditLog::create([
            'user_id'        => $preparer?->id,
            'event'          => 'report_draft_generated',
            'auditable_type' => Transformer::class,
            'auditable_id'   => $transformer->id,
            'module'         => 'transformers',
            'old_values'     => null,
            'new_values'     => [
                'format'       => 'docx',
                'health_index' => $hi['index'] === null ? null : round($hi['index'], 1),
            ],
            'url'        => request()->fullUrl(),
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);

        return $ruta;
    }

    /** Límite típico por gas (tope de la banda score 1), igual que el PDF. */
    private function limitesDeGas(array $data): array
    {
        $out = [];
        foreach (($data['cromasLimits'] ?? []) as $gas => $bandas) {
            $b1 = collect($bandas)->firstWhere('score', 1);
            if (($b1['to'] ?? null) !== null) {
                $out[$gas] = $b1['to'];
            }
        }

        return $out;
    }

    /** Límite por parámetro fisicoquímico con su dirección (Máx/Mín). */
    private function limitesFiqui(array $data): array
    {
        $out = [];
        foreach (($data['fiquisLimits'] ?? []) as $key => $bandas) {
            if (empty($bandas)) {
                continue;
            }
            $primera = $bandas[0];
            $ultima  = $bandas[count($bandas) - 1];
            $esLimit = count($bandas) === 3 && (float) ($bandas[1]['sev'] ?? -1) === 0.5;

            if ((float) ($primera['sev'] ?? 1) === 0.0 && $primera['to'] !== null) {
                $out[$key] = ['dir' => 'max', 'value' => (float) ($esLimit ? $ultima['from'] : $primera['to'])];
            } elseif ((float) ($ultima['sev'] ?? 1) === 0.0) {
                $out[$key] = ['dir' => 'min', 'value' => (float) ($esLimit ? $primera['to'] : $ultima['from'])];
            }
        }

        return $out;
    }

    /**
     * Prepara un trafo para enviar a aprobación: códigos del informe + un
     * snapshot compacto (HI, condición, fecha) para auditar QUÉ se aprobó, y
     * cachea los gráficos capturados (si los hay) para el PDF posterior.
     */
    public function approvalData(Transformer $transformer, array $charts = []): array
    {
        $transformer->loadMissing(['oilType:id,name,code', 'transformerType:id,name,code']);
        $hi = $this->hi->evaluate($transformer)->toArray();
        $reportCode = 'INF-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($transformer->serial ?: $transformer->tag ?: $transformer->id)) . '-' . now()->format('Ymd');
        $verifyCode = $this->verifyCode($transformer, $reportCode, $hi['index'] ?? null);

        if (!empty($charts)) {
            $this->chartCache->store($transformer, $charts);
        }

        return [
            'report_code' => $reportCode,
            'verify_code' => $verifyCode,
            'snapshot'    => [
                'serial'       => $transformer->serial ?: $transformer->tag,
                'health_index' => $hi['index'] === null ? null : round($hi['index'], 1),
                'condition'    => $hi['condition'] ?? null,
                'color'        => $hi['color'] ?? null,
                'frozen_at'    => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Firmantes para el PDF de un informe YA EMITIDO por el flujo de aprobación:
     * relación + cargo + nombre congelados, y la firma estampada al aprobar.
     */
    public function approvedSigners(\App\Models\ReportRequest $request): array
    {
        // Fecha de aprobación en la TZ del workspace (consistente con generatedAt).
        $tz = $request->tenant?->timezone ?: config('app.timezone', 'UTC');

        return $request->approvals()->where('status', 'approved')->orderBy('id')->get()->map(fn ($a) => [
            'relation'    => __('approvals.relation.' . ($a->relation ?: 'approved')),
            'title'       => $a->title,
            'name'        => User::withTrashed()->find($a->user_id)?->name,
            'signature'   => $a->signature,
            // Acto de aprobación real (clic + auditoría): si no hay imagen cargada,
            // el PDF estampa "Aprobado electrónicamente: [fecha]" en vez de dejar la
            // línea en blanco (que sugeriría "sin firmar" en una entrega digital).
            'approved_at' => $a->acted_at?->setTimezone($tz)->format('d-m-Y'),
        ])->values()->all();
    }

    /**
     * Huella de verificación del informe: 12 hex (XXXX-XXXX-XXXX) derivada de
     * identidad del informe + APP_KEY. Sello de integridad legible.
     */
    private function verifyCode(Transformer $transformer, string $reportCode, ?float $hiIndex): string
    {
        $payload = implode('|', [
            $transformer->tenant_id, $transformer->id, $transformer->serial ?: $transformer->tag,
            $reportCode, $hiIndex === null ? '' : round($hiIndex, 1), now()->format('Y-m-d'),
        ]);
        $hex = strtoupper(substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 12));
        return implode('-', str_split($hex, 4));
    }

    /** QR → portal público de verificación (/verify/{code}). Null si falla la lib. */
    private function verifyQrDataUri(string $verifyCode): ?string
    {
        try {
            return \Endroid\QrCode\Builder\Builder::create()
                ->writer(new \Endroid\QrCode\Writer\PngWriter())
                ->data(route('report.verify', ['code' => $verifyCode]))
                ->size(220)
                ->margin(6)
                ->build()
                ->getDataUri();
        } catch (\Throwable) {
            return null; // el informe sale igual, sin QR
        }
    }

    /**
     * Gauge del índice de salud (anillo GD, PNG data-URI). dompdf no rinde
     * SVG/echarts confiable; GD sí es determinístico. Se dibuja a 3x.
     */
    private function gaugeDataUri(?float $pct, string $hexColor, string $centerText, string $subText): ?string
    {
        try {
            $s = 330; $cx = $s / 2; $cy = $s / 2; $thick = 34;
            $im = imagecreatetruecolor($s, $s);
            imagesavealpha($im, true);
            imagealphablending($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));

            $rgb = sscanf(ltrim($hexColor, '#'), '%02x%02x%02x');
            $col   = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
            $track = imagecolorallocate($im, 232, 236, 239);
            $white = imagecolorallocate($im, 255, 255, 255);

            imagefilledellipse($im, (int) $cx, (int) $cy, $s, $s, $track);
            if ($pct !== null && $pct > 0) {
                $end = -90 + (int) round(360 * min(100, $pct) / 100);
                imagefilledarc($im, (int) $cx, (int) $cy, $s, $s, -90, $end, $col, IMG_ARC_PIE);
            }
            imagefilledellipse($im, (int) $cx, (int) $cy, $s - $thick * 2, $s - $thick * 2, $white);

            $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
            if (is_file($font)) {
                $dark = imagecolorallocate($im, 31, 41, 55);
                $bb = imagettfbbox(64, 0, $font, $centerText);
                imagettftext($im, 64, 0, (int) ($cx - ($bb[2] - $bb[0]) / 2), (int) ($cy + 18), $dark, $font, $centerText);
                if ($subText !== '') {
                    $bb = imagettfbbox(20, 0, $font, $subText);
                    imagettftext($im, 20, 0, (int) ($cx - ($bb[2] - $bb[0]) / 2), (int) ($cy + 62), $col, $font, $subText);
                }
            }

            ob_start();
            imagepng($im);
            $png = ob_get_clean();
            imagedestroy($im);
            return 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable) {
            return null; // sin gauge, el informe sale igual
        }
    }

    /** Firma del usuario (disk local PRIVADO) → data-URI. Null si no tiene. */
    private function signatureDataUri(?User $user): ?string
    {
        if (!$user?->signature) {
            return null;
        }
        try {
            $disk = Storage::disk('local');
            if (!$disk->exists($user->signature)) {
                return null;
            }
            $raw  = $disk->get($user->signature);
            $mime = $disk->mimeType($user->signature);
            // dompdf no rinde WEBP → convertir a PNG (si no, sale en blanco).
            if (str_contains((string) $mime, 'webp') && function_exists('imagecreatefromstring')) {
                $img = @imagecreatefromstring($raw);
                if ($img !== false) {
                    imagesavealpha($img, true);
                    ob_start();
                    imagepng($img);
                    $raw = ob_get_clean();
                    imagedestroy($img);
                    $mime = 'image/png';
                }
            }
            return 'data:' . $mime . ';base64,' . base64_encode($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Logo (ruta en disco público) → data-URI para embeber en el PDF. */
    private function logoDataUri(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://', 'data:'])) {
            return $path;
        }
        try {
            $disk = Storage::disk('public');
            return 'data:' . $disk->mimeType($path) . ';base64,' . base64_encode($disk->get($path));
        } catch (\Throwable) {
            return null;
        }
    }
}
