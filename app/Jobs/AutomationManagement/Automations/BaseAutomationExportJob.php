<?php

namespace App\Jobs\AutomationManagement\Automations;

use App\Models\Automation;
use App\Models\Download;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base abstracta para los 4 jobs de export (CSV / Excel / PDF / Word) del
 * modulo Automations. Concentra la duplicacion comun: creacion de Download,
 * locale, manejo de errores, buildQuery con scope, generateFilename,
 * buildFiltersSummary.
 *
 * Clon de BaseCustomerExportJob adaptado a Automations (per-tenant, sin
 * relacion country pero con filtros adicionales data_source / action_type /
 * only_favorites).
 *
 * Subclase debe implementar:
 *   - `protected string $type` y `protected string $extension`
 *   - `executeExport(Download $download): void` — renderiza el archivo y
 *     deja `$download->status = 'ready'` + `$download->path` seteado.
 */
abstract class BaseAutomationExportJob implements ShouldQueue
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
        ini_set('memory_limit', config('automations.export_job_memory_limit', '512M'));

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
     * Apply scope: filtered / selected / all. Eager-load creator si las
     * columnas estan pedidas.
     *
     * Automations es per-tenant: aplicamos `withoutGlobalScopes()` y manualmente
     * filtramos por tenant_id capturado en el constructor — el worker no tiene
     * la sesion del usuario para que el global scope BelongsToTenant funcione.
     */
    protected function buildQuery()
    {
        $scope   = $this->options['scope']   ?? 'filtered';
        $columns = $this->options['columns'] ?? ['creator'];

        $base = Automation::query()->withoutGlobalScopes();

        if ($this->tenantId !== null) {
            $base->where('automations.tenant_id', $this->tenantId);
        }

        if (in_array('creator', $columns)) {
            $base->with('creator:id,name');
        }

        if ($scope === 'selected' && !empty($this->options['selected_ids'])) {
            return $base->whereIn('automations.id', $this->options['selected_ids']);
        }
        if ($scope === 'all') {
            return $base;
        }

        // Aplicamos filtros manualmente (no hay scopeFilter en Automation —
        // las queries del controller usan `when()` inline). Replicamos la
        // misma forma de filtrado para mantener paridad con el listado.
        $f = $this->options['filters'] ?? [];

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $names = array_filter(array_map('trim', $names), fn ($n) => $n !== '');
            if (!empty($names)) {
                $isPgsql = \DB::getDriverName() === 'pgsql';
                $base->where(function ($qq) use ($names, $isPgsql) {
                    foreach ($names as $name) {
                        if ($isPgsql) {
                            $qq->orWhereRaw('unaccent(lower(automations.name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                        } else {
                            $qq->orWhere('automations.name', 'like', '%' . $name . '%');
                        }
                    }
                });
            }
        }

        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $base->where('automations.is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($f['data_source'])) {
            $values = is_array($f['data_source']) ? $f['data_source'] : [$f['data_source']];
            $values = array_values(array_filter($values, fn ($v) => $v !== ''));
            if (!empty($values)) {
                $base->whereIn('automations.data_source', $values);
            }
        }

        if (!empty($f['action_type'])) {
            $values = is_array($f['action_type']) ? $f['action_type'] : [$f['action_type']];
            $values = array_values(array_filter($values, fn ($v) => $v !== ''));
            if (!empty($values)) {
                $base->whereIn('automations.action_type', $values);
            }
        }

        if (!empty($f['created_from'])) {
            $base->where('automations.created_at', '>=', $f['created_from'] . ' 00:00:00');
        }
        if (!empty($f['created_to'])) {
            $base->where('automations.created_at', '<=', $f['created_to'] . ' 23:59:59');
        }

        if (!empty($f['only_favorites']) && filter_var($f['only_favorites'], FILTER_VALIDATE_BOOLEAN)) {
            $base->whereExists(function ($qq) {
                $qq->select(\DB::raw(1))
                    ->from('user_favorites')
                    ->whereColumn('user_favorites.favoritable_id', 'automations.id')
                    ->where('user_favorites.favoritable_type', Automation::class)
                    ->where('user_favorites.user_id', $this->userId);
            });
        }

        return $base;
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

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $out[] = ['label' => __('automations.name'), 'value' => implode(', ', $names)];
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $bool = filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN);
            $out[] = ['label' => __('automations.is_active'), 'value' => $bool ? __('global.active') : __('global.inactive')];
        }
        if (!empty($f['data_source'])) {
            $values = is_array($f['data_source']) ? $f['data_source'] : [$f['data_source']];
            $values = array_values(array_filter($values, fn ($v) => $v !== ''));
            if (!empty($values)) {
                $out[] = ['label' => __('automations.data_source'), 'value' => implode(', ', $values)];
            }
        }
        if (!empty($f['action_type'])) {
            $values = is_array($f['action_type']) ? $f['action_type'] : [$f['action_type']];
            $values = array_values(array_filter($values, fn ($v) => $v !== ''));
            if (!empty($values)) {
                $out[] = ['label' => __('automations.action_type'), 'value' => implode(', ', $values)];
            }
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
        $base = Str::slug($this->options['title'] ?? __('automations.export_filename'));
        return $base . '_' . now()->format('Y-m-d_H-i-s') . '.' . $this->extension;
    }
}
