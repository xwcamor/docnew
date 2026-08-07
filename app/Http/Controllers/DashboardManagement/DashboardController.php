<?php

namespace App\Http\Controllers\DashboardManagement;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Tenant;
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
     * Panel del dia para el tenant: como va el trabajo en obra.
     *
     * Sustituye al panel de flota de transformadores que traia la base
     * heredada. Todos los conteos usan Eloquent normal, asi que respetan el
     * aislamiento por workspace.
     */
    protected function tenantFleetDashboard(?User $user, Request $request): array
    {
        $vacio = ['fieldWork' => null];

        if (! $user) {
            return $vacio;
        }

        $hoy = today();

        return [
            'fieldWork' => [
                'planesHoy'        => \App\Models\WorkPlan::whereDate('date_start', $hoy)->count(),
                'planesAbiertos'   => \App\Models\WorkPlan::where('is_done', false)->count(),
                'firmasPendientes' => \App\Models\SignatureEvent::pendingReview()->count(),
                'formatosSinCerrar' => \App\Models\FormSubmission::where('status', '!=', 'confirmed')->count(),
                'personasActivas'  => \App\Models\Person::where('is_active', true)->count(),
                'empresas'         => \App\Models\Company::where('is_active', true)->count(),
            ],
        ];
    }

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
