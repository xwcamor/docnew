<?php

namespace App\Jobs\AuthManagement\Users;

use App\Models\Download;
use App\Models\User;
use App\Scopes\HideSuperScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base abstracta para los 4 jobs de export (CSV / Excel / PDF / Word) de Users.
 * Concentra la duplicacion comun: creacion de Download, locale, manejo de
 * errores, buildQuery con scope, generateFilename, buildFiltersSummary.
 *
 * Clon de BaseCustomerExportJob adaptado a Users con dos diferencias clave:
 *
 *  1. HideSuperScope: cuando un admin de tenant exporta, el global
 *     scope oculta el super row. Solo el super dispatcher puede
 *     ver/exportar el super → `withoutGlobalScopes` parcial.
 *
 *  2. Filtros inline: User no tiene `scopeFilter` (su index los hace inline).
 *     Replicamos esa logica aquí contra el array de filtros capturado.
 *
 * Subclase debe implementar:
 *   - `protected string $type` y `protected string $extension`
 *   - `executeExport(Download $download): void`
 */
abstract class BaseUserExportJob implements ShouldQueue
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
     */
    protected ?int $tenantId = null;

    /**
     * Si el dispatcher es super, el job desactiva HideSuperScope
     * para que pueda exportar el row del super. Admins de tenant lo
     * mantienen aplicado (no ven super ni system_users).
     */
    protected bool $dispatcherIsSuper = false;

    /** ID del Download persistido en el primer try. Preservado entre retries. */
    protected ?int $downloadId = null;

    public function __construct(int $userId, array $options = [])
    {
        $this->userId  = $userId;
        $this->options = $options;
        $this->locale  = app()->getLocale();

        $dispatcher = User::withoutGlobalScopes()->find($userId);
        $this->tenantId               = $dispatcher?->tenant_id;
        $this->dispatcherIsSuper = $dispatcher
            ? $dispatcher->hasRole('super')
            : false;
        $this->userTimezone = \App\Support\Tz::for($dispatcher);

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
        ini_set('memory_limit', config('users.export_job_memory_limit', '512M'));

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
     * Apply scope: filtered / selected / all. Eager-load creator, roles y
     * tenant cuando esten pedidos.
     *
     * Tenant scope manual + HideSuperScope condicional:
     *   - super dispatcher → sin HideSuperScope, sin tenant filter.
     *   - admin de tenant  → HideSuperScope aplicado + tenant filter.
     */
    protected function buildQuery()
    {
        $scope   = $this->options['scope']   ?? 'filtered';
        $columns = $this->options['columns'] ?? ['creator', 'tenant', 'role'];

        // Empezamos sin global scopes y aplicamos selectivamente HideSuperScope.
        $base = User::query()->withoutGlobalScopes();

        if (!$this->dispatcherIsSuper) {
            // Admin de tenant: aplicamos HideSuperScope manualmente
            // (la global scope original mira auth(), que en queue no existe).
            $hiddenRoleIds = \DB::table('roles')
                ->whereIn('name', ['super', 'api'])
                ->where('guard_name', 'web')
                ->pluck('id')
                ->all();

            if (!empty($hiddenRoleIds)) {
                $base->whereDoesntHave('roles', function ($q) use ($hiddenRoleIds) {
                    $q->whereIn('roles.id', $hiddenRoleIds);
                });
            }

            // Tenant filter manual.
            if ($this->tenantId !== null) {
                $base->where('users.tenant_id', $this->tenantId);
            }
        }

        if (in_array('creator', $columns, true)) {
            $base->with('creator:id,name,email');
        }
        if (in_array('tenant', $columns, true)) {
            $base->with('tenant:id,name');
        }
        if (in_array('role', $columns, true)) {
            $base->with('roles:id,name');
        }

        if ($scope === 'selected' && !empty($this->options['selected_ids'])) {
            return $base->whereIn('users.id', $this->options['selected_ids']);
        }
        if ($scope === 'all') {
            return $base;
        }

        // Filtros inline (User no tiene scopeFilter — replica controller).
        $f = $this->options['filters'] ?? [];

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $names = array_filter($names, fn ($n) => $n !== '');
            if (!empty($names)) {
                $isPgsql = \DB::getDriverName() === 'pgsql';
                $base->where(function ($qq) use ($names, $isPgsql) {
                    foreach ($names as $name) {
                        if ($isPgsql) {
                            $qq->orWhereRaw('unaccent(lower(users.name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                        } else {
                            $qq->orWhere('users.name', 'like', '%' . $name . '%');
                        }
                    }
                });
            }
        }

        if (!empty($f['email'])) {
            $base->where('users.email', 'like', '%' . $f['email'] . '%');
        }

        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $base->where('users.is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($f['role_id'])) {
            $roleIds = is_array($f['role_id']) ? $f['role_id'] : [$f['role_id']];
            $base->whereHas('roles', fn ($r) => $r->whereIn('roles.id', $roleIds));
        }

        // tenant_id filter solo aplica si dispatcher es super
        // (admin ya esta scoped a su tenant; ignorar este input es seguro).
        if (!empty($f['tenant_id']) && $this->dispatcherIsSuper) {
            $tenantIds = is_array($f['tenant_id']) ? $f['tenant_id'] : [$f['tenant_id']];
            $base->whereIn('users.tenant_id', $tenantIds);
        }

        if (!empty($f['created_from'])) {
            $base->where('users.created_at', '>=', $f['created_from'] . ' 00:00:00');
        }
        if (!empty($f['created_to'])) {
            $base->where('users.created_at', '<=', $f['created_to'] . ' 23:59:59');
        }

        if (!empty($f['only_favorites']) && filter_var($f['only_favorites'], FILTER_VALIDATE_BOOLEAN)) {
            $base->whereExists(function ($sub) {
                $sub->select(\DB::raw(1))
                    ->from('user_favorites')
                    ->whereColumn('user_favorites.favoritable_id', 'users.id')
                    ->where('user_favorites.favoritable_type', User::class)
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
            $out[] = ['label' => __('users.filter_name'), 'value' => implode(', ', $names)];
        }
        if (!empty($f['email'])) {
            $out[] = ['label' => __('users.filter_email'), 'value' => (string) $f['email']];
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $bool = filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN);
            $out[] = ['label' => __('users.is_active'), 'value' => $bool ? __('global.active') : __('global.inactive')];
        }
        if (!empty($f['role_id'])) {
            $ids = is_array($f['role_id']) ? $f['role_id'] : [$f['role_id']];
            $ids = array_values(array_filter($ids));
            if (!empty($ids)) {
                $names = \DB::table('roles')->whereIn('id', $ids)->pluck('name')->all();
                $out[] = ['label' => __('users.role'), 'value' => implode(', ', $names) ?: implode(', ', $ids)];
            }
        }
        if (!empty($f['tenant_id']) && $this->dispatcherIsSuper) {
            $ids = is_array($f['tenant_id']) ? $f['tenant_id'] : [$f['tenant_id']];
            $ids = array_values(array_filter($ids));
            if (!empty($ids)) {
                $names = \App\Models\Tenant::whereIn('id', $ids)->pluck('name')->all();
                $out[] = ['label' => __('users.tenant'), 'value' => implode(', ', $names) ?: implode(', ', $ids)];
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
        $base = Str::slug($this->options['title'] ?? __('users.export_filename'));
        return $base . '_' . now()->format('Y-m-d_H-i-s') . '.' . $this->extension;
    }
}
