<?php

namespace App\Jobs\BusinessManagement\Transformers;

use App\Exports\BusinessManagement\Transformers\FleetReportExport;
use App\Models\Chromatographical;
use App\Models\Download;
use App\Models\Fiqui;
use App\Models\Fpot;
use App\Models\Furano;
use App\Models\Transformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte de FLOTA en Excel (un libro con pestañas): Resumen + una pestaña por
 * prueba con TODAS las muestras crudas + Diagnósticos textuales. Es el formato
 * recomendado para "todos los datos" (PDF/Word no aguantan el volumen).
 *
 * Scope-safe: tenant + clientes asignados capturados al construir (el worker
 * no tiene sesión). Filtro opcional por cliente (`customer_id`).
 */
class GenerateFleetReportExcelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;

    protected string $locale;
    protected ?int $tenantId = null;
    protected array $allowedCustomerIds = [];
    protected ?int $downloadId = null;

    /** Rating 0..4 → clave i18n de condición. */
    private const RATING_KEY = [0 => 'muy_malo', 1 => 'malo', 2 => 'medio', 3 => 'bueno', 4 => 'muy_bueno'];

    /**
     * @param  int[]|null  $transformerIds  Selección concreta del índice. Null =
     *   toda la flota del alcance. A diferencia de compartir, acá SÍ se admiten
     *   varios clientes: es un archivo de trabajo interno, no un entregable.
     */
    public function __construct(protected int $userId, protected ?int $customerId = null, protected ?array $transformerIds = null)
    {
        $this->locale = app()->getLocale();
        $user = \App\Models\User::find($userId);
        $this->tenantId           = $user?->tenant_id;
        $this->allowedCustomerIds = $user?->assignedCustomerIds() ?? [];

        $download = Download::create([
            'slug'       => Str::random(22),
            'user_id'    => $userId,
            'type'       => 'excel',
            'filename'   => 'reporte-flota_' . now()->format('Y-m-d_H-i-s') . '.xlsx',
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
            $path = 'downloads/' . pathinfo($download->filename, PATHINFO_FILENAME) . '.xlsx';
            Excel::store(new FleetReportExport($this->buildSheets()), $path, 'local');

            $download->update([
                'status' => 'ready',
                'path'   => $path,
            ]);
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

    /** Query base de transformadores del scope (tenant + clientes asignados + cliente). */
    protected function fleetQuery()
    {
        $q = Transformer::query()->withoutGlobalScope('tenant');
        if ($this->tenantId !== null) {
            $q->where('transformers.tenant_id', $this->tenantId);
        }
        if (!empty($this->allowedCustomerIds)) {
            $q->whereIn('customer_id', $this->allowedCustomerIds);
        }
        if ($this->customerId) {
            $q->where('customer_id', $this->customerId);
        }
        // La selección se APILA sobre los filtros de alcance (tenant + clientes
        // asignados): nunca los ensancha.
        if (!empty($this->transformerIds)) {
            $q->whereIn('transformers.id', $this->transformerIds);
        }
        return $q;
    }

    /**
     * Diagnóstico de cada muestra, por prueba: [prueba => [muestra_id => fila]].
     *
     * Se construye con TransformerShowPayload, el mismo que usan la ficha, el
     * PDF y el Word. Es un build por trafo: el job es asíncrono, y la
     * alternativa (leer columnas que nadie llena) daba una columna vacía —
     * `dgaf_score`, `condition` y `furanos.dp` están en cero de cero filas.
     *
     * @param  \Illuminate\Support\Collection  $transformers
     * @return array<string,array<int,array>>
     */
    protected function diagnosticosPorMuestra($transformers): array
    {
        $payload = app(\App\Support\Transformers\TransformerShowPayload::class);
        $out = ['cromas' => [], 'fiquis' => [], 'furanos' => [], 'fpots' => []];

        foreach ($transformers as $t) {
            try {
                $data = $payload->build($t);
            } catch (\Throwable $e) {
                continue;   // un trafo con datos rotos no puede tumbar el libro
            }
            foreach (array_keys($out) as $prueba) {
                foreach (($data[$prueba] ?? []) as $m) {
                    if (isset($m['id'])) {
                        $out[$prueba][$m['id']] = $m;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Tendencia del índice, con la MISMA etiqueta del dashboard. Sin traducción
     * conocida se devuelve el valor crudo: es mejor un enum raro que perder el
     * dato.
     */
    private function etiquetaTendencia(?string $v): string
    {
        if (!$v) {
            return '—';
        }
        $k = 'transformers.fleet.trend_' . $v;
        return __($k) === $k ? $v : __($k);
    }

    /** Tipo de falla (familia Duval), con la misma etiqueta del dashboard. */
    private function etiquetaFalla(?string $v): string
    {
        if (!$v) {
            return '—';
        }
        $k = 'dashboard.fleet.fault_' . $v;
        return __($k) === $k ? $v : __($k);
    }

    /** Condición de una muestra ya diagnosticada, traducida. */
    private function condicionDe(array $mapa, ?int $id): string
    {
        $c = $mapa[$id]['condition'] ?? null;
        return $c ? \App\Support\Diagnostics\ConditionLabel::forCondition($c) : '—';
    }

    /** Arma las pestañas del libro. */
    protected function buildSheets(): array
    {
        // SIN lista de columnas a propósito. Estos modelos van al
        // TransformerShowPayload, que necesita el trafo COMPLETO — sobre todo el
        // tipo de aceite. Al seleccionar solo las columnas del resumen,
        // `oil_type_id` quedaba fuera, el payload no resolvía el aceite y TODAS
        // las muestras salían sin diagnóstico: "Sin reglas" en cromatografía y
        // "—" en fisicoquímico, en cada fila del libro.
        $transformers = $this->fleetQuery()
            ->with(['customer:id,name', 'transformerType:id,name', 'oilType'])
            ->orderBy('serial')
            ->get();

        $ids = $transformers->pluck('id')->all();
        $serialById = $transformers->mapWithKeys(fn ($t) => [$t->id => ($t->serial ?: ($t->tag ?: '#' . $t->id))])->all();
        $cond = fn ($r) => \App\Support\Diagnostics\ConditionLabel::for($r);

        // ── Resumen: 1 fila por trafo ──
        $resumen = $transformers->map(fn ($t) => [
            $t->serial ?: '—', $t->tag ?: '—', $t->customer?->name ?? '—', $t->transformerType?->name ?? '—',
            $t->voltage_kv, $t->power_mva, $t->manufacture_year,
            $t->health_index !== null ? round($t->health_index, 1) : '—',
            $cond($t->health_rating),
            // Tendencia y tipo de falla son enums internos: en el libro salían
            // crudos en inglés ("worsening", "thermal") al lado de columnas ya
            // traducidas.
            $this->etiquetaTendencia($t->health_trend),
            $this->etiquetaFalla($t->fault_type),
            $t->ieee_condition ?? '—',
            $t->paper_dp ?? '—',
        ])->all();

        // Condición POR MUESTRA. Las columnas dgaf_condition/condition de la BD
        // están vacías: el diagnóstico se calcula al vuelo con los motores y
        // nunca se persistió ahí, así que leerlas daba "—" en todas las filas.
        // Se resuelve con el MISMO payload que alimenta la ficha, el PDF y el
        // Word — una sola fuente de verdad.
        $diag = $this->diagnosticosPorMuestra($transformers);

        // ── Pruebas (todas las muestras crudas) ──
        // Se quitó la columna DGAF: sale de `dgaf_score`, que está vacía en las
        // 13 060 filas de la base (el motor lo calcula al vuelo y nunca lo
        // persiste ahí). Además es el promedio ponderado interno del motor, no
        // una medición: la columna Condición ya dice a dónde llegó.
        $cromas = Chromatographical::withoutGlobalScopes()->whereIn('transformer_id', $ids)
            ->orderBy('transformer_id')->orderByDesc('sample_date')
            ->get()->map(fn ($s) => [
                $serialById[$s->transformer_id] ?? '—', optional($s->sample_date)->format('Y-m-d'),
                $s->h2, $s->o2, $s->n2, $s->ch4, $s->co, $s->co2, $s->c2h4, $s->c2h6, $s->c2h2,
                $this->condicionDe($diag['cromas'], $s->id),
            ])->all();

        // El DP sale del diagnóstico, no de la columna `dp`: esa está vacía en
        // las 2 586 filas y dejaba la columna entera en blanco.
        $furanos = Furano::withoutGlobalScopes()->whereIn('transformer_id', $ids)
            ->orderBy('transformer_id')->orderByDesc('sample_date')
            ->get()->map(fn ($s) => [
                $serialById[$s->transformer_id] ?? '—', optional($s->sample_date)->format('Y-m-d'),
                $s->fal, $s->hme, $s->ace, $s->mfu, $s->fua,
                $diag['furanos'][$s->id]['dp'] ?? '—',
                $this->condicionDe($diag['furanos'], $s->id),
            ])->all();

        $fiquis = Fiqui::withoutGlobalScopes()->whereIn('transformer_id', $ids)
            ->orderBy('transformer_id')->orderByDesc('sample_date')
            ->get()->map(fn ($s) => [
                $serialById[$s->transformer_id] ?? '—', optional($s->sample_date)->format('Y-m-d'),
                $s->rig, $s->ten, $s->acid, $s->wat, $s->pot, $s->rig877, $s->pot100,
                $this->condicionDe($diag['fiquis'], $s->id),
            ])->all();

        $fpots = Fpot::withoutGlobalScopes()->whereIn('transformer_id', $ids)
            ->orderBy('transformer_id')->orderByDesc('sample_date')
            ->get()->map(fn ($s) => [
                $serialById[$s->transformer_id] ?? '—', optional($s->sample_date)->format('Y-m-d'),
                $s->value, $s->temperature, $this->condicionDe($diag['fpots'], $s->id),
            ])->all();

        $L = fn ($k) => __('transformers.fleet_report.' . $k);
        // Etiquetas de fisicoquímico: fuente ÚNICA en fiquis.php (el usuario
        // fijó esos nombres; duplicarlos acá los dejaba desactualizados).
        //
        // Se usa el nombre COMPLETO con la condición de ensayo pegada
        // ("Rigidez Dieléctrica 2.54 mm"): en una hoja suelta no hay tooltip ni
        // segunda línea de cabecera, y con el nombre corto salían dos columnas
        // "Rigidez Dieléctrica" y dos "Factor de Potencia" indistinguibles.
        // Debajo, la norma y la unidad.
        $F = function ($k) {
            $nombre = __('fiquis.' . $k . '_full');
            if ($nombre === 'fiquis.' . $k . '_full') {
                $nombre = __('fiquis.' . $k);
            }
            $medida = __('fiquis.' . $k . '_head');
            if ($medida === 'fiquis.' . $k . '_head') {
                $medida = __('fiquis.' . $k . '_unit');
            }
            return $nombre . "\n" . __('fiquis.' . $k . '_astm') . "\n" . $medida;
        };
        // Los gases se identifican por su símbolo; la unidad es común a todos.
        $G = fn ($sym) => $sym . "\n" . __('cromas.gas_unit');

        return [
            ['title' => $L('sheet_summary'), 'rows' => $resumen, 'headings' => [
                $L('h_serial'), $L('h_tag'), $L('h_customer'), $L('h_type'), $L('h_voltage'), $L('h_power'),
                $L('h_year'), $L('h_hi'), $L('h_condition'), $L('h_trend'), $L('h_fault'), $L('h_ieee'), $L('h_dp'),
            ]],
            ['title' => $L('sheet_cromas'), 'rows' => $cromas, 'headings' => array_merge(
                [$L('h_serial'), $L('h_date')],
                array_map($G, ['H₂', 'O₂', 'N₂', 'CH₄', 'CO', 'CO₂', 'C₂H₄', 'C₂H₆', 'C₂H₂']),
                [$L('h_condition')],
            )],
            ['title' => $L('sheet_furanos'), 'rows' => $furanos, 'headings' => [
                $L('h_serial'), $L('h_date'),
                "2-FAL\nppb", "5-HMF\nppb", "2-ACF\nppb", "5-MEF\nppb", "2-FOL\nppb",
                $L('h_dp'), $L('h_condition'),
            ]],
            ['title' => $L('sheet_fiquis'), 'rows' => $fiquis, 'headings' => [
                // Etiquetas desde fiquis.php (fuente única). Tenerlas duplicadas
                // acá dejaba el Excel con nombres viejos: decía "Acidez" cuando
                // el módulo ya decía "Número Ácido".
                $L('h_serial'), $L('h_date'),
                $F('rig'), $F('ten'), $F('acid'), $F('wat'), $F('pot'), $F('rig877'), $F('pot100'),
                $L('h_condition'),
            ]],
            ['title' => $L('sheet_fpot'), 'rows' => $fpots, 'headings' => [
                $L('h_serial'), $L('h_date'), $L('h_fpot_value'), $L('h_temp'), $L('h_condition'),
            ]],
        ];
    }
}
