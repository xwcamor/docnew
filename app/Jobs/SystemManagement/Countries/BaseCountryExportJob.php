<?php

namespace App\Jobs\SystemManagement\Countries;

use App\Models\Download;
use App\Models\Country;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base abstracta para los 4 jobs de export (CSV / Excel / PDF / Word).
 * Concentra la duplicación común: creación de Download, locale, manejo de
 * errores, buildQuery con scope, generateFilename, buildFiltersSummary.
 *
 * Subclase debe implementar:
 *   - `protected string $type` y `protected string $extension`
 *   - `executeExport(Download $download): void` — renderiza el archivo y
 *     deja `$download->status = 'ready'` + `$download->path` seteado.
 */
abstract class BaseCountryExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout en segundos. 10 min cubre el peor caso de 1M filas en CSV con
     * chunkById + I/O lento. Sin esto, un export colgado queda `processing`
     * para siempre.
     */
    public int $timeout = 600;

    /**
     * Reintenta 2 veces ante fallas transitorias (memoria temporal, disco
     * lleno momentáneo, blip de red al guardar). Failures definitivos (sintaxis,
     * datos corruptos) terminan en el `failed()` handler abajo.
     */
    public int $tries = 2;

    /** Override en la subclase: 'csv' | 'excel' | 'pdf' | 'word' (debe matchear el enum de downloads.type). */
    protected string $type;

    /** Override en la subclase: extensión del archivo final (sin punto). */
    protected string $extension;

    protected int $userId;
    protected array $options;
    protected string $locale;
    /** TZ del user que disparó el export — fechas se convierten al display. */
    protected string $userTimezone;
    protected ?Download $download = null;

    /**
     * ID del Download persistido en el primer try. Se preserva entre retries
     * porque las props primitivas sí se serializan en el queue. Permite que
     * `failed()` sepa exactamente cuál Download marcar como failed, aunque
     * el mismo usuario tenga otros exports en paralelo.
     */
    protected ?int $downloadId = null;

    public function __construct(int $userId, array $options = [])
    {
        $this->userId  = $userId;
        $this->options = $options;
        $this->locale  = app()->getLocale();
        $user = \App\Models\User::withoutGlobalScopes()->find($userId);
        $this->userTimezone = \App\Support\Tz::for($user);

        // Pre-creamos el Download row aquí (en el web request, antes de que el
        // job entre al queue). Así el inbox del usuario ve el item en
        // status='processing' al instante — el bell arranca su polling sin
        // esperar a que el worker tome el job. Si el worker tarda 30s en
        // tomar el job, el usuario igual ve "Generando..." en su bell.
        $this->download = Download::create([
            'slug'       => Str::random(22),
            'user_id'    => $userId,
            'type'       => $this->type,
            'filename'   => $this->generateFilename(),
            'path'       => '',
            'disk'       => 'local',
            'status'     => 'processing',
            'expires_at' => Download::computeExpiresAt(),
        ]);
        $this->downloadId = $this->download->id;
    }

    public function handle(): void
    {
        // Techo de memoria explícito para que un export pesado no se coma toda
        // la RAM del worker. Sin esto hereda el `memory_limit` global de PHP
        // (puede ser muy bajo o ilimitado). Configurable via env.
        ini_set('memory_limit', config('countries.export_job_memory_limit', '512M'));

        app()->setLocale($this->locale);

        // El Download ya fue creado en __construct. Lo recargamos en el
        // worker (el `$this->download` serializado podría estar stale).
        $this->download = Download::find($this->downloadId);
        if (!$this->download) return; // el usuario lo borró antes de que arrancara

        // Si es un retry, el status puede estar en 'failed' — reseteamos a
        // 'processing' y limpiamos el error anterior antes de re-intentar.
        if ($this->download->status !== 'processing') {
            $this->download->update(['status' => 'processing', 'error_message' => null]);
        }

        try {
            $this->executeExport($this->download);
        } catch (\Throwable $e) {
            $this->download->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            \Log::error(static::class . ' failed', [
                'download_id' => $this->downloadId,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            // Re-throw para que Laravel queue reintente según `$tries`.
            // Si ya gastamos los tries → llama `failed()` abajo.
            throw $e;
        }
    }

    /**
     * Se llama cuando el job falla DEFINITIVAMENTE (tries agotados, timeout,
     * kill -9 por OOM, supervisor restart). Garantiza que el Download no
     * quede en `processing` para siempre incluso si el handle() no pudo
     * catchear la excepción (worker matado externamente).
     */
    public function failed(\Throwable $exception): void
    {
        if ($this->downloadId) {
            Download::where('id', $this->downloadId)
                ->whereIn('status', ['processing', 'failed'])
                ->update([
                    'status'        => 'failed',
                    'error_message' => 'Job interrumpido: ' . substr($exception->getMessage(), 0, 200),
                ]);
        }

        \Log::error(static::class . ' permanently failed', [
            'download_id' => $this->downloadId,
            'user_id'     => $this->userId,
            'error'       => $exception->getMessage(),
        ]);
    }

    /** Subclase: renderiza el archivo, persiste en disk, marca Download como ready. */
    abstract protected function executeExport(Download $download): void;

    /**
     * Apply scope: filtered / selected / all. Eager-load creator solo si la
     * columna está pedida (los formatos visuales suelen pedirla; CSV solo
     * si va en `columns`).
     */
    protected function buildQuery()
    {
        $scope   = $this->options['scope'] ?? 'filtered';
        $base    = Country::query();
        $cols    = $this->options['columns'] ?? ['creator'];

        if (in_array('creator', $cols, true)) {
            $base->with('creator:id,name');
        }
        if (in_array('region', $cols, true)) {
            $base->with('region:id,name');
        }
        if (in_array('default_locale', $cols, true)) {
            $base->with('defaultLocale:id,code,name');
        }

        if ($scope === 'selected' && !empty($this->options['selected_ids'])) {
            return $base->whereIn('id', $this->options['selected_ids']);
        }
        if ($scope === 'all') {
            return $base;
        }
        return $base->filter($this->options['filters'] ?? []);
    }

    /**
     * Lista flat de filtros activos para la portada de PDF/Word. CSV/Excel
     * la ignoran (devuelven Excel-styled headers, no portada).
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function buildFiltersSummary(): array
    {
        $f = $this->options['filters'] ?? [];
        $out = [];

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $out[] = ['label' => __('countries.name'),  'value' => implode(', ', $names)];
        }
        if (!empty($f['iso_code'])) {
            $codes = is_array($f['iso_code']) ? $f['iso_code'] : [$f['iso_code']];
            $out[] = ['label' => __('countries.iso_code'), 'value' => implode(', ', $codes)];
        }
        if (!empty($f['currency'])) {
            $cur = is_array($f['currency']) ? $f['currency'] : [$f['currency']];
            $out[] = ['label' => __('countries.currency'), 'value' => implode(', ', $cur)];
        }
        if (!empty($f['region_id'])) {
            $ids = is_array($f['region_id']) ? $f['region_id'] : [$f['region_id']];
            $names = \App\Models\Region::whereIn('id', $ids)->pluck('name')->all();
            $out[] = ['label' => __('countries.region'), 'value' => implode(', ', $names)];
        }
        if (!empty($f['default_locale_id'])) {
            $ids = is_array($f['default_locale_id']) ? $f['default_locale_id'] : [$f['default_locale_id']];
            $codes = \App\Models\Locale::whereIn('id', $ids)->pluck('code')->all();
            $out[] = ['label' => __('countries.default_locale'), 'value' => implode(', ', $codes)];
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $bool = filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN);
            $out[] = ['label' => __('countries.is_active'), 'value' => $bool ? __('global.active') : __('global.inactive')];
        }
        if (!empty($f['created_from']) || !empty($f['created_to'])) {
            $out[] = ['label' => __('global.created_at'), 'value' => ($f['created_from'] ?? '…') . ' → ' . ($f['created_to'] ?? '…')];
        }
        if (!empty($f['updated_from']) || !empty($f['updated_to'])) {
            $out[] = ['label' => __('global.updated_at'), 'value' => ($f['updated_from'] ?? '…') . ' → ' . ($f['updated_to'] ?? '…')];
        }
        if (!empty($f['id_from']) || !empty($f['id_to'])) {
            $out[] = ['label' => 'ID', 'value' => ($f['id_from'] ?? '…') . ' – ' . ($f['id_to'] ?? '…')];
        }

        return $out;
    }

    protected function generateFilename(): string
    {
        $base = Str::slug($this->options['title'] ?? __('countries.export_filename'));
        return $base . '_' . now()->format('Y-m-d_H-i-s') . '.' . $this->extension;
    }
}
