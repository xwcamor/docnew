<?php

namespace App\Jobs;

use App\Models\ReportInstance;
use App\Models\ReportRequest;
use App\Services\Reports\TransformerReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Congela los PDF de una solicitud APROBADA: renderiza cada informe UNA vez
 * (con las firmas de quienes aprobaron y los gráficos cacheados) y guarda los
 * bytes en disco. Toda descarga posterior sirve ese archivo — lo que se firmó
 * es lo que se entrega, aunque el trafo cambie después.
 *
 * Va en cola (queue:work) porque una flota puede tener decenas de informes y
 * dompdf tarda segundos por PDF. Mientras un informe no esté congelado, las
 * descargas caen al render en vivo (ventana de segundos tras aprobar: los
 * datos son los mismos que se aprobaron).
 *
 * Además engorda `snapshot` con las MUESTRAS usadas (array por prueba): el
 * registro de auditoría de qué datos respaldaron la firma. Ese array sobrevive
 * a la purga por retención (solo se purga el archivo, nunca el snapshot ni el
 * hash).
 */
class FreezeApprovedReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // flotas grandes: varios PDFs de segundos c/u

    public function __construct(public int $reportRequestId)
    {
    }

    public function handle(TransformerReportService $reports): void
    {
        $request = ReportRequest::find($this->reportRequestId);
        if (!$request || $request->status !== 'approved') {
            return;
        }

        $signers = $reports->approvedSigners($request);

        foreach ($request->instances()->where('status', 'approved')->get() as $instance) {
            if ($instance->frozen_path || !$instance->transformer) {
                continue; // idempotente: ya congelado o trafo eliminado
            }
            $this->freezeInstance($instance, $reports, $signers);
        }
    }

    protected function freezeInstance(ReportInstance $instance, TransformerReportService $reports, array $signers): void
    {
        $t = $instance->transformer;

        $bytes = $reports->pdf(
            $t,
            charts: [], // cae al caché de gráficos (o al render server-side)
            preparer: null,
            approvalSigners: $signers,
            reviewState: null,
        )->output();

        $path = "frozen-reports/{$instance->tenant_id}/{$instance->id}.pdf";
        Storage::disk('local')->put($path, $bytes);

        $instance->update([
            'frozen_path' => $path,
            'frozen_hash' => hash('sha256', $bytes),
            'frozen_at'   => now(),
            'snapshot'    => array_merge($instance->snapshot ?? [], [
                'samples' => $this->samplesArray($t),
            ]),
        ]);
    }

    /**
     * Array de auditoría: las muestras por prueba tal como estaban al congelar
     * (valores crudos + fecha). No es una receta para re-renderizar — es el
     * registro de QUÉ datos respaldaron la firma.
     */
    protected function samplesArray($t): array
    {
        $pick = fn ($rows, array $cols) => $rows->map(
            fn ($r) => collect($r->only(array_merge(['sample_date'], $cols)))
                ->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)
                ->all()
        )->values()->all();

        return [
            'cromas'  => $pick($t->chromatographicals()->orderBy('sample_date')->get(), ['h2', 'o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2']),
            'fiquis'  => $pick($t->fiquis()->orderBy('sample_date')->get(), ['rig', 'ten', 'acid', 'wat', 'pot', 'rig877', 'pot100']),
            'furanos' => $pick($t->furanos()->orderBy('sample_date')->get(), ['fal']),
            'fpot'    => $pick($t->fpots()->orderBy('sample_date')->get(), ['value']),
        ];
    }
}
