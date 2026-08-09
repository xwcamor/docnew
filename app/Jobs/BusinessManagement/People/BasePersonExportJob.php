<?php

namespace App\Jobs\BusinessManagement\People;

use App\Models\Person;
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
 * Clon de BaseRegionExportJob adaptado a People (per-tenant, con relacion
 * a country y filtros adicionales cod / country_id / only_favorites).
 *
 * Subclase debe implementar:
 *   - `protected string $type` y `protected string $extension`
 *   - `executeExport(Download $download): void` — renderiza el archivo y
 *     deja `$download->status = 'ready'` + `$download->path` seteado.
 */
abstract class BasePersonExportJob implements ShouldQueue
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
        // El workspace EFECTIVO, no su columna: un super que ha entrado en uno
        // debe exportar lo de ese workspace, no lo de todos.
        $this->tenantId     = \App\Support\TenantContext::actual($user);
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
        ini_set('memory_limit', config('people.export_job_memory_limit', '512M'));

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
     * People es per-tenant: aplicamos `withoutGlobalScopes()` y manualmente
     * filtramos por tenant_id capturado en el constructor — el worker no tiene
     * la sesion del usuario para que el global scope BelongsToTenant funcione.
     */
    /**
     * ¿Quien pidio el fichero puede ver los documentos completos?
     *
     * Se resuelve aqui y no en cada formato porque el job corre en cola: no hay
     * sesion, `auth()` esta vacio y `PrivateInfo::visibleFor()` sin usuario
     * devuelve false. El usuario que lo pidio llega en `$this->userId`.
     */
    protected function puedeVerElDocumento(): bool
    {
        return \App\Support\PrivateInfo::visibleFor(\App\Models\User::find($this->userId));
    }

    protected function buildQuery()
    {
        $scope = $this->options['scope'] ?? 'filtered';
        $columns = $this->options['columns'] ?? ['creator'];

        $base = Person::query()->withoutGlobalScope('tenantOrGlobal');

        // El job corre en cola, o sea en un worker SIN sesion: el global scope
        // se rinde solo (`if (! auth()->hasUser()) return;`) y la consulta salia
        // sin ningun filtro de workspace — el admin se descargaba las filas de
        // TODOS. Se re-aplica a mano con el tenant que se capturo al encolar.
        // tenant_id null = super sin workspace: exporta todo, igual que ve en
        // pantalla. Con workspace: las suyas mas las globales del catalogo.
        if ($this->tenantId !== null) {
            $base->where(function ($q) {
                $q->where('people.tenant_id', $this->tenantId)
                  ->orWhereNull('people.tenant_id');
            });
        }

        if (in_array('creator', $columns)) {
            $base->with('creator:id,name');
        }
        // Relaciones y conteos solo si se van a exportar: sin esto cada fila
        // dispara sus propias queries.
        if (in_array('country', $columns, true)) {
            $base->with('country:id,name');
        }
        if (in_array('roles', $columns, true)) {
            $base->with('roles');
        }
        if (in_array('companies', $columns, true)) {
            $base->with('companyLinks.company:id,name');
        }
        if (in_array('companies_count', $columns, true)) {
            $base->withCount('companyLinks');
        }
        if (in_array('has_biometric', $columns, true)) {
            $base->withCount(['biometrics as active_biometrics_count' => fn ($q) => $q->where('is_active', true)]);
        }

        if ($scope === 'selected' && !empty($this->options['selected_ids'])) {
            return $base->whereIn('people.id', $this->options['selected_ids']);
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
     * Tapa el documento **al salir de la base**, no al pintarlo.
     *
     * El CSV escribia `$person->num_doc` en crudo y el PDF, el Excel y el Word
     * pasan la coleccion entera a su plantilla: cuatro sitios donde acordarse,
     * y un quinto formato nuevo que se olvidaria. Enmascarar aqui lo cubre
     * todo de una vez y no hay forma de saltarselo escribiendo otra plantilla.
     *
     * Importa porque el enmascarado de la pantalla no servia de nada: bastaba
     * pulsar «Exportar CSV» para bajarse los 228 documentos completos.
     */
    protected function taparDocumento($people)
    {
        if ($this->puedeVerElDocumento()) {
            return $people;
        }

        // `map` y no `each` porque tres de los cuatro formatos recorren un
        // `cursor()`: con `each` se materializaria la coleccion entera y un
        // export de 200 000 personas se comeria la memoria. `map` sobre una
        // LazyCollection sigue siendo perezoso.
        return $people->map(function ($persona) {
            if (filled($persona->num_doc)) {
                // Solo el atributo en memoria. Nada de esto se guarda.
                $persona->num_doc = \App\Support\PrivateInfo::enmascarar((string) $persona->num_doc);
            }

            return $persona;
        });
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
            $out[] = ['label' => __('people.name'), 'value' => implode(', ', $names)];
        }
        if (!empty($f['num_doc'])) {
            $out[] = ['label' => __('people.num_doc'), 'value' => (string) $f['num_doc']];
        }
        if (!empty($f['doc_type'])) {
            $out[] = ['label' => __('people.doc_type'), 'value' => (string) $f['doc_type']];
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $bool = filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN);
            $out[] = ['label' => __('people.is_active'), 'value' => $bool ? __('global.active') : __('global.inactive')];
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
        $base = Str::slug($this->options['title'] ?? __('people.export_filename'));
        return $base . '_' . now()->format('Y-m-d_H-i-s') . '.' . $this->extension;
    }
}
