<?php

namespace App\Jobs\AuthManagement\Roles;

use App\Models\Download;
use App\Models\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base abstracta para los 4 jobs de export (CSV / Excel / PDF / Word) de Roles.
 * Concentra la duplicacion comun: creacion de Download, locale, manejo de
 * errores, buildQuery con scope, generateFilename, buildFiltersSummary.
 *
 * Clon de BaseCustomerExportJob adaptado a Roles. Diferencias:
 *   - Roles tiene un eje de visibilidad extra: ademas del tenant del usuario,
 *     los roles del SISTEMA (tenant_id=null, names en `systemRoleNames`) son
 *     visibles para todos los tenants. super (sin tenant_id) ve
 *     absolutamente todo.
 *   - Tenant scoping NO es per-trait (Role no usa BelongsToTenant) — se hace
 *     manual aquí y en el controller.
 *
 * Subclase debe implementar:
 *   - `protected string $type` y `protected string $extension`
 *   - `executeExport(Download $download): void` — renderiza el archivo y
 *     deja `$download->status = 'ready'` + `$download->path` seteado.
 */
abstract class BaseRoleExportJob implements ShouldQueue
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
     * worker de queue no tiene la sesion del usuario.
     *
     * super tiene tenant_id=null → ve TODOS los roles sin restriccion.
     * admin tiene tenant_id=X → ve roles de su tenant + roles del sistema
     *   (tenant_id=null, names protegidos super/admin/api).
     */
    protected ?int $tenantId = null;

    /**
     * Nombres de roles del sistema — protegidos globalmente. Tenant users
     * los ven en exports (read-only) pero no pueden editarlos ni importarlos.
     */
    protected array $systemRoleNames = ['super', 'admin', 'api'];

    /** Snapshot de si el dispatcher es super — captura una sola vez. */
    protected bool $isSuper = false;

    /** ID del Download persistido en el primer try. Preservado entre retries. */
    protected ?int $downloadId = null;

    public function __construct(int $userId, array $options = [])
    {
        $this->userId   = $userId;
        $this->options  = $options;
        $this->locale   = app()->getLocale();
        // Capturamos tenant_id + flag super del user que dispara.
        $user = \App\Models\User::find($userId);
        $this->tenantId = $user?->tenant_id;
        $this->isSuper  = (bool) $user?->hasRole('super');
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
        ini_set('memory_limit', config('roles.export_job_memory_limit', '512M'));

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
     * Apply scope: filtered / selected / all. Eager-load creator y tenant
     * si las columnas estan pedidas.
     *
     * Roles scoping:
     *   - super → todos los roles, sin filtro
     *   - tenant user → roles de SU tenant + roles del sistema (tenant_id=null
     *     + name en `systemRoleNames`)
     */
    protected function buildQuery()
    {
        $scope   = $this->options['scope'] ?? 'filtered';
        $columns = $this->options['columns'] ?? ['creator', 'tenant'];

        $base = Role::query()->where('guard_name', 'web');

        // Tenant scoping. super no filtra.
        if (!$this->isSuper) {
            $tenantId        = $this->tenantId;
            $systemRoleNames = $this->systemRoleNames;
            $base->where(function ($q) use ($tenantId, $systemRoleNames) {
                $q->where('tenant_id', $tenantId)
                  ->orWhere(function ($qq) use ($systemRoleNames) {
                      $qq->whereNull('tenant_id')->whereIn('name', $systemRoleNames);
                  });
            });
        }

        if (in_array('creator', $columns)) {
            $base->with('creator:id,name');
        }
        if (in_array('tenant', $columns)) {
            $base->with('tenant:id,name');
        }

        if ($scope === 'selected' && !empty($this->options['selected_ids'])) {
            return $base->whereIn('roles.id', $this->options['selected_ids']);
        }
        if ($scope === 'all') {
            return $base;
        }

        // Roles no tiene scopeFilter en el modelo (los filtros viven en el
        // controller). Replicamos aquí el subset de filtros para mantener
        // coherencia con lo que ve el usuario en pantalla.
        return $this->applyFilters($base);
    }

    /**
     * Aplica los filtros de Roles (name multi-tag, is_active, scope, dates,
     * only_favorites) a la query. Replica la logica del RoleController@index.
     */
    protected function applyFilters($query)
    {
        $f = $this->options['filters'] ?? [];
        $isPgsql = config('database.default') === 'pgsql';

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $names = array_filter($names, fn ($n) => $n !== '' && $n !== null);
            if (!empty($names)) {
                $query->where(function ($qq) use ($names, $isPgsql) {
                    foreach ($names as $name) {
                        if ($isPgsql) {
                            $qq->orWhereRaw('unaccent(lower(name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                        } else {
                            $qq->orWhere('name', 'like', '%' . $name . '%');
                        }
                    }
                });
            }
        }

        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $query->where('is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($f['scope'])) {
            $scope = is_array($f['scope']) ? $f['scope'] : [$f['scope']];
            $query->where(function ($qq) use ($scope) {
                if (in_array('system', $scope, true)) $qq->orWhereNull('tenant_id');
                if (in_array('tenant', $scope, true)) $qq->orWhereNotNull('tenant_id');
            });
        }

        if (!empty($f['created_from'])) {
            $query->where('roles.created_at', '>=', $f['created_from'] . ' 00:00:00');
        }
        if (!empty($f['created_to'])) {
            $query->where('roles.created_at', '<=', $f['created_to'] . ' 23:59:59');
        }

        if (!empty($f['only_favorites']) && filter_var($f['only_favorites'], FILTER_VALIDATE_BOOLEAN)) {
            $userId = $this->userId;
            $query->whereExists(function ($sub) use ($userId) {
                $sub->select(\DB::raw(1))
                    ->from('user_favorites')
                    ->whereColumn('user_favorites.favoritable_id', 'roles.id')
                    ->where('user_favorites.favoritable_type', Role::class)
                    ->where('user_favorites.user_id', $userId);
            });
        }

        return $query;
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
            $out[] = ['label' => __('roles.name'), 'value' => implode(', ', $names)];
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $bool = filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN);
            $out[] = ['label' => __('roles.is_active'), 'value' => $bool ? __('global.active') : __('global.inactive')];
        }
        if (!empty($f['scope'])) {
            $scope = is_array($f['scope']) ? $f['scope'] : [$f['scope']];
            $labels = array_map(fn ($s) => $s === 'system' ? __('roles.tag_system') : __('roles.tenant'), $scope);
            $out[] = ['label' => __('roles.scope'), 'value' => implode(', ', $labels)];
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
        $base = Str::slug($this->options['title'] ?? __('roles.export_title'));
        return $base . '_' . now()->format('Y-m-d_H-i-s') . '.' . $this->extension;
    }
}
