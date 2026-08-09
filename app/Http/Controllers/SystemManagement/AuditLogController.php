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
     * Lo que el admin de un workspace puede auditar, ademas de lo que sale de
     * `system_modules` (ver adminModules()).
     *
     * Son las piezas que SI se auditan pero que no son un modulo con CRUD
     * propio: la cadena de evidencia de una firma, los subobjetos de un
     * formato y las herramientas del workspace. Sin ellas el admin veria
     * «formato modificado» pero no quien cambio una respuesta.
     */
    private const EXTRA_ADMIN_MODULES = [
        // Evidencia de obra — lo que puede acabar delante de un inspector.
        'signature_events', 'evidence_files', 'person_signatures', 'person_biometrics',
        'form_submissions', 'form_answers', 'form_attachments', 'form_sections', 'form_fields',
        'person_roles', 'person_company_links',
        // Herramientas y datos del propio workspace.
        'brands', 'comments', 'messages', 'automations',
        'customer_areas', 'customer_locations', 'customer_substations',
    ];

    /**
     * Modulos que el ADMIN puede auditar (solo de SU tenant). El super ve TODOS.
     *
     * La lista NO se escribe a mano: sale de `system_modules`, que es el
     * registro de lo que un admin puede delegar en sus perfiles. Si un modulo
     * esta ahi, el admin lo gestiona y por tanto debe poder auditarlo; si no
     * esta, es nucleo de plataforma (ajustes, workspaces, catalogos globales,
     * planes) y sigue siendo exclusivo del super.
     *
     * Antes era una constante con la lista del dominio de transformadores
     * (`transformers`, `laboratories`, `oil_types`, `tap_changer_*`), purgado
     * del producto. O sea que el admin abria «Logs del sistema» y no veia NADA
     * de planes de trabajo, personas, formatos ni firmas: justo el rastro que
     * este producto existe para conservar. Derivarlo de la tabla evita que
     * vuelva a quedarse atras cuando se agregue un modulo.
     *
     * @return string[]
     */
    private function adminModules(): array
    {
        $registrados = \App\Models\SystemModule::query()
            ->whereNull('deleted_at')
            ->pluck('permission_key')
            ->all();

        return array_values(array_unique(array_merge($registrados, self::EXTRA_ADMIN_MODULES)));
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('super') || $user->hasRole('admin')),
            403
        );

        $isSuper = $user->hasRole('super');
        $adminModules = $isSuper ? [] : $this->adminModules();

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
        // Ademas: el admin queda acotado a los modulos que gestiona
        // (adminModules()); el nucleo de plataforma es exclusivo de super.
        if (! $isSuper) {
            $query->whereHas('user', function ($q) use ($user) {
                $q->withoutGlobalScopes()->where('tenant_id', $user->tenant_id);
            });
            $query->whereIn('module', $adminModules);
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
                ->whereIn('module', $adminModules);
            $eventsQuery->whereHas('user', $tenantScope)
                ->whereIn('module', $adminModules);
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
