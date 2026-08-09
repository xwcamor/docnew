<?php

namespace App\Jobs\BusinessManagement\WorkPlans;

use App\Models\WorkPlan;
use App\Models\Download;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base abstracta para los 4 jobs de export (CSV / Excel / PDF / Word).
 * Concentra la duplicacion comun: creacion de Download, locale, manejo de
 * errores, buildQuery con scope, generateFilename, buildFiltersSummary.
 *
 * Clon de BaseRegionExportJob adaptado a WorkPlans (per-tenant, con relacion
 * a country y filtros adicionales cod / country_id / only_favorites).
 *
 * Subclase debe implementar:
 *   - `protected string $type` y `protected string $extension`
 *   - `executeExport(Download $download): void` — renderiza el archivo y
 *     deja `$download->status = 'ready'` + `$download->path` seteado.
 */
abstract class BaseWorkPlanExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout en segundos. 10 min cubre el peor caso de 1M filas en CSV. */
    public int $timeout = 600;

    /** Reintenta 2 veces ante fallas transitorias. */
    public int $tries = 2;

    /** Override en la subclase: 'csv' | 'excel' | 'pdf' | 'word'. */
    protected string $type;

    /** Override en la subclase: extension del archivo final (sin punto). */
    protected string $extension;

    protected int $userId;
    protected array $options;
    protected string $locale;
    /** TZ del user que disparó el export — fechas se convierten al display. */
    protected string $userTimezone;
    protected ?Download $download = null;

    /**
     * Tenant del usuario que dispara el job. Lo capturamos al construir
     * (auth context) y lo aplicamos manualmente en buildQuery() porque el
     * worker de queue no tiene la sesion del usuario — el BelongsToTenant
     * trait no scopea sin un user autenticado.
     */
    protected ?int $tenantId = null;

    /** ID del Download persistido en el primer try. Preservado entre retries. */
    protected ?int $downloadId = null;

    public function __construct(int $userId, array $options = [])
    {
        $this->userId   = $userId;
        $this->options  = $options;
        $this->locale   = app()->getLocale();
        // Capturamos tenant_id del user que dispara — el worker no tiene sesion.
        $user = \App\Models\User::find($userId);
        $this->tenantId     = $user?->tenant_id;
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
        // Techo de memoria explicito.
        ini_set('memory_limit', config('work_plans.export_job_memory_limit', '512M'));

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

            throw $e;
        }
    }

    /**
     * Se llama cuando el job falla DEFINITIVAMENTE (tries agotados, timeout,
     * kill -9 por OOM, supervisor restart).
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
     * Apply scope: filtered / selected / all. Eager-load creator y country
     * si las columnas estan pedidas.
     *
     * WorkPlans es per-tenant: aplicamos `withoutGlobalScopes()` y manualmente
     * filtramos por tenant_id capturado en el constructor — el worker no tiene
     * la sesion del usuario para que el global scope BelongsToTenant funcione.
     */
    protected function buildQuery()
    {
        $scope = $this->options['scope'] ?? 'filtered';
        $columns = $this->options['columns'] ?? ['creator'];

        $base = WorkPlan::query()->withoutGlobalScope('tenant');

        // work_plans es catálogo global (sin tenant), no filtramos por tenant_id.

        if (in_array('creator', $columns)) {
            $base->with('creator:id,name');
        }
        // Relaciones que piden las columnas de obra: sin esto el export dispara
        // una query por fila, y son miles de planes.
        foreach ([
            'company'       => 'company:id,name',
            'work_type'     => 'workType:id,code',
            'work_location' => 'workLocation:id,name',
            'workstation'   => 'workstation:id,name',
            'work_area'     => 'workArea:id,name',
            'registered_by' => 'user:id,name',
        ] as $col => $relation) {
            if (in_array($col, $columns, true)) {
                $base->with($relation);
            }
        }
        if (in_array('people_count', $columns, true)) {
            $base->withCount('people');
        }

        if ($scope === 'selected' && !empty($this->options['selected_ids'])) {
            return $base->whereIn('work_plans.id', $this->options['selected_ids']);
        }
        if ($scope === 'all') {
            return $base;
        }

        // scopeFilter espera un Request — convertimos el array de filtros.
        $filters  = $this->options['filters'] ?? [];
        $fakeReq  = new \Illuminate\Http\Request($filters);
        return $base->filter($fakeReq);
    }

    /**
     * Lista flat de filtros activos para la portada de PDF/Word.
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function buildFiltersSummary(): array
    {
        $f = $this->options['filters'] ?? [];
        $out = [];

        if (!empty($f['search'])) {
            $terms = is_array($f['search']) ? $f['search'] : [$f['search']];
            $out[] = ['label' => __('work_plans.filter_search'), 'value' => implode(', ', $terms)];
        }
        if (!empty($f['code'])) {
            $out[] = ['label' => __('work_plans.code'), 'value' => (string) $f['code']];
        }
        if (!empty($f['num_os'])) {
            $out[] = ['label' => __('work_plans.num_os'), 'value' => (string) $f['num_os']];
        }
        if (isset($f['is_done']) && $f['is_done'] !== '' && $f['is_done'] !== null) {
            $bool = filter_var($f['is_done'], FILTER_VALIDATE_BOOLEAN);
            $out[] = ['label' => __('work_plans.is_done'), 'value' => $bool ? __('work_plans.state_done') : __('work_plans.state_pending')];
        }
        if (!empty($f['date_from']) || !empty($f['date_to'])) {
            $out[] = ['label' => __('work_plans.date_start'), 'value' => ($f['date_from'] ?? '…') . ' → ' . ($f['date_to'] ?? '…')];
        }
        if (!empty($f['created_from']) || !empty($f['created_to'])) {
            $out[] = ['label' => __('global.created_at'), 'value' => ($f['created_from'] ?? '…') . ' → ' . ($f['created_to'] ?? '…')];
        }
        if (!empty($f['only_favorites']) && filter_var($f['only_favorites'], FILTER_VALIDATE_BOOLEAN)) {
            $out[] = ['label' => __('global.only_favorites'), 'value' => '✓'];
        }

        return $out;
    }

    protected function generateFilename(): string
    {
        $base = Str::slug($this->options['title'] ?? __('work_plans.export_filename'));
        return $base . '_' . now()->format('Y-m-d_H-i-s') . '.' . $this->extension;
    }
}
