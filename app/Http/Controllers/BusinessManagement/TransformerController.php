<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\Transformer\BulkDeleteTransformerRequest;
use App\Http\Requests\BusinessManagement\Transformer\BulkRestoreTransformerRequest;
use App\Http\Requests\BusinessManagement\Transformer\DeleteTransformerRequest;
use App\Http\Requests\BusinessManagement\Transformer\EditAllUpdateTransformerRequest;
use App\Http\Requests\BusinessManagement\Transformer\ForceDeleteTransformerRequest;
use App\Http\Requests\BusinessManagement\Transformer\ImportTransformerRequest;
use App\Http\Requests\BusinessManagement\Transformer\StoreTransformerRequest;
use App\Http\Requests\BusinessManagement\Transformer\UpdateTransformerRequest;
use App\Jobs\BusinessManagement\Transformers\GenerateTransformersCsvJob;
use App\Jobs\BusinessManagement\Transformers\GenerateTransformersExcelJob;
use App\Jobs\BusinessManagement\Transformers\GenerateFleetReportExcelJob;
use App\Jobs\BusinessManagement\Transformers\GenerateFleetReportCsvJob;
use App\Jobs\BusinessManagement\Transformers\GenerateFleetReportPdfJob;
use App\Jobs\BusinessManagement\Transformers\GenerateTransformersPdfJob;
use App\Jobs\BusinessManagement\Transformers\GenerateTransformersWordJob;
use App\Models\AuditLog;
use App\Models\Transformer;
use App\Services\BusinessManagement\TransformerService;
use App\Services\Diagnostics\HealthIndexService;
use App\Support\Transformers\TransformerShowPayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransformerController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /** Pone el candado al trafo (super → nivel sistema; admin → nivel tenant). */
    public function lock(Request $request, Transformer $transformer): RedirectResponse
    {
        return $this->applyLock($transformer, $request);
    }

    /** Saca el candado (un admin no puede quitar un candado del super). */
    public function unlock(Request $request, Transformer $transformer): RedirectResponse
    {
        return $this->applyUnlock($transformer, $request);
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        $userId  = $request->user()?->id;
        $isSuper = $request->user()?->hasRole('super') ?? false;

        // Solo super necesita el tenant eager-loaded â€” admins ven solo los suyos
        // y la columna workspace queda oculta en el frontend.
        $with = ['creator:id,name,email', 'customer:id,name', 'oilType:id,name', 'transformerType:id,name,code,shape', 'connectionType:id,name', 'tapChangerType:id,name', 'preservation:id,name', 'brand:id,name'];
        if ($isSuper) {
            $with[] = 'tenant:id,name';
        }

        $transformers = Transformer::query()
            ->select('transformers.*')
            ->with($with)
            ->orderByFavoriteFirst($userId)
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        $totalUnfiltered = Transformer::count();

        // serial/tag son filtros multi-valor (tags). El front puede mandarlos como
        // array, string suelto, '' o null (ConvertEmptyStringsToNull transforma el
        // `serial=`/`tag=` vacio de la URL en null). Normalizamos todo a array.
        $serials = $request->get('serial', []);
        $serials = is_array($serials) ? array_values($serials) : (filled($serials) ? [$serials] : []);
        $tags = $request->get('tag', []);
        $tags = is_array($tags) ? array_values($tags) : (filled($tags) ? [$tags] : []);

        // Opciones de los selects — se reusan en los multiselects de filtros Y
        // en el schema de filtros avanzados (para no consultarlas dos veces).
        $customerOptions        = $this->customerOptions();
        $transformerTypeOptions = $this->transformerTypeOptions();
        $oilTypeOptions         = $this->oilTypeOptions();
        $brandOptions           = $this->brandOptions();
        $tapChangerTypeOptions  = $this->tapChangerTypeOptions();
        $connectionTypeOptions  = $this->connectionTypeOptions();
        $preservationOptions    = $this->preservationOptions();
        $substationOptions      = $this->substationOptions();

        return inertia('Transformers/Index', [
            'transformers' => array_merge($transformers->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato â€” el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('transformers'),
            // Opciones para los multiselects de filtros (reusan los del form).
            'customerOptions'        => $customerOptions,
            'transformerTypeOptions' => $transformerTypeOptions,
            'oilTypeOptions'         => $oilTypeOptions,
            'brandOptions'           => $brandOptions,
            'tapChangerTypeOptions'  => $tapChangerTypeOptions,
            'connectionTypeOptions'  => $connectionTypeOptions,
            'preservationOptions'    => $preservationOptions,
            'substationOptions'      => $substationOptions,
            'filters' => [
                'q'            => $request->get('q', ''),
                'serial'       => array_values($serials),
                'tag'          => array_values($tags),
                'customer_id'  => $request->get('customer_id', []),
                'customer_substation_id'    => $request->get('customer_substation_id', []),
                'transformer_type_id' => $request->get('transformer_type_id', []),
                'oil_type_id'  => $request->get('oil_type_id', []),
                'brand_id'     => $request->get('brand_id', []),
                'tap_changer_type_id'       => $request->get('tap_changer_type_id', []),
                'connection_type_id'        => $request->get('connection_type_id', []),
                'transformer_preservation_id' => $request->get('transformer_preservation_id', []),
                'phases'       => $request->get('phases', []),
                'health_rating' => $request->get('health_rating', []),
                'voltage_min'  => $request->get('voltage_min', ''),
                'voltage_max'  => $request->get('voltage_max', ''),
                'power_min'    => $request->get('power_min', ''),
                'power_max'    => $request->get('power_max', ''),
                'year_min'     => $request->get('year_min', ''),
                'year_max'     => $request->get('year_max', ''),
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                'only_favorites' => $request->boolean('only_favorites'),
                'sort'         => $request->get('sort', 'id'),
                'direction'    => $request->get('direction', 'desc'),
                'per_page'     => $perPage,
                // Filtros avanzados: array de clausulas {field, op, value}
                // que el drawer construye. Lo persisto para que al recargar
                // la pagina (paginate, sort) el filtro siga aplicado.
                'advanced_where' => $this->parseAdvancedWhere($request),
            ],
            'isSuper'        => $isSuper,
            // Schema de campos filtrables â€” alimenta el drawer "Filtros
            // avanzados" del frontend (selects de field/op + control tipado
            // del valor). Cada modulo declara el suyo en su modelo.
            'filterSchema'   => Transformer::filterSchema([
                'customers'         => $customerOptions,
                'substations'       => $substationOptions,
                'transformer_types' => $transformerTypeOptions,
                'oil_types'         => $oilTypeOptions,
                'brands'            => $brandOptions,
                'connection_types'  => $connectionTypeOptions,
                'tap_changer_types' => $tapChangerTypeOptions,
                'preservations'     => $preservationOptions,
            ]),
        ]);
    }

    /**
     * Panel de flota — vista de águila del estado de salud de TODOS los trafos
     * del workspace. Solo lectura, agrega sobre health_rating (ya persistido):
     * conteo por banda + KPIs + los peores. Tenant-scoped por BelongsToTenant.
     */
    public function fleet(Request $request)
    {
        // Filtro opcional por cliente ("flota de un cliente"). Se valida que el
        // id pertenezca al workspace (el scope de Customer ya lo garantiza).
        $customerId = $request->integer('customer_id') ?: null;
        if ($customerId && !\App\Models\Customer::whereKey($customerId)->exists()) {
            $customerId = null;
        }
        $scoped = fn () => Transformer::query()->when($customerId, fn ($q) => $q->where('customer_id', $customerId));

        // Conteo por banda de salud (4=Muy Bueno … 0=Muy Malo; null = sin datos).
        $raw = $scoped()
            ->selectRaw('health_rating, count(*) as c')
            ->groupBy('health_rating')
            ->pluck('c', 'health_rating');

        $bands = [];
        foreach ([4, 3, 2, 1, 0] as $r) {
            $bands[$r] = (int) ($raw[$r] ?? 0);
        }
        $noData = (int) ($raw[''] ?? $raw[null] ?? 0);

        $total      = array_sum($bands) + $noData;
        $diagnosed  = array_sum($bands);
        // "En riesgo" = Malo (1) o Muy Malo (0).
        $atRisk     = $bands[0] + $bands[1];
        // "Empeorando" = el DGAF de cromas subió entre las 2 últimas muestras.
        $worsening  = $scoped()->where('health_trend', 'worsening')->count();

        $cols = ['id', 'slug', 'serial', 'tag', 'customer_id', 'health_index', 'health_rating', 'health_trend'];
        $map  = fn ($t) => [
            'slug'          => $t->slug,
            'serial'        => $t->serial,
            'tag'           => $t->tag,
            'customer'      => $t->customer?->name,
            'health_index'  => $t->health_index,
            'health_rating' => $t->health_rating,
            'health_trend'  => $t->health_trend,
        ];

        // Los peores: rating ascendente (0 primero), luego índice ascendente.
        $worst = $scoped()
            ->with('customer:id,name')
            ->whereNotNull('health_rating')
            ->orderBy('health_rating')
            ->orderBy('health_index')
            ->limit(25)
            ->get($cols);

        // Empeorando: alerta temprana. El peor+empeorando arriba. Para estos
        // (≤25) se calcula además la PROYECCIÓN: a este ritmo, en cuántos
        // meses cruzan a la siguiente banda peor.
        $hi = app(\App\Services\Diagnostics\HealthIndexService::class);
        $worseningList = $scoped()
            ->with(['customer:id,name', 'oilType', 'transformerType'])
            ->where('health_trend', 'worsening')
            ->orderBy('health_rating')
            ->orderBy('health_index')
            ->limit(25)
            // + FKs de aceite/tipo: el motor de cromas resuelve el rule_set con ellos.
            ->get(array_merge($cols, ['oil_type_id', 'transformer_type_id']))
            ->map(function ($t) use ($hi, $map) {
                $row = $map($t);
                $f = $hi->cromasForecast($t);
                // > 5 años de horizonte: la extrapolación deja de ser informativa.
                $row['forecast'] = ($f && $f['months'] <= 60) ? $f : null;
                return $row;
            });

        return inertia('Transformers/Fleet', [
            'bands'      => $bands,
            'noData'     => $noData,
            'total'      => $total,
            'diagnosed'  => $diagnosed,
            'atRisk'     => $atRisk,
            'worsening'  => $worsening,
            'worst'      => $worst->map($map),
            'worseningList' => $worseningList, // ya mapeada (incluye forecast)
            // Tope del PDF del reporte de flota (para el check verde/rojo en el modal).
            'pdfCap'     => \App\Models\Setting::getInt('fleet_report.pdf_max_transformers', 50),
            // Selector de "flota por cliente".
            'customerOptions'  => $this->customerOptions(),
            'selectedCustomer' => $customerId,
        ]);
    }

    /**
     * Reporte de flota consolidado (Excel con pestañas): todas las pruebas de
     * todos los transformadores del cliente en un solo libro. Async → Download
     * → campana, como los demás exports. Scope-safe (tenant + clientes
     * asignados los aplica el job). `customer_id` opcional acota a un cliente.
     */
    public function fleetReportExcel(Request $request): RedirectResponse
    {
        GenerateFleetReportExcelJob::dispatch(
            auth()->id(),
            $this->resolveFleetCustomer($request),
            $this->resolveSelectedTransformers($request),
        );
        return back()->with('success', __('global.download_in_queue'));
    }

    /** Reporte de flota en CSV (resumen plano, 1 fila por trafo). */
    public function fleetReportCsv(Request $request): RedirectResponse
    {
        GenerateFleetReportCsvJob::dispatch(auth()->id(), $this->resolveFleetCustomer($request));
        return back()->with('success', __('global.download_in_queue'));
    }

    /** Reporte de flota en PDF (presentable, capado). */
    public function fleetReportPdf(Request $request): RedirectResponse
    {
        GenerateFleetReportPdfJob::dispatch(auth()->id(), $this->resolveFleetCustomer($request));
        return back()->with('success', __('global.download_in_queue'));
    }

    /** customer_id validado (el scope de Customer ya acota al tenant). */
    /**
     * IDs elegidos en el índice para el reporte de flota. Se validan como
     * enteros y se acotan (el scope real lo aplica igual la query del job).
     *
     * @return int[]|null  null = sin selección → toda la flota del alcance.
     */
    protected function resolveSelectedTransformers(Request $request): ?array
    {
        $ids = collect($request->input('transformer_ids', []))
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->unique()->values();

        return $ids->isEmpty() ? null : $ids->take(5000)->all();
    }

    protected function resolveFleetCustomer(Request $request): ?int
    {
        $customerId = $request->integer('customer_id') ?: null;
        if ($customerId && !\App\Models\Customer::whereKey($customerId)->exists()) {
            $customerId = null;
        }
        return $customerId;
    }

    /**
     * Normaliza `advanced_where` del request: viene como JSON string o
     * array directo segun como Inertia lo serialice. Filtra clausulas
     * vacias o incompletas antes de pasarlo al frontend.
     */
    protected function parseAdvancedWhere(\Illuminate\Http\Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        if (!is_array($raw)) return [];

        return array_values(array_filter($raw, fn ($c) =>
            is_array($c) && !empty($c['field']) && !empty($c['op'])
        ));
    }

    /**
     * Informe consolidado del transformador (PDF): cliente + equipo + índice de
     * salud + cromas (tabla + tendencias + diagnóstico) + fiquis + furanos +
     * conclusiones. El logo del tenant y del cliente se resuelven de DATOS
     * (no mandrakeados): se embeben como data-URI. Reusa TransformerShowPayload.
     */
    public function report(Request $request, Transformer $transformer, \App\Services\Reports\TransformerReportService $reports)
    {
        // Gráficos capturados por el navegador (echarts → PNG data-URIs).
        $charts = collect($request->input('images', []))
            ->filter(fn ($i) => is_array($i) && is_string($i['dataURL'] ?? null)
                && str_starts_with($i['dataURL'], 'data:image/png;base64,'))
            ->map(fn ($i) => ['label' => (string) ($i['label'] ?? ''), 'group' => (string) ($i['group'] ?? ''), 'dataURL' => $i['dataURL']])
            ->values()->all();

        // El servicio arma el PDF, estampa la firma del preparador (este user
        // ejecuta la acción) y escribe el audit de emisión.
        $pdf = $reports->pdf($transformer, $charts, $request->user());

        return $pdf->download($transformer->reportFilename('informe'));
    }

    /**
     * Informe BORRADOR en Word (.docx), editable. Solo para UN trafo — la flota
     * no lo ofrece: un borrador se corrige trafo por trafo.
     *
     * NO pasa por el flujo de aprobación aunque el workspace lo exija: no es un
     * entregable (sin QR ni firmas), es material de trabajo interno.
     */
    public function reportWord(Request $request, Transformer $transformer, \App\Services\Reports\TransformerReportService $reports)
    {
        $charts = collect($request->input('images', []))
            ->filter(fn ($i) => is_array($i) && is_string($i['dataURL'] ?? null)
                && str_starts_with($i['dataURL'], 'data:image/png;base64,'))
            ->map(fn ($i) => ['label' => (string) ($i['label'] ?? ''), 'group' => (string) ($i['group'] ?? ''), 'dataURL' => $i['dataURL']])
            ->values()->all();

        $ruta = $reports->word($transformer, $charts, $request->user());

        return response()->download($ruta, $transformer->reportFilename('informe', 'docx'))
            ->deleteFileAfterSend(true);
    }

    /**
     * Envía el informe de UN trafo al flujo de aprobación (etapa 2 de firmas).
     * Solo cuando el workspace lo exige. Crea una solicitud con 1 informe; los
     * firmantes la aprueban desde "Aprobaciones". El PDF sale recién al emitirse.
     */
    public function sendForApproval(Request $request, Transformer $transformer, \App\Services\Reports\TransformerReportService $reports, \App\Services\Reports\ReportApprovalService $approvals)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant && $approvals->isRequired($tenant), 403);

        // No duplicar: si ya hay una solicitud en revisión para este trafo, corta.
        $pending = \App\Models\ReportInstance::where('transformer_id', $transformer->id)
            ->where('status', 'in_review')->exists();
        if ($pending) {
            return back()->with('error', __('approvals.already_pending'));
        }

        $charts = collect($request->input('images', []))
            ->filter(fn ($i) => is_array($i) && is_string($i['dataURL'] ?? null)
                && str_starts_with($i['dataURL'], 'data:image/png;base64,'))
            ->map(fn ($i) => ['label' => (string) ($i['label'] ?? ''), 'group' => (string) ($i['group'] ?? ''), 'dataURL' => $i['dataURL']])
            ->values()->all();

        $data = $reports->approvalData($transformer, $charts);

        $approvals->createRequest(
            tenant: $tenant,
            requester: $request->user(),
            scope: 'transformer',
            instances: [[
                'transformer' => $transformer,
                'snapshot'    => $data['snapshot'],
                'report_code' => $data['report_code'],
                'verify_code' => $data['verify_code'],
            ]],
            label: $transformer->serial ?: $transformer->tag,
        );

        return back()->with('success', __('approvals.sent_ok'));
    }

    public function show(Request $request, Transformer $transformer)
    {
        $transformer->load([
            'creator:id,name,email', 'deleter:id,name,email', 'locker:id,name',
            // `code` es CLAVE: el motor de diagnóstico (cromas/fiquis) resuelve las
            // reglas por oilType->code y transformerType->code. Sin él, todo sale "—".
            // tapChangerType->code también es clave: DETC muestra marca+modelo,
            // OLTC además la tecnología (misma regla que el Form).
            'customer:id,name', 'oilType:id,name,code', 'transformerType:id,name,code,shape', 'brand:id,name', 'tapChangerType:id,name,code',
            'tapChangerBrand:id,name', 'tapChangerModel:id,name', 'tapChangerTechnology:id,name',
            'connectionType:id,name', 'preservation:id,name',
            // Cadena con trashed: un ancestro borrado debe mostrarse "(eliminado)".
            'substation' => fn ($q) => $q->withTrashed(),
            'substation.area' => fn ($q) => $q->withTrashed(),
            'substation.area.location' => fn ($q) => $q->withTrashed(),
        ]);

        // Track recent view (best-effort, no rompe la pantalla si falla). El
        // índice solo trackea al abrir el drawer; la página show completa (acceso
        // directo por URL o navegación) también debe contar como "visto".
        if ($userId = $request->user()?->id) {
            try {
                \App\Models\UserRecentView::track($userId, Transformer::class, $transformer->id);
            } catch (\Throwable $e) {
                // silent fail
            }
        }

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        // El historial arranca en 40 y crece de a 40 con "Ver más" (sin tope:
        // la query devuelve solo lo que exista, asi que ver "todo" es posible).
        $activityLimit = max((int) $request->integer('activity_limit', 40), 40);
        $activityRows = $canSeeAudit ? $this->transformerActivity($transformer, $activityLimit + 1) : [];
        $activityHasMore = count($activityRows) > $activityLimit;
        $activity = array_slice($activityRows, 0, $activityLimit);
        // Quién creó/actualizó el REGISTRO. Se saca del audit log completo (no del
        // feed capado a 40): el trafo no tiene columna updated_by, y el evento
        // 'created' puede caer fuera de la ventana del feed.
        $recordAudit = $this->recordAuditMeta($transformer);

        // Calcula el Índice de Salud en vivo (combina las pruebas con datos) y
        // refresca la caché del transformador. El resumen por prueba alimenta el
        // dashboard del detalle.
        $hi = app(\App\Services\Diagnostics\HealthIndexService::class)->evaluate($transformer);

        // El armado de las pestañas de pruebas (cromas/furanos/fiquis/fpot/duval/
        // bitácora) vive en un presenter dedicado para no engordar el controlador.
        return inertia('Transformers/Show', array_merge([
            'transformer'  => array_merge(
                $this->payload($transformer, withAudit: true),
                ['lock' => $this->lockMeta($transformer, $request)],
            ),
            'activity'     => $activity,
            'activityHasMore' => $activityHasMore,
            'activityLimit'   => $activityLimit,
            'recordAudit'  => $recordAudit,
            'diagnostics'  => $hi->toArray(),
            // Editor de muestras: habilitado por transformers.samples O .edit
            // (mismo gate que las rutas que guardan muestras). Antes solo miraba
            // .edit → un perfil "carga de muestras" veía la tabla pero no el editor.
            'canEditTests' => $request->user()?->canAny(['transformers.samples', 'transformers.edit']) ?? false,
            // Catálogo de laboratorios (per-tenant, auto-scoped) para el select de
            // las grillas de muestras (columna Laboratorio). Solo activos.
            'laboratories' => \App\Models\Laboratory::where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            // Si el caché de gráficos del informe está vacío/viejo, el front lo
            // repuebla en background al abrir esta página (los echarts ya están
            // montados). Así el PDF del portal compartido — incluso de FLOTA,
            // donde no hay navegador que capture — sale con los gráficos reales.
            'chartCacheStale' => app(\App\Services\Reports\ReportChartCache::class)->get($transformer) === null,
        ], app(\App\Support\Transformers\TransformerShowPayload::class)->build($transformer)));
    }

    /**
     * Recibe los gráficos del informe capturados por el navegador (echarts →
     * PNG data-URIs) y los cachea. Lo llama Show.vue en background cuando el
     * caché está vacío/viejo. Fire-and-forget: no afecta la navegación.
     */
    public function storeReportCharts(Request $request, Transformer $transformer)
    {
        $charts = collect($request->input('images', []))
            ->filter(fn ($i) => is_array($i) && is_string($i['dataURL'] ?? null)
                && str_starts_with($i['dataURL'], 'data:image/png;base64,'))
            ->map(fn ($i) => ['label' => (string) ($i['label'] ?? ''), 'group' => (string) ($i['group'] ?? ''), 'dataURL' => $i['dataURL']])
            ->values()->all();

        app(\App\Services\Reports\ReportChartCache::class)->store($transformer, $charts);

        return response()->json(['cached' => count($charts)]);
    }

    /**
     * Guarda la selección MANUAL de muestras para Tabla 4 (IEEE C57.104). Reemplaza
     * el cálculo automático: el diagnóstico DGA pasa a usar exactamente estas
     * muestras. Los IDs se validan contra las cromatografías del propio
     * transformador (scope-safe) — no se confía en lo que manda el front.
     */
    public function saveDgaRateSelection(Request $request, Transformer $transformer)
    {
        abort_unless($request->user()?->canAny(['transformers.samples', 'transformers.edit']), 403);
        $data = $request->validate([
            'sample_ids'   => 'required|array|min:3|max:6',
            'sample_ids.*' => 'integer',
        ]);
        $valid = $transformer->chromatographicals()
            ->whereIn('id', $data['sample_ids'])->pluck('id')->all();
        abort_if(count($valid) < 3, 422, __('transformers.ieee_dga.min_samples'));

        $transformer->update(['dga_rate_sample_ids' => array_values($valid)]);

        return back()->with('success', __('global.updated_success'));
    }

    /** Vuelve al modo AUTOMÁTICO de Tabla 4 (borra la selección manual). */
    public function clearDgaRateSelection(Request $request, Transformer $transformer)
    {
        abort_unless($request->user()?->canAny(['transformers.samples', 'transformers.edit']), 403);
        $transformer->update(['dga_rate_sample_ids' => null]);

        return back()->with('success', __('global.updated_success'));
    }

    public function create()
    {
        return inertia('Transformers/Form', [
            'transformer'         => null,
            'customerOptions'         => $this->customerOptions(),
            'countryOptions'          => $this->countryOptions(),
            'oilTypeOptions'          => $this->oilTypeOptions(),
            'transformerTypeOptions'  => $this->transformerTypeOptions(),
            'brandOptions'            => $this->brandOptions(),
            'tapChangerTypeOptions'   => $this->tapChangerTypeOptions(),
            'tapChangerBrandOptions'      => $this->tapChangerBrandOptions(),
            'tapChangerModelOptions'      => $this->tapChangerModelOptions(),
            'tapChangerTechnologyOptions' => $this->tapChangerTechnologyOptions(),
            'connectionTypeOptions'   => $this->connectionTypeOptions(),
            'preservationOptions'     => $this->preservationOptions(),
            'substationOptions'       => $this->substationOptions(),
            'tenantOptions'           => $this->tenantOptions(),
        ]);
    }

    /** Workspaces activos como Select options — solo para super (vacío si no). */
    protected function tenantOptions(): array
    {
        if (! (auth()->user()?->hasRole('super') ?? false)) return [];
        return \App\Models\Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])
            ->all();
    }

    /**
     * Cadena Ubicación › Área › Subestación del transformador, con flag `deleted`
     * por nivel (un ancestro soft-deleted se muestra "(eliminado)" en el detalle).
     * Null si el trafo no está asignado a una subestación o la relación no se cargó.
     */
    protected function substationPath(Transformer $m): ?array
    {
        if (!$m->relationLoaded('substation') || !$m->substation) {
            return null;
        }
        $sub = $m->substation;
        $area = $sub->area;
        $loc = $area?->location;

        return [
            'location'   => $loc  ? ['name' => $loc->name,  'deleted' => $loc->deleted_at !== null]  : null,
            'area'       => $area ? ['name' => $area->name, 'deleted' => $area->deleted_at !== null] : null,
            'substation' => ['name' => $sub->name, 'deleted' => $sub->deleted_at !== null],
        ];
    }

    /** Clientes activos del workspace como Select options. */
    protected function customerOptions(): array
    {
        return \App\Models\Customer::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => mb_strtoupper($c->name)])
            ->all();
    }

    /** Países activos como options (para el alta rápida de cliente en el form). */
    protected function countryOptions(): array
    {
        return \App\Models\Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->iso_code . ')'])
            ->all();
    }

    /**
     * Subestaciones (tenant-scoped) como options, cada una con su customer_id
     * y la ruta "Ubicación › Área › Subestación". El form filtra por el cliente
     * seleccionado en el cliente (sin AJAX). Solo subestaciones no eliminadas.
     */
    protected function substationOptions(): array
    {
        return \App\Models\CustomerSubstation::with(['area.location'])
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'value'       => $s->id,
                'customer_id' => $s->area?->location?->customer_id,
                'label'       => ($s->area?->location?->name ?? '—') . ' › ' . ($s->area?->name ?? '—') . ' › ' . $s->name,
            ])
            ->filter(fn ($o) => $o['customer_id'] !== null)
            ->values()
            ->all();
    }

    /** Catálogo global de tipos de aceite activos. */
    protected function oilTypeOptions(): array
    {
        return \App\Models\OilType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($o) => ['value' => $o->id, 'label' => $o->display_name])
            ->all();
    }

    /** Catálogo global de tipos de transformador activos. */
    protected function transformerTypeOptions(): array
    {
        return \App\Models\TransformerType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($tt) => ['value' => $tt->id, 'label' => $tt->name])
            ->all();
    }

    /** Catálogo global de marcas/fabricantes activos. */
    protected function brandOptions(): array
    {
        return \App\Models\Brand::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])
            ->all();
    }

    /** Catálogo global de tipos de conmutador activos. */
    protected function tapChangerTypeOptions(): array
    {
        return \App\Models\TapChangerType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name, 'code' => $t->code])
            ->all();
    }

    /** Catálogo per-tenant de marcas de conmutador activas. */
    protected function tapChangerBrandOptions(): array
    {
        return \App\Models\TapChangerBrand::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])
            ->all();
    }

    /** Catálogo per-tenant de modelos de conmutador activos. */
    protected function tapChangerModelOptions(): array
    {
        return \App\Models\TapChangerModel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($m) => ['value' => $m->id, 'label' => $m->name])
            ->all();
    }

    /** Catálogo per-tenant de tecnologías de conmutador activas (solo OLTC). */
    protected function tapChangerTechnologyOptions(): array
    {
        return \App\Models\TapChangerTechnology::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])
            ->all();
    }

    /** Catálogo global de grupos de conexión activos. */
    protected function connectionTypeOptions(): array
    {
        return \App\Models\ConnectionType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
            ->all();
    }

    /** Catálogo global de sistemas de preservación del aceite activos. */
    protected function preservationOptions(): array
    {
        return \App\Models\TransformerPreservation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])
            ->all();
    }

    public function store(StoreTransformerRequest $request, TransformerService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant â†’ no aplica. -1 = ilimitado.
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Transformer::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $transformer = $service->create($request->validated());

        // Tras crear, ir al "ver" del transformador (igual que clientes).
        return redirect()
            ->route('business_management.transformers.show', $transformer->slug)
            ->with('success', __('transformers.created'));
    }

    public function edit(Transformer $transformer)
    {
        // Registro bloqueado (Lockable): ni se abre el formulario de edición.
        abort_if($transformer->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('Transformers/Form', [
            'transformer'         => $this->payload($transformer),
            'customerOptions'         => $this->customerOptions(),
            'countryOptions'          => $this->countryOptions(),
            'oilTypeOptions'          => $this->oilTypeOptions(),
            'transformerTypeOptions'  => $this->transformerTypeOptions(),
            'brandOptions'            => $this->brandOptions(),
            'tapChangerTypeOptions'   => $this->tapChangerTypeOptions(),
            'tapChangerBrandOptions'      => $this->tapChangerBrandOptions(),
            'tapChangerModelOptions'      => $this->tapChangerModelOptions(),
            'tapChangerTechnologyOptions' => $this->tapChangerTechnologyOptions(),
            'connectionTypeOptions'   => $this->connectionTypeOptions(),
            'preservationOptions'     => $this->preservationOptions(),
            'substationOptions'       => $this->substationOptions(),
            'tenantOptions'           => $this->tenantOptions(),
        ]);
    }


    public function update(UpdateTransformerRequest $request, Transformer $transformer, TransformerService $service): RedirectResponse
    {
        $service->update($transformer, $request->validated());

        return redirect()
            ->route('business_management.transformers.show', $transformer->slug)
            ->with('success', __('transformers.saved'));
    }

    public function delete(Transformer $transformer)
    {
        // Registro bloqueado (Lockable): ni se abre la confirmación de borrado.
        abort_if($transformer->is_locked, 403, __('locks.cannot_delete_locked'));

        return inertia('Transformers/Delete', [
            'transformer' => $this->payload($transformer),
        ]);
    }

    public function deleteSave(DeleteTransformerRequest $request, Transformer $transformer, TransformerService $service): RedirectResponse
    {
        $service->delete($transformer, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$transformer->id]);

        return redirect()
            ->route('business_management.transformers.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$transformer->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['transformers.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds(60)->toIso8601String(),
        ]]);
    }

    /** Payload que va al frontend via flash para disparar el toast. */
    protected function buildRecentDeletePayload(array $ids): array
    {
        return [
            'count'   => count($ids),
            'seconds' => 60,
        ];
    }

    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $serial  = $request->get('serial', '');
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        $transformers = Transformer::onlyTrashed()
            ->with('deleter:id,name,email')
            ->when($serial !== '', fn ($q) => $q->where('serial', 'like', "%{$serial}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Transformers/Trash', [
            'transformers' => $transformers,
            'filters'   => [
                'serial'   => $serial,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, TransformerService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = Transformer::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($model);

        return redirect()
            ->route('business_management.transformers.trash')
            ->with('success', __('global.restored_success'));
    }

    /**
     * Edit All â€” pagina con tabla editable in-line de serial.
     * El submit hace batch update en transaccion (editAllUpdate).
     */
    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        $transformers = Transformer::query()
            ->filter($request)
            ->select('transformers.id', 'transformers.slug', 'transformers.serial', 'transformers.tag')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Transformers/EditAll', [
            'transformers' => $transformers,
            'filters'   => [
                'serial'    => $request->get('serial', ''),
                'sort'      => $request->get('sort', 'id'),
                'direction' => $request->get('direction', 'asc'),
                'per_page'  => $perPage,
            ],
        ]);
    }

    public function editAllUpdate(EditAllUpdateTransformerRequest $request, TransformerService $service): RedirectResponse
    {
        $changes = $request->validated()['changes'];

        // Excluir registros BLOQUEADOS (Lockable) de la edición masiva.
        $ids = array_column($changes, 'id');
        [, $lockedIds] = $this->splitLockedIds(Transformer::class, $ids);
        if (!empty($lockedIds)) {
            $lockedSet = array_flip($lockedIds);
            $changes = array_values(array_filter($changes, fn ($c) => !isset($lockedSet[(int) $c['id']])));
        }

        $touched = $service->editAllUpdate($changes);

        $msg = __('global.updated_success') . " ({$touched})";
        if (!empty($lockedIds)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]);
        }

        return redirect()
            ->route('business_management.transformers.edit_all')
            ->with('success', $msg);
    }

    /**
     * Clona el transformer. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, Transformer $transformer, TransformerService $service): RedirectResponse
    {
        // Duplicar crea un registro nuevo → respeta el límite del plan (como store).
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Transformer::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $clone = $service->duplicate($transformer);

        if (!$clone) {
            return back()->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('business_management.transformers.index')
            ->with('success', __('global.duplicated_success'));
    }

    public function bulkRestore(BulkRestoreTransformerRequest $request, TransformerService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('business_management.transformers.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('business_management.transformers.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function forceDelete(ForceDeleteTransformerRequest $request, $slug, TransformerService $service): RedirectResponse
    {
        $model = Transformer::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        if (trim($data['name_confirmation']) !== (string) $model->serial) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($model, $data['reason']);

        return redirect()
            ->route('business_management.transformers.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    /**
     * Feed de auditoría del transformador + sus muestras (cromas/furanos/fiquis/
     * fpot), para que lo que se hace en cada PESTAÑA también aparezca en "Cambios"
     * (antes solo salían los cambios del propio trafo). Mismo patrón que
     * customerActivity(): cada evento de una muestra lleva un `subject` con la
     * prueba + la fecha de muestreo, para dar contexto. withTrashed: las muestras
     * borradas conservan su rastro en el feed.
     */
    /**
     * Humaniza el diff de un evento 'updated' del trafo: nombre de campo
     * traducido + valores legibles. Resuelve las FK a su nombre (conmutador →
     * OLTC, tipo de conexión → Dyn5, marca, aceite…), traduce el enum de fases
     * y los booleanos. Sin esto el historial mostraba `connection_type_id: 5 → 7`.
     */
    protected function humanizeTransformerDiff(array $old, array $next): array
    {
        // Columna FK → modelo para resolver el id a nombre.
        $fk = [
            'customer_id'                 => \App\Models\Customer::class,
            'customer_substation_id'      => \App\Models\CustomerSubstation::class,
            'oil_type_id'                 => \App\Models\OilType::class,
            'transformer_type_id'         => \App\Models\TransformerType::class,
            'brand_id'                    => \App\Models\Brand::class,
            'tap_changer_type_id'         => \App\Models\TapChangerType::class,
            'connection_type_id'          => \App\Models\ConnectionType::class,
            'transformer_preservation_id' => \App\Models\TransformerPreservation::class,
        ];
        // Columna → clave i18n de la etiqueta (transformers.*).
        $labels = [
            'serial' => 'serial', 'tag' => 'tag', 'voltage_kv' => 'voltage_kv',
            'power_mva' => 'power_mva', 'manufacture_year' => 'manufacture_year',
            'phases' => 'phases', 'paper_type' => 'paper_type', 'oil_treated_at' => 'oil_treated_at',
            'customer_id' => 'customer', 'customer_substation_id' => 'substation',
            'oil_type_id' => 'oil_type', 'transformer_type_id' => 'transformer_type',
            'brand_id' => 'brand', 'tap_changer_type_id' => 'tap_changer_type',
            'connection_type_id' => 'connection_type', 'transformer_preservation_id' => 'preservation',
        ];
        // Columnas internas/caché que no edita el usuario → no mostrar.
        $ignore = ['updated_at', 'created_at', 'slug', 'health_index', 'health_rating',
            'fault_type', 'gassing_rate', 'paper_dp', 'paper_life_years', 'ieee_condition',
            'dga_rate_sample_ids', 'forecast_months', 'forecast_target',
            'deleted_by', 'deleted_description', 'deleted_at', 'tenant_id'];

        $cache = [];
        $fmt = function ($col, $value) use ($fk, &$cache) {
            if ($value === null || $value === '') return '—';
            if (is_bool($value)) return $value ? __('global.yes') : __('global.no');
            if (isset($fk[$col])) {
                $model = $fk[$col];
                $key = $model . ':' . $value;
                if (!array_key_exists($key, $cache)) {
                    // withTrashed solo si el modelo es soft-deletable (si no, revienta).
                    $q = method_exists(new $model, 'trashed') ? $model::withTrashed() : $model::query();
                    $cache[$key] = $q->find($value)?->name;
                }
                return $cache[$key] ?: ('#' . $value);
            }
            if ($col === 'phases') return __('transformers.phases_' . $value);
            if (is_array($value)) return implode(', ', $value);
            return (string) $value;
        };

        $rows = [];
        foreach (array_keys(array_merge($old, $next)) as $col) {
            if (in_array($col, $ignore, true)) continue;
            $before = $fmt($col, $old[$col] ?? null);
            $after  = $fmt($col, $next[$col] ?? null);
            if ($before === $after) continue;
            $rows[] = [
                'label'  => isset($labels[$col]) ? __('transformers.' . $labels[$col]) : ucfirst(str_replace('_', ' ', $col)),
                'before' => $before,
                'after'  => $after,
            ];
        }
        return $rows;
    }

    protected function transformerActivity(Transformer $transformer, int $limit = 40): array
    {
        $children = [
            \App\Models\Chromatographical::class => ['ids' => $transformer->chromatographicals()->withTrashed()->pluck('id'), 'label' => __('cromas.tab')],
            \App\Models\Furano::class            => ['ids' => $transformer->furanos()->withTrashed()->pluck('id'),            'label' => __('furanos.tab')],
            \App\Models\Fiqui::class             => ['ids' => $transformer->fiquis()->withTrashed()->pluck('id'),             'label' => __('fiquis.tab')],
            \App\Models\Fpot::class              => ['ids' => $transformer->fpots()->withTrashed()->pluck('id'),              'label' => __('fpot.tab')],
        ];

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->where(function ($q) use ($transformer, $children) {
                $q->where(fn ($w) => $w->where('auditable_type', Transformer::class)->where('auditable_id', $transformer->id));
                foreach ($children as $type => $meta) {
                    if ($meta['ids']->isNotEmpty()) {
                        $q->orWhere(fn ($w) => $w->where('auditable_type', $type)->whereIn('auditable_id', $meta['ids']));
                    }
                }
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'user_id', 'auditable_type', 'auditable_id', 'event', 'old_values', 'new_values', 'created_at']);

        return $logs->map(function ($log) use ($children) {
            // El sujeto del trafo raíz no necesita etiqueta (ya es el contexto).
            $subject = null;
            if (isset($children[$log->auditable_type])) {
                $date = $log->new_values['sample_date'] ?? $log->old_values['sample_date'] ?? null;
                $date = $date ? substr((string) $date, 0, 10) : null;
                $subject = trim($children[$log->auditable_type]['label'] . ($date ? " · {$date}" : ''));
            }

            // Diff humanizado solo para ediciones del trafo raíz (las pruebas
            // hijas tienen otras columnas; caen al render crudo del front).
            $isRoot = $log->auditable_type === Transformer::class;

            return [
                'id'         => $log->id,
                'event'      => $log->event,
                'subject'    => $subject,
                'user'       => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'changes'    => $log->event === 'updated'
                    ? ($isRoot
                        ? $this->humanizeTransformerDiff($log->old_values ?? [], $log->new_values ?? [])
                        : $this->humanizeChildDiff($log->auditable_type, $log->old_values ?? [], $log->new_values ?? []))
                    : null,
                'created_at' => $log->created_at,
            ];
        })->all();
    }

    /** Etiquetas legibles de las columnas de cada prueba hija (diff del historial). */
    private function childFieldLabels(string $type): array
    {
        $date = ['sample_date' => __('diagnostics.date')];
        return match ($type) {
            \App\Models\Chromatographical::class => $date + [
                'h2' => 'H₂', 'o2' => 'O₂', 'n2' => 'N₂', 'ch4' => 'CH₄', 'co' => 'CO',
                'co2' => 'CO₂', 'c2h4' => 'C₂H₄', 'c2h6' => 'C₂H₆', 'c2h2' => 'C₂H₂',
            ],
            \App\Models\Fiqui::class => $date + collect(\App\Models\Fiqui::FIELDS)
                ->mapWithKeys(fn ($f) => [$f => __('fiquis.' . $f)])->all(),
            \App\Models\Furano::class => $date + [
                'fal' => '2FAL', 'hme' => '5HMF', 'ace' => '2ACF', 'mfu' => '5MEF', 'fua' => '2FOL',
            ],
            \App\Models\Fpot::class => $date + [
                'value' => __('fpot.value'), 'temperature' => __('fpot.temperature'),
            ],
            default => [],
        };
    }

    /**
     * Diff legible de una edición de prueba hija: traduce las columnas a sus
     * etiquetas (gases, parámetros…) y omite las columnas internas/caché. Solo
     * recorre los campos que cambiaron (new_values = getChanges).
     */
    private function humanizeChildDiff(string $type, array $old, array $next): array
    {
        $labels = $this->childFieldLabels($type);
        if (empty($labels)) {
            return [];
        }
        $fmt = function ($v) {
            if ($v === null || $v === '') return '—';
            if (is_numeric($v)) {
                return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
            }
            return (string) $v;
        };
        $rows = [];
        foreach ($next as $field => $newVal) {
            if (!isset($labels[$field])) continue;   // ignora caché/internos
            $before = $field === 'sample_date' ? substr((string) ($old[$field] ?? ''), 0, 10) : $fmt($old[$field] ?? null);
            $after  = $field === 'sample_date' ? substr((string) $newVal, 0, 10) : $fmt($newVal);
            if ($before === $after) continue;
            $rows[] = ['label' => $labels[$field], 'before' => $before ?: '—', 'after' => $after ?: '—'];
        }
        return $rows;
    }

    protected function payload(Transformer $m, bool $withAudit = false): array
    {
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'serial'     => $m->serial,
            'tag'        => $m->tag,
            'customer_id' => $m->customer_id,
            'customer_substation_id' => $m->customer_substation_id,
            'oil_type_id' => $m->oil_type_id,
            'transformer_type_id' => $m->transformer_type_id,
            'brand_id'    => $m->brand_id,
            'tap_changer_type_id' => $m->tap_changer_type_id,
            'tap_changer_brand_id'      => $m->tap_changer_brand_id,
            'tap_changer_model_id'      => $m->tap_changer_model_id,
            'tap_changer_technology_id' => $m->tap_changer_technology_id,
            'connection_type_id'  => $m->connection_type_id,
            'transformer_preservation_id' => $m->transformer_preservation_id,
            // Specs + diagnóstico (para la vista de detalle).
            'voltage_kv'       => $m->voltage_kv,
            'power_mva'        => $m->power_mva,
            'manufacture_year' => $m->manufacture_year,
            'paper_type'       => $m->paper_type,
            'phases'           => $m->phases,
            'oil_treated_at'   => optional($m->oil_treated_at)->format('Y-m-d'),
            'tenant_id'        => $m->tenant_id,
            'is_locked'        => $m->is_locked,
            'lock_scope'       => $m->lock_scope,
            'health_index'     => $m->health_index,
            'health_rating'    => $m->health_rating,
            'health_trend'     => $m->health_trend,
            'customer'         => $m->relationLoaded('customer') && $m->customer ? ['id' => $m->customer->id, 'name' => $m->customer->name] : null,
            'substation_path'  => $this->substationPath($m),
            'oil_type'         => $m->relationLoaded('oilType') && $m->oilType ? ['id' => $m->oilType->id, 'name' => $m->oilType->display_name] : null,
            'transformer_type' => $m->relationLoaded('transformerType') && $m->transformerType ? ['id' => $m->transformerType->id, 'name' => $m->transformerType->name, 'shape' => $m->transformerType->shape] : null,
            'brand'            => $m->relationLoaded('brand') && $m->brand ? ['id' => $m->brand->id, 'name' => $m->brand->name] : null,
            // `code` (detc/oltc/none) gatea qué campos del conmutador se muestran
            // en la ficha (misma regla que el Form: DETC → marca+modelo; OLTC →
            // además tecnología; none → nada).
            'tap_changer_type' => $m->relationLoaded('tapChangerType') && $m->tapChangerType ? ['id' => $m->tapChangerType->id, 'name' => $m->tapChangerType->name, 'code' => $m->tapChangerType->code] : null,
            'tap_changer_brand' => $m->relationLoaded('tapChangerBrand') && $m->tapChangerBrand ? ['id' => $m->tapChangerBrand->id, 'name' => $m->tapChangerBrand->name] : null,
            'tap_changer_model' => $m->relationLoaded('tapChangerModel') && $m->tapChangerModel ? ['id' => $m->tapChangerModel->id, 'name' => $m->tapChangerModel->name] : null,
            'tap_changer_technology' => $m->relationLoaded('tapChangerTechnology') && $m->tapChangerTechnology ? ['id' => $m->tapChangerTechnology->id, 'name' => $m->tapChangerTechnology->name] : null,
            'connection_type'  => $m->relationLoaded('connectionType') && $m->connectionType ? ['id' => $m->connectionType->id, 'name' => $m->connectionType->name] : null,
            'preservation'     => $m->relationLoaded('preservation') && $m->preservation ? ['id' => $m->preservation->id, 'name' => $m->preservation->name] : null,
            'is_favorite' => (bool) ($m->is_favorite ?? false),
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
            'deleted_at' => $m->deleted_at,
        ];
        if ($withAudit) {
            $base['deleted_description'] = $m->deleted_description;
            $base['creator'] = $m->creator ? ['id' => $m->creator->id, 'name' => $m->creator->name, 'email' => $m->creator->email] : null;
            $base['deleter'] = $m->deleter ? ['id' => $m->deleter->id, 'name' => $m->deleter->name, 'email' => $m->deleter->email] : null;
        }
        return $base;
    }

    // â”€â”€ EXPORTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Los 4 formatos van a queue como jobs async (mismo patron que Regions).
    // El job se encarga de la query con scope + render + Download record.

    public function exportCsv(Request $request)
    {
        return $this->dispatchExport($request, 'csv', GenerateTransformersCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateTransformersExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateTransformersPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateTransformersWordJob::class);
    }

    /**
     * Helper comun de los 4 export endpoints: parse options â†’ limit check â†’
     * audit â†’ dispatch. Mismo patron que Region.
     */
    protected function dispatchExport(Request $request, string $format, string $jobClass): RedirectResponse
    {
        $options = $this->buildExportOptions($request, $format);
        $this->assertExportLimit($format, $options);
        $this->recordExportAudit($format, $options);
        $jobClass::dispatch(auth()->id(), $options);

        return back()->with('success', __('global.download_in_queue'));
    }

    /**
     * Valida que el dataset no exceda el limite del formato. Usuarios con
     * plan premium (feature flag `export_unlimited_rows`) saltean el limite.
     */
    protected function assertExportLimit(string $format, array $options): void
    {
        if (\App\Support\FeatureGate::allows('export_unlimited_rows', auth()->user())
            && config('features.features.export_unlimited_rows') !== null) {
            return;
        }

        $limit = \App\Models\Setting::getExportLimit('transformers', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('transformers.export_limit_exceeded', [
                'count'  => number_format($count),
                'limit'  => number_format($limit),
                'format' => strtoupper($format),
            ]));
        }
    }

    /** Cuenta filas a exportar segun scope+filters. */
    protected function countForExport(array $options): int
    {
        $scope = $options['scope'] ?? 'filtered';

        if ($scope === 'selected') {
            return count($options['selected_ids'] ?? []);
        }
        if ($scope === 'all') {
            return Transformer::query()->count();
        }
        // Filters como Request para reusar scopeFilter.
        $fakeReq = new Request($options['filters'] ?? []);
        return Transformer::query()->filter($fakeReq)->count();
    }

    // â”€â”€ IMPORTS (two-phase: dry_run preview + commit) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\Transformers\TransformersImportTemplate(),
            __('transformers.import_template_filename')
        );
    }

    public function import(ImportTransformerRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Guardrail multi-tenant: super NO puede importar sin tenant porque
        // el lookup por nombre case-insensitive matchearÃ­a transformers de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant especÃ­fico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('transformers.import_super_blocked', [], 'Super sin workspace asignado no puede importar â€” el match por nombre puede actualizar registros de otro tenant.'),
            ], 422);
        }

        $importer = new \App\Imports\BusinessManagement\Transformers\TransformersImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('TransformersImport failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => $this->humanizeImportError($e),
            ], 422);
        }

        return response()->json([
            'ok'      => true,
            'dry_run' => $dryRun,
            'summary' => $importer->summary(),
        ], 200);
    }

    /**
     * Convierte una excepcion de import en mensaje legible para el usuario.
     * El detalle tecnico queda en el log, no llega al cliente.
     */
    protected function humanizeImportError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if ($e instanceof \Illuminate\Database\QueryException) {
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate')) {
                return __('imports.err_unique_violation');
            }
            if (str_contains($msg, 'NOT NULL') || str_contains($msg, 'null value')) {
                return __('imports.err_not_null_violation');
            }
            if (str_contains($msg, 'foreign key') || str_contains($msg, 'violates foreign')) {
                return __('imports.err_foreign_key_violation');
            }
        }

        return __('imports.process_failed');
    }

    // â”€â”€ BULK OPERATIONS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function bulkDelete(BulkDeleteTransformerRequest $request, TransformerService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no se borran en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(Transformer::class, $data['ids']);
        if (empty($allowedIds)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]));
        }

        $result = $service->bulkDelete($allowedIds, $data['deleted_description']);

        if (!empty($result['queued'])) {
            // Async: el delete real ocurre despues del redirect; el undo
            // window de 60s no calza con un job que tarda minutos.
            return back()
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        $deletedIds = $result['deleted'];
        $this->storeUndoableDelete($deletedIds);

        $msg = __('global.deleted_success') . ' (' . count($deletedIds) . ')';
        if (!empty($lockedIds)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]);
        }

        return back()
            ->with('success', $msg)
            ->with('recentDelete', $this->buildRecentDeletePayload($deletedIds));
    }

    /**
     * Undo dentro del window de 60s. Validamos contra session claim:
     * quien borro puede deshacer su propio error sin permisos extra.
     * Defense in depth: el service solo restaura las filas con
     * deleted_by = current user.
     */
    public function undoLastDelete(Request $request, TransformerService $service): RedirectResponse
    {
        $claim = session('transformers.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('transformers.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) auth()->id());

        if (empty($restored)) {
            session()->forget('transformers.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('transformers.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    // â”€â”€ Export helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Opciones normalizadas que reciben todos los jobs de export. Allowlist
     * de columnas previene inyeccion de campos sensibles.
     */
    protected function buildExportOptions(Request $request, string $format): array
    {
        // Columnas válidas = las que el job sabe renderizar (fuente de verdad
        // única). La lista hardcodeada anterior estaba incompleta (solo id/
        // serial/tag/slug/fechas/creator) y rechazaba columnas que el diálogo
        // ofrece por defecto (customer, transformer_type, health_index…): la
        // validación tiraba 422 y el export NUNCA se encolaba.
        $allowedColumns = array_keys(
            \App\Support\Transformers\TransformerExportColumns::definitions(config('app.timezone', 'UTC'))
        );
        // La columna `tenant` (workspace) SOLO es exportable por super: el resto
        // ve únicamente transformadores de su propio tenant. Gate de seguridad
        // real (no basta ocultarla en el ExportDialog del front).
        if (! ($request->user()?->hasRole('super') ?? false)) {
            $allowedColumns = array_values(array_filter($allowedColumns, fn ($k) => $k !== 'tenant'));
        }

        $rules = [
            'scope'                   => 'nullable|in:filtered,selected,all',
            'selected_ids'            => 'array',
            'selected_ids.*'          => 'integer',
            'columns'                 => 'array|min:1',
            'columns.*'               => 'in:' . implode(',', $allowedColumns),
            'title'                   => 'nullable|string|max:120',
            'include_filters_summary' => 'boolean',
            'filters'                 => 'array',
        ];
        if ($format === 'pdf') {
            $rules['orientation'] = 'nullable|in:portrait,landscape';
            $rules['paper_size']  = 'nullable|in:a4,letter';
        }
        if ($format === 'excel') {
            $rules['autofilter']    = 'boolean';
            $rules['freeze_header'] = 'boolean';
        }

        $data = $request->validate($rules);

        return [
            'scope'                   => $data['scope']                   ?? 'filtered',
            'selected_ids'            => $data['selected_ids']            ?? [],
            'columns'                 => $data['columns']                 ?? $allowedColumns,
            'title'                   => $data['title']                   ?? __('transformers.export_title'),
            'include_filters_summary' => $data['include_filters_summary'] ?? true,
            'filters'                 => $data['filters']                 ?? [],
            'orientation'             => $data['orientation']             ?? 'portrait',
            'paper_size'              => $data['paper_size']              ?? 'a4',
            'autofilter'              => $data['autofilter']              ?? true,
            'freeze_header'           => $data['freeze_header']           ?? true,
        ];
    }

    /**
     * Escribe audit log manual del export. Event = 'export_queued' registra
     * la INTENCION del usuario; el estado final (ready/failed) vive en `downloads`.
     */
    protected function recordExportAudit(string $format, array $options): void
    {
        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => 'export_queued',
            'auditable_type' => Transformer::class,
            'auditable_id'   => null,
            'module'         => 'transformers',
            'old_values'     => null,
            'new_values'     => [
                'format'                  => $format,
                'scope'                   => $options['scope']        ?? 'filtered',
                'columns'                 => $options['columns']      ?? [],
                'title'                   => $options['title']        ?? null,
                'orientation'             => $format === 'pdf'   ? ($options['orientation']    ?? null) : null,
                'paper_size'              => $format === 'pdf'   ? ($options['paper_size']     ?? null) : null,
                'autofilter'              => $format === 'excel' ? ($options['autofilter']     ?? null) : null,
                'freeze_header'           => $format === 'excel' ? ($options['freeze_header']  ?? null) : null,
                'include_filters_summary' => $options['include_filters_summary'] ?? false,
                'filters'                 => $options['filters']      ?? [],
                'selected_ids_count'      => count($options['selected_ids'] ?? []),
            ],
            'url'        => route('business_management.transformers.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
