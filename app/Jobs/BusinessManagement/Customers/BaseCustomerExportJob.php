<?php

namespace App\Jobs\BusinessManagement\Customers;

use App\Models\Customer;
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
 * Clon de BaseRegionExportJob adaptado a Customers (per-tenant, con relacion
 * a country y filtros adicionales cod / country_id / only_favorites).
 *
 * Subclase debe implementar:
 *   - `protected string $type` y `protected string $extension`
 *   - `executeExport(Download $download): void` — renderiza el archivo y
 *     deja `$download->status = 'ready'` + `$download->path` seteado.
 */
abstract class BaseCustomerExportJob implements ShouldQueue
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

    /** Clientes a los que el user está restringido (3ª capa). Vacío = sin restricción. @var int[] */
    protected array $allowedCustomerIds = [];

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
        // 3ª capa: restricción por cliente, capturada aquí (el worker no tiene
        // sesión y el global scope assigned_customers no aplica allí).
        $this->allowedCustomerIds = $user?->assignedCustomerIds() ?? [];

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
        ini_set('memory_limit', config('customers.export_job_memory_limit', '512M'));

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
     * Customers es per-tenant: aplicamos `withoutGlobalScopes()` y manualmente
     * filtramos por tenant_id capturado en el constructor — el worker no tiene
     * la sesion del usuario para que el global scope BelongsToTenant funcione.
     */
    protected function buildQuery()
    {
        $scope = $this->options['scope'] ?? 'filtered';
        $columns = $this->options['columns'] ?? ['creator', 'country'];

        // Quitamos SOLO el scope de tenant (el worker no tiene sesión para
        // resolverlo), NO todos los scopes: dejamos vivo el de SoftDeletes para
        // que la papelera NO se exporte (igual que el listado). El tenant se
        // filtra a mano abajo.
        $base = Customer::query()->withoutGlobalScope('tenantOrGlobal');

        // Aplicar tenant scope manualmente. tenant_id null = super (sin
        // tenant), exporta todos los registros (consistente con el global scope).
        if ($this->tenantId !== null) {
            $base->where('customers.tenant_id', $this->tenantId);
        }

        // 3ª capa: restringido a clientes exporta SOLO esos (espeja el scope
        // assigned_customers que no corre en el worker).
        if (!empty($this->allowedCustomerIds)) {
            $base->whereIn('customers.id', $this->allowedCustomerIds);
        }

        if (in_array('creator', $columns)) {
            $base->with('creator:id,name');
        }
        if (in_array('country', $columns)) {
            $base->with('country:id,name,iso_code');
        }

        // Conteos de la jerarquía solo si se piden (mismo patrón que el index):
        // locations/areas/transformers por withCount, substations por subquery.
        $countCols = ['locations_count', 'areas_count', 'substations_count', 'transformers_count'];
        if (array_intersect($countCols, $columns)) {
            $base->withCount(['locations', 'areas', 'transformers'])
                ->addSelect(['substations_count' => \App\Models\CustomerSubstation::query()
                    ->selectRaw('count(*)')
                    ->whereIn('customer_area_id', \App\Models\CustomerArea::query()
                        ->select('id')
                        ->whereIn('customer_location_id', \App\Models\CustomerLocation::query()
                            ->select('id')
                            ->whereColumn('customer_id', 'customers.id')))]);
        }

        if ($scope === 'selected' && !empty($this->options['selected_ids'])) {
            return $base->whereIn('customers.id', $this->options['selected_ids']);
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

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $out[] = ['label' => __('customers.name'), 'value' => implode(', ', $names)];
        }
        if (!empty($f['cod'])) {
            $out[] = ['label' => __('customers.cod'), 'value' => (string) $f['cod']];
        }
        if (!empty($f['country_id'])) {
            $ids = is_array($f['country_id']) ? $f['country_id'] : [$f['country_id']];
            $ids = array_values(array_filter($ids));
            if (!empty($ids)) {
                $names = \App\Models\Country::whereIn('id', $ids)->pluck('name')->all();
                $out[] = ['label' => __('customers.country'), 'value' => implode(', ', $names) ?: implode(', ', $ids)];
            }
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $bool = filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN);
            $out[] = ['label' => __('customers.is_active'), 'value' => $bool ? __('global.active') : __('global.inactive')];
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
        $base = Str::slug($this->options['title'] ?? __('customers.export_filename'));
        return $base . '_' . now()->format('Y-m-d_H-i-s') . '.' . $this->extension;
    }
}
