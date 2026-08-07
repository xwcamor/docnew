<?php

namespace App\Jobs\BusinessManagement\Transformers;

use App\Jobs\BusinessManagement\Transformers\Concerns\BuildsFleetQuery;
use App\Models\Download;
use App\Services\Reports\FleetReportData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reporte de flota en CSV: tabla plana, UNA fila por transformador (resumen:
 * identidad + índice de salud + condición por prueba). El detalle crudo por
 * muestra vive en el Excel; el CSV es para análisis rápido / tablas dinámicas.
 */
class GenerateFleetReportCsvJob implements ShouldQueue
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
            'type'       => 'csv',
            'filename'   => 'reporte-flota_' . now()->format('Y-m-d_H-i-s') . '.csv',
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
            $transformers = $this->fleetQuery($this->customerId)
                ->with(['customer:id,name', 'transformerType:id,name'])
                ->orderBy('serial')
                ->get(['id', 'serial', 'tag', 'customer_id', 'transformer_type_id', 'voltage_kv', 'power_mva',
                       'manufacture_year', 'health_index', 'health_rating', 'health_trend', 'fault_type',
                       'ieee_condition', 'paper_dp']);

            $rows = app(FleetReportData::class)->summary($transformers);
            $L = fn ($k) => __('transformers.fleet_report.' . $k);
            $cond = fn ($r) => \App\Support\Diagnostics\ConditionLabel::for($r);

            $fh = fopen('php://temp', 'r+');
            fwrite($fh, "\xEF\xBB\xBF"); // BOM para que Excel abra UTF-8 bien.
            fputcsv($fh, [
                $L('h_serial'), $L('h_tag'), $L('h_customer'), $L('h_type'), $L('h_voltage'), $L('h_power'),
                $L('h_year'), $L('h_hi'), $L('h_condition'), $L('h_trend'), $L('h_fault'), $L('h_ieee'), $L('h_dp'),
                $L('sheet_cromas'), $L('sheet_furanos'), $L('sheet_fiquis'), $L('sheet_fpot'),
            ], ',', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($fh, [
                    $r['serial'], $r['tag'], $r['customer'], $r['type'], $r['voltage_kv'], $r['power_mva'],
                    $r['year'], $r['hi'] ?? '—', $cond($r['rating']), $r['trend'] ?? '—',
                    $r['fault_type'] ?? '—', $r['ieee'] ?? '—', $r['dp'] ?? '—',
                    $r['cromas'] ?? '—', $r['furanos'] ?? '—', $r['fiquis'] ?? '—', $r['fpot'] ?? '—',
                ], ',', '"', '\\');
            }
            rewind($fh);
            $csv = stream_get_contents($fh);
            fclose($fh);

            $path = 'downloads/' . pathinfo($download->filename, PATHINFO_FILENAME) . '.csv';
            Storage::disk('local')->put($path, $csv);

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
