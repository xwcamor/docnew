<?php

namespace App\Http\Controllers\DashboardManagement;

use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Models\FormSubmission;
use App\Models\SignatureEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Services\BusinessManagement\WorkPlanCompletionService;
use App\Support\Tz;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * DashboardController — la primera pantalla despues de entrar.
 *
 * Lo que se enseña aqui es **el panel del dia**: cuantos planes hay hoy, que
 * falta por firmar y que formatos quedan sin confirmar. Es lo que un supervisor
 * de obra necesita saber sin abrir nada mas.
 *
 * Dos reglas que ya se incumplieron una vez y por eso estan escritas:
 *
 *  1. **Todo conteo se acota al workspace.** `work_plans` lo hace solo con su
 *     scope global; `work_plan_people`, `work_plan_approvals` y
 *     `form_submissions` NO tienen scope propio, asi que se cuentan siempre
 *     colgando de una subconsulta de planes visibles. Contarlas sueltas
 *     enseñaba las firmas pendientes de todos los workspaces a la vez.
 *  2. **«Hoy» es hoy en la obra.** `date_start` guarda la hora de pared del
 *     trabajo y la app corre en UTC: con `today()` a secas, en Lima el panel
 *     cambiaba de dia a las 19:00.
 *
 * Al super se le añade encima el estado de la plataforma (workspaces,
 * suscripciones, ejecuciones de automatizaciones), que es su trabajo. Su panel
 * del dia es cross-tenant porque su scope hace bypass, y el frontend lo dice.
 */
class DashboardController extends Controller
{
    /**
     * Cuantos planes de hoy se listan bajo los indicadores.
     *
     * El tope existe por el coste: de cada plan listado se dice **que le
     * falta**, y eso lo calcula `WorkPlanCompletionService` con unas seis
     * consultas por plan. Es caro y se paga a proposito —repetir aqui la regla
     * de cierre seria tener dos verdades—, pero queda acotado: el numero no
     * crece con el tamaño del workspace. El resto se ve en «ver todos los de
     * hoy», que es el listado paginado de siempre.
     */
    protected const PLANES_LISTADOS = 8;

    public function index(Request $request)
    {
        $user    = $request->user();
        $isSuper = $user?->hasRole('super') ?? false;

        return inertia('Dashboard/Index', [
            'isSuper'           => $isSuper,
            // El panel del dia. `null` cuando el usuario no puede ver planes:
            // mejor una bienvenida honesta que una rejilla de ceros ajenos.
            'today'             => $this->panelDelDia($user),
            'workspaceWidgets'  => $isSuper ? [] : $this->tenantWidgets($user),
            'widgets'           => $isSuper ? $this->superAdminWidgets() : [],
            'recentAutomations' => $isSuper ? $this->recentAutomations($user) : [],
            'expiringSoon'      => $isSuper ? $this->expiringSubscriptions($user) : [],
        ]);
    }

    /**
     * El panel del dia: indicadores de obra + los planes con fecha de hoy y lo
     * que le falta a cada uno.
     *
     * Devuelve `null` si el usuario no puede ver planes de trabajo — no se le
     * enseñan conteos de algo que no tiene permiso para abrir.
     */
    protected function panelDelDia(?User $user): ?array
    {
        if (! $user || ! $user->can('work_plans.view')) {
            return null;
        }

        $hoy = Carbon::now(Tz::for($user))->toDateString();

        // Subconsultas de planes VISIBLES para este usuario. El scope global de
        // WorkPlan las acota al workspace (y las deja abiertas para el super),
        // asi que todo lo que cuelga de aqui hereda el aislamiento.
        $abiertos = WorkPlan::query()->where('is_done', false)->select('id');

        $planesHoy      = WorkPlan::query()->whereDate('date_start', $hoy)->count();
        $planesHoySinTerminar = WorkPlan::query()->whereDate('date_start', $hoy)
            ->where('is_done', false)->count();
        $planesAbiertos = WorkPlan::query()->where('is_done', false)->count();

        // «Falta por firmar» son dos cosas distintas y las dos cuentan: el
        // trabajador que aun no ha firmado su asistencia y la aprobacion
        // obligatoria que nadie ha dado. No es lo mismo que la bandeja de
        // revision, que son firmas YA hechas y dudosas.
        $firmasTrabajadores = WorkPlanPerson::query()
            ->whereIn('work_plan_id', (clone $abiertos))
            ->where('is_approved', false)
            ->count();

        $firmasAprobacion = WorkPlanApproval::query()
            ->whereIn('work_plan_id', (clone $abiertos))
            ->where('is_required', true)
            ->where('is_approved', false)
            ->count();

        $formatosSinConfirmar = FormSubmission::query()
            ->whereIn('work_plan_id', (clone $abiertos))
            ->where('status', '!=', 'confirmed')
            ->count();

        $firmasPendientes = $firmasTrabajadores + $firmasAprobacion;

        $indicadores = [
            [
                'key'   => 'plans_today',
                'value' => $planesHoy,
                'hint'  => trans_choice('dashboard.hint_plans_today', $planesHoySinTerminar, ['count' => $planesHoySinTerminar]),
                'color' => 'blue',
                'icon'  => 'FileDoneOutlined',
                'href'  => route('business_management.work_plans.index', ['date_from' => $hoy, 'date_to' => $hoy]),
            ],
            [
                'key'   => 'signatures_pending',
                'value' => $firmasPendientes,
                'hint'  => __('dashboard.hint_signatures_pending', [
                    'workers'   => $firmasTrabajadores,
                    'approvals' => $firmasAprobacion,
                ]),
                'color' => $firmasPendientes > 0 ? 'orange' : 'green',
                'icon'  => 'EditOutlined',
                'href'  => null,
            ],
            [
                'key'   => 'forms_pending',
                'value' => $formatosSinConfirmar,
                'hint'  => __('dashboard.hint_forms_pending'),
                'color' => $formatosSinConfirmar > 0 ? 'orange' : 'green',
                'icon'  => 'FormOutlined',
                'href'  => null,
            ],
            [
                'key'   => 'plans_open',
                'value' => $planesAbiertos,
                'hint'  => __('dashboard.hint_plans_open'),
                'color' => 'default',
                'icon'  => 'FolderOpenOutlined',
                'href'  => route('business_management.work_plans.index', ['is_done' => 'false']),
            ],
        ];

        // Bandeja de firmas dudosas: solo a quien la puede resolver, y con el
        // enlace a la propia bandeja. Sin el permiso no aparece el numero.
        if ($user->can('signature_events.review')) {
            // `signable` es polimorfico (trabajador, aprobacion o formato), asi
            // que el filtro por workspace tiene que entrar por cada tabla: un
            // `whereIn('signable_id', ...)` compararia ids de tablas distintas.
            $porRevisar = SignatureEvent::query()
                ->pendingReview()
                ->whereHasMorph(
                    'signable',
                    [WorkPlanPerson::class, WorkPlanApproval::class, FormSubmission::class],
                    fn ($q) => $q->whereIn('work_plan_id', WorkPlan::query()->select('id')),
                )
                ->count();

            $indicadores[] = [
                'key'   => 'signatures_review',
                'value' => $porRevisar,
                'hint'  => __('dashboard.hint_signatures_review'),
                'color' => $porRevisar > 0 ? 'red' : 'default',
                'icon'  => 'SafetyCertificateOutlined',
                'href'  => route('field_work.signatures.review'),
            ];
        }

        return [
            'date'       => $hoy,
            'widgets'    => $indicadores,
            'plans'      => $this->planesDeHoy($hoy),
            'plansTotal' => $planesHoy,
            'canCreate'  => $user->can('work_plans.create'),
            'createUrl'  => $user->can('work_plans.create')
                ? route('business_management.work_plans.create')
                : null,
            'allUrl'     => route('business_management.work_plans.index', ['date_from' => $hoy, 'date_to' => $hoy]),
        ];
    }

    /**
     * Los planes con fecha de hoy y **que le falta a cada uno** para poder
     * cerrarse, en las mismas palabras que usa la ficha del plan.
     *
     * Lo que falta lo dice `WorkPlanCompletionService::loQueFalta()`, que es la
     * regla real de cierre. Repetirla aqui a mano seria tener dos verdades.
     */
    protected function planesDeHoy(string $hoy): array
    {
        $servicio = app(WorkPlanCompletionService::class);

        return WorkPlan::query()
            ->whereDate('date_start', $hoy)
            ->with([
                'company:id,name',
                'workLocation:id,name',
                // Solo `code`: el tipo de trabajo del sistema anterior tenia
                // `name_es`/`name_en` y aqui no existen, asi que pedirlas
                // reventaba con un 42703. Se precarga porque
                // `expectedFormTemplates()` —dentro de `loQueFalta()`— entra al
                // tipo de cada plan, y sin esto son N consultas mas.
                'workType:id,code',
                'formTemplateOverrides',
            ])
            // Sin terminar primero: lo que hay que atender va arriba.
            ->orderBy('is_done')
            ->orderBy('date_start')
            ->limit(self::PLANES_LISTADOS)
            ->get()
            ->map(fn (WorkPlan $plan) => [
                'slug'        => $plan->slug,
                'code'        => $plan->code,
                'company'     => $plan->company?->name,
                'location'    => $plan->workLocation?->name,
                'description' => $plan->description,
                'time'        => $plan->date_start?->format('H:i'),
                'is_done'     => (bool) $plan->is_done,
                'is_closed'   => (bool) $plan->is_closed,
                'missing'     => $plan->is_done ? [] : $servicio->loQueFalta($plan),
                'href'        => route('business_management.work_plans.show', $plan->slug),
            ])
            ->all();
    }

    /** Widgets de super: estado de la plataforma, no de la obra. */
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
            ['key' => 'tenants_active', 'value' => $activeTenants, 'hint' => __('dashboard.hint_tenants_total', ['count' => $totalTenants]), 'color' => 'blue',  'icon' => 'BankOutlined', 'href' => route('system_management.tenants.index')],
            ['key' => 'subs_active',    'value' => $activeSubs,    'hint' => __('dashboard.hint_subs_active'), 'color' => 'green', 'icon' => 'CrownOutlined', 'href' => null],
            ['key' => 'subs_expiring',  'value' => $expiringIn7,   'hint' => __('dashboard.hint_subs_expiring'), 'color' => $expiringIn7 > 0 ? 'orange' : 'default', 'icon' => 'ClockCircleOutlined', 'href' => null],
            ['key' => 'autos_runs_24h', 'value' => $autoLast24h,   'hint' => trans_choice('dashboard.hint_autos_failed', $autoFailed24h, ['count' => $autoFailed24h]), 'color' => $autoFailed24h > 0 ? 'red' : 'cyan', 'icon' => 'ThunderboltOutlined', 'href' => null],
        ];
    }

    /**
     * Widgets del workspace para el admin del tenant: su equipo, sus reglas y
     * los dias que le quedan de plan. No es la obra, y en el frontend va
     * debajo y en su propio bloque para que no se confunda con ella.
     */
    protected function tenantWidgets(?User $user): array
    {
        if (! $user || ! $user->tenant_id || ! $user->hasRole('admin')) {
            return [];
        }

        $tenantId = $user->tenant_id;

        $usersCount   = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $autoActive   = \App\Models\Automation::where('tenant_id', $tenantId)->where('is_active', true)->count();
        $autoFailed7d = AutomationRun::where('tenant_id', $tenantId)
            ->where('started_at', '>=', now()->subDays(7))
            ->where('status', 'failed')
            ->count();

        $sub = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['trial', 'active'])
            ->orderByDesc('ends_at')
            ->first();
        $daysLeft = $sub?->daysRemaining() ?? 0;

        // Automatizaciones no se protege con un permiso sino con la feature del
        // plan (asi lo hacen la ruta y el menu lateral). Preguntar por
        // `can('automations.view')` daba siempre `false` —ese permiso no
        // existe— y el numero se quedaba sin enlace.
        $verAutomatizaciones = $user->tenant?->canUseFeature('automations') ?? false;

        return [
            ['key' => 'users_count',    'value' => $usersCount,   'hint' => __('dashboard.hint_users_count'), 'color' => 'blue', 'icon' => 'UserOutlined', 'href' => $user->can('users.view') ? route('user_management.users.index') : null],
            ['key' => 'automations',    'value' => $autoActive,   'hint' => __('dashboard.hint_automations'), 'color' => 'cyan', 'icon' => 'ThunderboltOutlined', 'href' => $verAutomatizaciones ? route('automation_management.automations.index') : null],
            ['key' => 'auto_failures',  'value' => $autoFailed7d, 'hint' => __('dashboard.hint_auto_failures'), 'color' => $autoFailed7d > 0 ? 'red' : 'default', 'icon' => 'WarningOutlined', 'href' => null],
            ['key' => 'plan_days_left', 'value' => $daysLeft,     'hint' => $sub?->plan ? strtoupper($sub->plan) : '—', 'color' => $daysLeft <= 7 ? 'orange' : 'green', 'icon' => 'CrownOutlined', 'href' => null],
        ];
    }

    protected function recentAutomations(?User $user): array
    {
        if (! $user) return [];

        $q = AutomationRun::query()
            ->with('automation:id,name,tenant_id')
            ->orderByDesc('started_at')
            ->limit(5);

        if (! $user->hasRole('super')) {
            if (! $user->tenant_id) return [];
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
        if (! $user) return [];

        $q = Subscription::query()
            ->with('tenant:id,name')
            ->whereIn('status', ['trial', 'active'])
            ->whereBetween('ends_at', [now(), now()->addDays(7)])
            ->orderBy('ends_at');

        if (! $user->hasRole('super')) {
            if (! $user->tenant_id) return [];
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
