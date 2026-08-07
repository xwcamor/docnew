<?php

namespace App\Http\Controllers\DashboardManagement;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Chromatographical;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DashboardController — landing post-login con widgets reales.
 *
 * Los widgets que devuelve dependen del rol:
 *   - super: vista del sistema completo (tenants, suscripciones, automatizaciones globales).
 *   - admin del tenant: vista del workspace (sus users, su sub, sus automatizaciones).
 *   - user: vista personal (tareas, automatizaciones que le notificaron).
 *
 * Cada widget es un objeto plano para que el frontend lo renderice sin
 * lógica: { label, value, hint, color, icon, href? }.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuper = $user?->hasRole('super') ?? false;

        // Dashboard de flota para el tenant (admin/operativo). Sin "actividad
        // reciente": para eso está el módulo de Logs del sistema.
        if (!$isSuper) {
            return inertia('Dashboard/Index', array_merge([
                'isSuper'           => false,
                'widgets'           => $this->tenantWidgets($user),
                'recentAutomations' => [],
                'expiringSoon'      => [],
            ], $this->tenantFleetDashboard($user, $request)));
        }

        // Super: flota CROSS-TENANT (el scope hace bypass) + desglose por
        // workspace + sus widgets de sistema (suscripciones, automatizaciones).
        return inertia('Dashboard/Index', array_merge([
            'isSuper'           => true,
            'widgets'           => $this->superAdminWidgets(),
            'recentAutomations' => $this->recentAutomations($user),
            'expiringSoon'      => $this->expiringSubscriptions($user),
        ], $this->tenantFleetDashboard($user, $request)));
    }

    /**
     * Últimas 10 acciones del propio user (su audit_log). Para la vista
     * simple del dashboard non-super — "lo que hiciste recientemente".
     *
     * Shape minimal: event, módulo, fecha. No exponemos old/new values aquí
     * (eso vive en el Show de cada registro para el detalle completo).
     */
    protected function recentUserActivity(?User $user): array
    {
        if (!$user) return [];

        return AuditLog::query()
            ->where('user_id', $user->id)
            ->whereIn('event', ['created', 'updated', 'deleted', 'restored', 'exported', 'force_deleted'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'event', 'module', 'auditable_type', 'auditable_id', 'created_at'])
            ->map(fn ($log) => [
                'id'             => $log->id,
                'event'          => $log->event,
                'module'         => $log->module,
                'auditable_id'   => $log->auditable_id,
                'auditable_type' => class_basename($log->auditable_type ?? ''),
                'created_at'     => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Widgets de super: estado del sistema completo. */
    protected function superAdminWidgets(): array
    {
        $activeTenants  = Tenant::where('is_active', true)->count();
        $totalTenants   = Tenant::count();
        $activeSubs     = Subscription::whereIn('status', ['trial', 'active'])
            ->where('ends_at', '>', now())
            ->count();
        $expiringIn7    = Subscription::whereIn('status', ['trial', 'active'])
            ->whereBetween('ends_at', [now(), now()->addDays(7)])
            ->count();
        $autoLast24h    = AutomationRun::where('started_at', '>=', now()->subDay())->count();
        $autoFailed24h  = AutomationRun::where('started_at', '>=', now()->subDay())
            ->where('status', 'failed')->count();

        return [
            ['key' => 'tenants_active',   'label' => 'tenants_active',   'value' => $activeTenants, 'hint' => "{$totalTenants} totales", 'color' => 'blue',   'icon' => 'BankOutlined',     'href' => route('system_management.tenants.index')],
            ['key' => 'subs_active',      'label' => 'subs_active',      'value' => $activeSubs,    'hint' => 'En curso',               'color' => 'green',  'icon' => 'CrownOutlined',    'href' => null],
            ['key' => 'subs_expiring',    'label' => 'subs_expiring',    'value' => $expiringIn7,   'hint' => 'En 7 días',              'color' => $expiringIn7 > 0 ? 'orange' : 'default', 'icon' => 'ClockCircleOutlined', 'href' => null],
            ['key' => 'autos_runs_24h',   'label' => 'autos_runs_24h',   'value' => $autoLast24h,   'hint' => "{$autoFailed24h} fallaron", 'color' => $autoFailed24h > 0 ? 'red' : 'cyan', 'icon' => 'ThunderboltOutlined', 'href' => null],
        ];
    }

    /** Widgets para admin/user del tenant: vista del workspace. */
    protected function tenantWidgets(?User $user): array
    {
        if (!$user || !$user->tenant_id) return [];

        $tenantId = $user->tenant_id;
        $usersCount    = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $autoActive    = Automation::where('tenant_id', $tenantId)->where('is_active', true)->count();
        $autoFailed7d  = AutomationRun::where('tenant_id', $tenantId)
            ->where('started_at', '>=', now()->subDays(7))
            ->where('status', 'failed')
            ->count();
        $sub = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['trial', 'active'])
            ->orderByDesc('ends_at')
            ->first();
        $daysLeft = $sub?->daysRemaining() ?? 0;

        return [
            ['key' => 'users_count',     'label' => 'users_count',     'value' => $usersCount,    'hint' => 'En tu workspace',        'color' => 'blue',   'icon' => 'UserOutlined',     'href' => route('user_management.users.index')],
            ['key' => 'automations',     'label' => 'automations',     'value' => $autoActive,    'hint' => 'Activas',                'color' => 'cyan',   'icon' => 'ThunderboltOutlined', 'href' => null],
            ['key' => 'auto_failures',   'label' => 'auto_failures',   'value' => $autoFailed7d,  'hint' => 'En los últimos 7 días',  'color' => $autoFailed7d > 0 ? 'red' : 'default', 'icon' => 'WarningOutlined', 'href' => null],
            ['key' => 'plan_days_left',  'label' => 'plan_days_left',  'value' => $daysLeft,      'hint' => $sub ? $sub->plan : '—',  'color' => $daysLeft <= 7 ? 'orange' : 'green', 'icon' => 'CrownOutlined', 'href' => null],
        ];
    }

    /**
     * Dashboard de FLOTA para el tenant (admin/operativo). TODOS los conteos
     * usan Eloquent NORMAL → respetan el scope de tenant Y la restricción por
     * clientes asignados (sin fuga). Filtros: f_customer / f_type / f_rating.
     * La tendencia es real (dgaf_score de las muestras de cromatografía).
     */
    protected function tenantFleetDashboard(?User $user, Request $request): array
    {
        $empty = ['fleet' => null, 'fleetFilters' => ['customers' => [], 'types' => [], 'tenants' => []], 'fleetActive' => ['customer' => [], 'type' => [], 'rating' => [], 'tenant' => []]];
        if (!$user) {
            return $empty;
        }
        $isSuper = method_exists($user, 'hasRole') && $user->hasRole('super');

        // Builder base con filtros, fresco en cada uso (scope-safe). Para super
        // el scope de tenant hace bypass → la flota agrega TODOS los workspaces;
        // el filtro f_tenant (solo super) permite acotar a uno.
        $tx = fn () => Transformer::query()
            ->when($request->filled('f_customer'), fn ($q) => $q->whereIn('customer_id', (array) $request->input('f_customer')))
            ->when($request->filled('f_type'), fn ($q) => $q->whereIn('transformer_type_id', (array) $request->input('f_type')))
            ->when($request->filled('f_rating'), fn ($q) => $q->whereIn('health_rating', (array) $request->input('f_rating')))
            ->when($isSuper && $request->filled('f_tenant'), fn ($q) => $q->whereIn('tenant_id', (array) $request->input('f_tenant')));

        // KPIs
        $total       = $tx()->count();
        $criticos    = $tx()->whereIn('health_rating', [0, 1])->count();
        $sanos       = $tx()->whereIn('health_rating', [3, 4])->count();
        $sinDx       = $tx()->whereNull('health_rating')->count();
        // Clientes del set filtrado (no todos): si filtras por un cliente, da 1.
        $clientes    = (clone $tx())->whereNotNull('customer_id')->distinct()->count('customer_id');
        // Muestras de cromatografía de los últimos 12 meses (via whereHas →
        // scope-safe). "Este mes" daba casi siempre 0 (poco muestreo mensual).
        $muestras12m = Chromatographical::whereHas('transformer')
            ->where('sample_date', '>=', now()->subMonths(12))
            ->count();

        // Distribución por estado de salud (dona). Rating 4=mejor … 0=peor.
        $RATING = [4 => ['muy_bueno', '#1D7044'], 3 => ['bueno', '#5AA82E'], 2 => ['medio', '#E9A23B'], 1 => ['malo', '#E2661E'], 0 => ['muy_malo', '#C8281D']];
        $rc = $tx()->whereNotNull('health_rating')->selectRaw('health_rating, count(*) c')->groupBy('health_rating')->pluck('c', 'health_rating');
        $health = [];
        foreach ($RATING as $r => [$cond, $color]) {
            $health[] = ['rating' => $r, 'cond' => $cond, 'color' => $color, 'count' => (int) ($rc[$r] ?? 0)];
        }
        // Segmento "sin diagnóstico" para que la dona sume el TOTAL (= KPI trafos)
        // y no haya el desfase confuso 404 (KPI) vs 390 (solo diagnosticados).
        if ($sinDx > 0) {
            $health[] = ['rating' => null, 'cond' => 'sin_dx', 'color' => '#B6BCC4', 'count' => $sinDx];
        }

        // Por tipo de transformador
        $typeNames = TransformerType::pluck('name', 'id');
        $byType = $tx()->whereNotNull('transformer_type_id')->selectRaw('transformer_type_id, count(*) c')->groupBy('transformer_type_id')
            ->pluck('c', 'transformer_type_id')->map(fn ($c, $id) => ['name' => $typeNames[$id] ?? '—', 'count' => (int) $c])
            ->sortByDesc('count')->values()->all();

        // Por marca (top 8)
        $brandNames = Brand::pluck('name', 'id');
        $byBrand = $tx()->whereNotNull('brand_id')->selectRaw('brand_id, count(*) c')->groupBy('brand_id')->orderByDesc('c')->limit(8)
            ->pluck('c', 'brand_id')->map(fn ($c, $id) => ['name' => $brandNames[$id] ?? '—', 'count' => (int) $c])->values()->all();

        // Por cliente (conteo) — base para top clientes Y para país (sin joins
        // ambiguos: agregamos en PHP mapeando cliente → país).
        $byCustomer = $tx()->whereNotNull('customer_id')
            ->selectRaw('customer_id, count(*) c, sum(case when health_rating in (0,1) then 1 else 0 end) crit')
            ->groupBy('customer_id')->get();
        $custMeta = Customer::whereIn('id', $byCustomer->pluck('customer_id'))->get(['id', 'name', 'country_id'])->keyBy('id');

        $topCustomers = $byCustomer->sortByDesc('c')->take(8)->map(fn ($r) => [
            'name'     => $custMeta[$r->customer_id]->name ?? '—',
            'trafos'   => (int) $r->c,
            'criticos' => (int) $r->crit,
        ])->values()->all();

        // Por país (agregando los conteos por cliente según su país)
        $countryNames = Country::pluck('name', 'id');
        $byCountryMap = [];
        foreach ($byCustomer as $r) {
            $cid = $custMeta[$r->customer_id]->country_id ?? null;
            $name = $cid ? ($countryNames[$cid] ?? '—') : '—';
            $byCountryMap[$name] = ($byCountryMap[$name] ?? 0) + (int) $r->c;
        }
        arsort($byCountryMap);
        $byCountry = [];
        foreach (array_slice($byCountryMap, 0, 12, true) as $name => $count) {
            $byCountry[] = ['country' => $name, 'count' => $count];
        }

        // Riesgo a corto plazo: cuáles cruzarán a una banda peor dentro de ≤12
        // meses. El pronóstico (forecast_months) ya viene CACHEADO en la tabla
        // (se calcula al diagnosticar, solo para los que empeoran). Antes se
        // recalculaba ~40 trafos EN VIVO por carga → era el cuello de botella
        // del dashboard. Ahora son 2 queries agregadas (la columna está indexada).
        $riskBase = fn () => $tx()->whereNotNull('forecast_months')->where('forecast_months', '<=', 12);
        $shortTermRisk = [
            'count' => $riskBase()->count(),
            'items' => $riskBase()->with('customer:id,name')
                ->orderBy('forecast_months')->limit(6)
                ->get(['id', 'slug', 'serial', 'tag', 'customer_id', 'forecast_months', 'forecast_target', 'health_rating'])
                ->map(fn ($t) => [
                    'slug'     => $t->slug,
                    'serial'   => $t->serial ?: ($t->tag ?: '—'),
                    'customer' => $t->customer?->name,
                    'months'   => $t->forecast_months < 1 ? 0 : (int) round($t->forecast_months),
                    'target'   => $t->forecast_target,
                    'rating'   => $t->health_rating,
                ])->all(),
        ];

        // ── Diagnóstico de flota (leen la caché: fault_type/ieee_condition/
        // paper_dp/gassing_rate; se llenan al evaluar el HI, backfill con
        // `php artisan diagnose:fleet-cache`). Todo vía $tx() → scope-safe. ──

        // Tipos de falla (Duval): familia de la última cromatografía.
        $faultOrder = ['thermal', 'discharge', 'pd', 'mixed', 'other', 'normal'];
        $fc = $tx()->whereNotNull('fault_type')->selectRaw('fault_type, count(*) c')->groupBy('fault_type')->pluck('c', 'fault_type');
        $byFault = [];
        foreach ($faultOrder as $code) {
            if (($fc[$code] ?? 0) > 0) {
                $byFault[] = ['code' => $code, 'count' => (int) $fc[$code]];
            }
        }

        // DGA Status IEEE C57.104-2019 (1=normal · 2 · 3=peor). Distribución 1..3.
        // (el campo se llama `ieee_condition` por compat., pero guarda el Status 2019).
        $ic = $tx()->whereNotNull('ieee_condition')->selectRaw('ieee_condition, count(*) c')->groupBy('ieee_condition')->pluck('c', 'ieee_condition');
        $ieeeLevels = [];
        foreach ([1, 2, 3] as $lvl) {
            $ieeeLevels[] = ['level' => $lvl, 'count' => (int) ($ic[$lvl] ?? 0)];
        }

        // Vida del papel: DP (Chendong) en bandas. <200 = fin de vida.
        $dpCase = 'case when paper_dp < 200 then 0 when paper_dp < 400 then 1 when paper_dp < 700 then 2 when paper_dp < 1100 then 3 else 4 end';
        $dpRaw = $tx()->whereNotNull('paper_dp')->selectRaw("{$dpCase} b, count(*) c")->groupByRaw($dpCase)->pluck('c', 'b');
        $paperAging = [];
        foreach ([0, 1, 2, 3, 4] as $b) {
            $paperAging[] = ['bucket' => $b, 'count' => (int) ($dpRaw[$b] ?? 0)];
        }
        $paperEol = (int) ($dpRaw[0] ?? 0);

        // Vida remanente del papel (proyección): cuántos llegan a fin de vida
        // (DP<200) en ≤5 años + los más próximos (≤10 años). Predictivo de largo
        // plazo (envejecimiento del aislamiento), complementa el riesgo de gases.
        $paperLifeUrgent = $tx()->whereNotNull('paper_life_years')->where('paper_life_years', '<=', 5)->count();
        $paperLifeSoon = $tx()->whereNotNull('paper_life_years')->where('paper_life_years', '<=', 10)
            ->with('customer:id,name')->orderBy('paper_life_years')->limit(8)
            ->get(['id', 'slug', 'serial', 'tag', 'customer_id', 'paper_life_years', 'paper_dp', 'health_rating'])
            ->map(fn ($t) => [
                'slug'     => $t->slug,
                'serial'   => $t->serial ?: ($t->tag ?: '—'),
                'customer' => $t->customer?->name,
                'years'    => (float) $t->paper_life_years,
                'dp'       => $t->paper_dp,
                'rating'   => $t->health_rating,
            ])->all();
        $paperLife = ['count' => $paperLifeUrgent, 'items' => $paperLifeSoon];

        // Tasa de generación de gas (TDCG ppm/mes): cuántos gasifican + top.
        $gassingActive = $tx()->where('gassing_rate', '>', 0)->count();
        $gassingTop = $tx()->where('gassing_rate', '>', 0)->with('customer:id,name')
            ->orderByDesc('gassing_rate')->limit(8)
            ->get(['id', 'slug', 'serial', 'tag', 'customer_id', 'gassing_rate', 'health_rating'])
            ->map(fn ($t) => [
                'slug'     => $t->slug,
                'serial'   => $t->serial ?: ($t->tag ?: '—'),
                'customer' => $t->customer?->name,
                'rate'     => (int) round((float) $t->gassing_rate),
                'rating'   => $t->health_rating,
            ])->all();

        // Matriz de riesgo: condición (health_rating 0..4) × criticidad por
        // clase de tensión (0=≤36 kV, 1=36-145, 2=>145). Prioriza mantenimiento.
        $kvCase = 'case when voltage_kv <= 36 then 0 when voltage_kv <= 145 then 1 else 2 end';
        $riskMatrix = $tx()->whereNotNull('voltage_kv')->whereNotNull('health_rating')
            ->selectRaw("health_rating r, {$kvCase} kv, count(*) c")->groupByRaw("health_rating, {$kvCase}")
            ->get()->map(fn ($x) => ['rating' => (int) $x->r, 'kv' => (int) $x->kv, 'count' => (int) $x->c])->all();

        // Desglose por workspace — SOLO super (cross-tenant). Para tenant queda [].
        $byTenant = [];
        $tenantOpts = [];
        if ($isSuper) {
            $tenantNames = Tenant::pluck('name', 'id');
            $byTenant = $tx()->whereNotNull('tenant_id')
                ->selectRaw('tenant_id, count(*) c, sum(case when health_rating in (0,1) then 1 else 0 end) crit')
                ->groupBy('tenant_id')->orderByDesc('c')->limit(12)
                ->get()->map(fn ($r) => ['name' => $tenantNames[$r->tenant_id] ?? '—', 'trafos' => (int) $r->c, 'criticos' => (int) $r->crit])->all();
            $tenantOpts = Tenant::orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all();
        }

        // Opciones de filtros (scope-safe)
        // Todos los clientes activos del scope (con o sin trafos): el filtro
        // sirve para VER si el cliente tiene o no transformadores en la flota.
        $customerOpts = Customer::where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => mb_strtoupper($c->name)])->all();
        $typeOpts = TransformerType::where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all();

        return [
            'fleet' => [
                'kpis' => [
                    'trafos'       => $total,
                    'clientes'     => $clientes,
                    'sanos'        => $sanos,
                    'criticos'     => $criticos,
                    'sin_dx'       => $sinDx,
                    'muestras'     => $muestras12m,
                    'sanos_pct'    => $total ? (int) round($sanos * 100 / $total) : 0,
                ],
                'health'       => $health,
                'byType'       => $byType,
                'byBrand'      => $byBrand,
                'byCountry'    => $byCountry,
                'shortTermRisk' => $shortTermRisk,
                'topCustomers' => $topCustomers,
                'byTenant'     => $byTenant,
                'byFault'      => $byFault,
                'ieeeLevels'   => $ieeeLevels,
                'paperAging'   => $paperAging,
                'paperEol'     => $paperEol,
                'paperLife'    => $paperLife,
                'gassing'      => ['active' => $gassingActive, 'top' => $gassingTop],
                'riskMatrix'   => $riskMatrix,
            ],
            'fleetFilters' => ['customers' => $customerOpts, 'types' => $typeOpts, 'tenants' => $tenantOpts],
            'fleetActive'  => [
                'customer' => array_map('intval', (array) $request->input('f_customer', [])),
                'type'     => array_map('intval', (array) $request->input('f_type', [])),
                'rating'   => array_map('intval', (array) $request->input('f_rating', [])),
                'tenant'   => array_map('intval', (array) $request->input('f_tenant', [])),
            ],
        ];
    }

    /**
     * Últimas 5 ejecuciones de automatizaciones — relevantes para el dashboard.
     * Super ve todas. Tenant user ve solo las de su workspace.
     */
    protected function recentAutomations(?User $user): array
    {
        if (!$user) return [];

        $q = AutomationRun::query()
            ->with('automation:id,name,tenant_id')
            ->orderByDesc('started_at')
            ->limit(5);

        if (!$user->hasRole('super')) {
            if (!$user->tenant_id) return [];
            $q->where('tenant_id', $user->tenant_id);
        }

        return $q->get(['id', 'automation_id', 'tenant_id', 'started_at', 'status', 'records_matched', 'output_summary'])
            ->map(fn ($r) => [
                'id'              => $r->id,
                'automation_id'   => $r->automation_id,
                'automation_name' => $r->automation?->name ?? '—',
                'started_at'      => $r->started_at?->toIso8601String(),
                'status'          => $r->status,
                'records_matched' => $r->records_matched,
                'output_summary'  => $r->output_summary,
            ])
            ->all();
    }

    /**
     * Suscripciones por vencer en 7 días. Super ve todas. Admin del
     * tenant ve solo la suya. Útil para alertar antes de la pérdida de servicio.
     */
    protected function expiringSubscriptions(?User $user): array
    {
        if (!$user) return [];

        $q = Subscription::query()
            ->with('tenant:id,name')
            ->whereIn('status', ['trial', 'active'])
            ->whereBetween('ends_at', [now(), now()->addDays(7)])
            ->orderBy('ends_at');

        if (!$user->hasRole('super')) {
            if (!$user->tenant_id) return [];
            $q->where('tenant_id', $user->tenant_id);
        }

        return $q->limit(10)
            ->get(['id', 'tenant_id', 'plan', 'status', 'ends_at'])
            ->map(fn ($s) => [
                'id'             => $s->id,
                'tenant_name'    => $s->tenant?->name ?? '—',
                'plan'           => $s->plan,
                'status'         => $s->status,
                'ends_at'        => $s->ends_at?->toIso8601String(),
                'days_remaining' => $s->daysRemaining(),
            ])
            ->all();
    }
}
