<?php

namespace App\Jobs\BusinessManagement\Transformers;

use App\Jobs\BusinessManagement\Transformers\Concerns\BuildsFleetQuery;
use App\Models\Customer;
use App\Models\Download;
use App\Models\Setting;
use App\Services\Reports\FleetReportData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reporte de flota en PDF: documento PRESENTABLE (portada con resumen + un
 * bloque por transformador con su diagnóstico textual, sin gráficos). dompdf
 * renderiza todo en RAM, así que se CAPEA (setting fleet_report.pdf_max_*,
 * default 50). Para el detalle completo de muchos trafos está el Excel.
 */
class GenerateFleetReportPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, BuildsFleetQuery;

    public int $timeout = 600;
    public int $tries = 2;

    protected string $locale;
    protected ?int $downloadId = null;

    private const RATING_KEY = [0 => 'muy_malo', 1 => 'malo', 2 => 'medio', 3 => 'bueno', 4 => 'muy_bueno'];

    public function __construct(protected int $userId, protected ?int $customerId = null)
    {
        $this->locale = app()->getLocale();
        $this->captureFleetScope($userId);

        $download = Download::create([
            'slug'       => Str::random(22),
            'user_id'    => $userId,
            'type'       => 'pdf',
            'filename'   => 'reporte-flota_' . now()->format('Y-m-d_H-i-s') . '.pdf',
            'path'       => '',
            'disk'       => 'local',
            'status'     => 'processing',
            'expires_at' => Download::computeExpiresAt(),
        ]);
        $this->downloadId = $download->id;
    }

    public function handle(): void
    {
        ini_set('memory_limit', config('transformers.export_job_memory_limit', '512M'));
        app()->setLocale($this->locale);

        $download = Download::find($this->downloadId);
        if (!$download) return;
        if ($download->status !== 'processing') {
            $download->update(['status' => 'processing', 'error_message' => null]);
        }

        try {
            $cap = Setting::getInt('fleet_report.pdf_max_transformers', 50);

            $total = $this->fleetQuery($this->customerId)->count();
            $transformers = $this->fleetQuery($this->customerId)
                ->with(['customer:id,name', 'transformerType:id,name'])
                ->orderBy('serial')
                ->limit($cap)
                ->get(['id', 'serial', 'tag', 'customer_id', 'transformer_type_id', 'voltage_kv', 'power_mva',
                       'manufacture_year', 'health_index', 'health_rating', 'health_trend', 'fault_type',
                       'ieee_condition', 'paper_dp']);

            $rows = app(FleetReportData::class)->summary($transformers);

            // Distribución de salud para la portada (sobre el set mostrado).
            $dist = [4 => 0, 3 => 0, 2 => 0, 1 => 0, 0 => 0, 'sin' => 0];
            foreach ($rows as $r) {
                $dist[$r['rating'] ?? 'sin']++;
            }

            $customerName = $this->customerId ? (Customer::find($this->customerId)?->name) : null;

            $pdf = Pdf::loadView('business_management.transformers.pdf.fleet_report', [
                'rows'         => $rows,
                'total'        => $total,
                'shown'        => count($rows),
                'capped'       => $total > count($rows),
                'cap'          => $cap,
                'dist'         => $dist,
                'customerName' => $customerName,
                'generatedAt'  => now()->format('Y-m-d H:i'),
                'ratingKey'    => self::RATING_KEY,
            ])->setPaper('a4', 'landscape');

            $path = 'downloads/' . pathinfo($download->filename, PATHINFO_FILENAME) . '.pdf';
            Storage::disk('local')->put($path, $pdf->output());

            $download->update(['status' => 'ready', 'path' => $path]);
        } catch (\Throwable $e) {
            $download->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            \Log::error(static::class . ' failed', ['download_id' => $this->downloadId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->downloadId) {
            Download::where('id', $this->downloadId)
                ->whereIn('status', ['processing', 'failed'])
                ->update(['status' => 'failed', 'error_message' => 'Job interrumpido: ' . substr($exception->getMessage(), 0, 200)]);
        }
    }
}
