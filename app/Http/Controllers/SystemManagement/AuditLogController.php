<?php

namespace App\Http\Controllers\SystemManagement;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * AuditLogController — read-only ledger across all modules.
 *
 * Access policy: super OR admin. Regular users / clients' workers
 * can NEVER see this. Defense-in-depth: route middleware + abort_unless here.
 */
class AuditLogController extends Controller
{
    /**
     * Modulos que el ADMIN puede auditar (solo de SU tenant). El super ve TODOS
     * los modulos; el admin queda acotado a esta lista — el resto (catalogos,
     * core de system_management, automations, etc.) es exclusivo de super.
     */
    private const ADMIN_MODULES = [
        'users', 'roles', 'brands', 'customers', 'transformers',
        // Catálogos de diagnóstico que el admin gestiona (laboratorio, aceites,
        // tipos de trafo y conmutador): también debe poder auditarlos.
        'laboratories', 'oil_types', 'transformer_types',
        'tap_changer_brands', 'tap_changer_models', 'tap_changer_technologies', 'tap_changer_types',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('super') || $user->hasRole('admin')),
            403
        );

        $isSuper = $user->hasRole('super');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        $query = AuditLog::query()
            ->with(['user:id,name,email'])
            ->select([
                'id', 'user_id', 'event', 'auditable_type', 'auditable_id',
                'module', 'old_values', 'new_values', 'url', 'ip_address',
                'user_agent', 'note', 'created_at',
            ]);

        // Tenant scope: admin solo ve logs de users de SU tenant.
        // (Super ve todo, incluidos logs propios y de otros tenants.)
        // Ademas: el admin queda acotado a ADMIN_MODULES (users/roles/brands/
        // customers/transformers); el resto de modulos es exclusivo de super.
        if (! $isSuper) {
            $query->whereHas('user', function ($q) use ($user) {
                $q->withoutGlobalScopes()->where('tenant_id', $user->tenant_id);
            });
            $query->whereIn('module', self::ADMIN_MODULES);
        }

        // Filters
        if ($request->filled('module')) {
            $query->where('module', $request->get('module'));
        }
        if ($request->filled('event')) {
            $query->where('event', $request->get('event'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }
        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', $request->get('auditable_id'));
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        // Distinct module + event lists para filter dropdowns — también scoped por tenant.
        $modulesQuery = AuditLog::query()->whereNotNull('module');
        $eventsQuery  = AuditLog::query();
        if (! $isSuper) {
            $tenantScope = function ($q) use ($user) {
                $q->withoutGlobalScopes()->where('tenant_id', $user->tenant_id);
            };
            $modulesQuery->whereHas('user', $tenantScope)
                ->whereIn('module', self::ADMIN_MODULES);
            $eventsQuery->whereHas('user', $tenantScope)
                ->whereIn('module', self::ADMIN_MODULES);
        }
        $modules = $modulesQuery->distinct()->orderBy('module')->pluck('module');
        $events  = $eventsQuery->distinct()->orderBy('event')->pluck('event');

        // Usuarios para el filtro "Usuario" (select, no input de ID). Admin: solo
        // los de SU tenant (los global scopes de User ya lo restringen y ocultan
        // a los super). Super: TODOS, con el workspace entre paréntesis para
        // distinguir homónimos cross-tenant.
        if ($isSuper) {
            $users = \App\Models\User::withoutGlobalScopes()
                ->with('tenant:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'tenant_id'])
                ->map(fn ($u) => [
                    'value' => $u->id,
                    'label' => $u->name . ' (' . ($u->tenant?->name ?? __('global.platform')) . ')',
                ])->values();
        } else {
            $users = \App\Models\User::where('tenant_id', $user->tenant_id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])
                ->values();
        }

        return inertia('AuditLogs/Index', [
            'logs'    => $logs,
            'modules' => $modules,
            'events'  => $events,
            'users'   => $users,
            'filters' => [
                'module'       => $request->get('module', ''),
                'event'        => $request->get('event', ''),
                'user_id'      => $request->get('user_id', ''),
                'auditable_id' => $request->get('auditable_id', ''),
                'date_from'    => $request->get('date_from', ''),
                'date_to'      => $request->get('date_to', ''),
                'per_page'     => $perPage,
            ],
        ]);
    }
}
