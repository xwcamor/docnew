<?php

namespace App\Http\Controllers\SystemManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\SystemManagement\Plan\StorePlanRequest;
use App\Http\Requests\SystemManagement\Plan\UpdatePlanRequest;
use App\Http\Requests\SystemManagement\Plan\DeletePlanRequest;
use App\Http\Requests\SystemManagement\Plan\ForcePlanDeleteRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plans — pricing tiers editables (super only).
 *
 * CRUD + soft-delete con motivo (patrón Regions). Sin import/export/edit_all
 * porque el dataset es chico (4-10 planes típico) y no aplica.
 *
 * Force-delete con triple guard: super + nombre exacto + reason ≥ 10 chars.
 * Audit log polimórfico vía trait Auditable. Trash list super only.
 */
class PlanController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    public function index(Request $request)
    {
        $plans = Plan::orderBy('sort_order')->orderBy('id')->get()->map(fn ($p) => $this->planPayload($p));

        return inertia('Plans/Index', [
            'plans' => $plans,
        ]);
    }

    public function show(Request $request, Plan $plan)
    {
        // Super puede ver soft-deleted via withTrashed (route model
        // binding por defecto excluye soft-deleted, hace falta resolver manual).
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $plan->load(['creator:id,name,email', 'deleter:id,name,email']);

        // Tenants en este plan (preview en el tab).
        $tenants = $plan->tenants()
            ->select('id', 'slug', 'name', 'is_active', 'created_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($t) => [
                'id'         => $t->id,
                'slug'       => $t->slug,
                'name'       => $t->name,
                'is_active'  => $t->is_active,
                'created_at' => $t->created_at,
            ]);

        // Audit log (mismo shape que Regions vía AuditLogResource).
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', Plan::class)
                    ->where('auditable_id', $plan->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Plans/Show', [
            'plan'                      => $this->planPayload($plan, withTimestamps: true, withAudit: true),
            'tenants_count'             => $plan->tenantsCount(),
            'active_subscriptions_count'=> $plan->activeSubscriptionsCount(),
            'tenants'                   => $tenants,
            'activity'                  => $activity,
            'recordAudit'               => $this->recordAuditMeta($plan),
            'featureKeys'               => $this->featureKeys(),
        ]);
    }

    public function create()
    {
        return inertia('Plans/Form', [
            'plan'          => null,
            'tenants_count' => 0,
            'featureKeys'   => $this->featureKeys(),
        ]);
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $features = $this->normalizeFeatures($data['features'] ?? []);

        Plan::create(array_merge($data, [
            'features'   => $features,
            'sort_order' => $data['sort_order'] ?? (Plan::max('sort_order') + 1),
            'is_active'  => $data['is_active'] ?? true,
            'is_public'  => $data['is_public'] ?? true,
            'created_by' => $request->user()?->id,
        ]));

        return redirect()
            ->route('system_management.plans.index')
            ->with('success', __('plans.created'));
    }

    public function edit(Plan $plan)
    {
        return inertia('Plans/Form', [
            'plan'          => $this->planPayload($plan),
            'tenants_count' => $plan->tenantsCount(),
            'featureKeys'   => $this->featureKeys(),
        ]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        $data = $request->validated();
        $features = $this->normalizeFeatures($data['features'] ?? []);

        $plan->update(array_merge($data, [
            'features'  => $features,
            'is_active' => $data['is_active'] ?? $plan->is_active,
            'is_public' => $data['is_public'] ?? $plan->is_public,
        ]));

        return redirect()
            ->route('system_management.plans.index')
            ->with('success', __('plans.saved'));
    }

    // ── SOFT-DELETE (con motivo obligatorio, patrón Regions) ────────────────

    public function delete(Plan $plan)
    {
        return inertia('Plans/Delete', [
            'plan' => [
                'id'        => $plan->id,
                'slug'      => $plan->slug,
                'name'      => $plan->name,
                'is_active' => $plan->is_active,
            ],
            'dependents' => $this->countDependents($plan),
        ]);
    }

    public function deleteSave(DeletePlanRequest $request, Plan $plan): RedirectResponse
    {
        // Bloquea si hay tenants apuntando al plan (dependents `block: true`).
        $tenantsCount = $plan->tenantsCount();
        if ($tenantsCount > 0) {
            return back()->with('error', __('plans.delete_blocked', ['count' => $tenantsCount]));
        }

        $plan->forceFill([
            'deleted_by'          => $request->user()?->id,
            'deleted_description' => $request->validated()['deleted_description'],
        ])->save();
        $plan->delete();

        return redirect()
            ->route('system_management.plans.index')
            ->with('success', __('global.deleted_success'));
    }

    // ── TRASH + RESTORE + FORCE_DELETE (super only) ───────────────────

    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $plans = Plan::onlyTrashed()
            ->with(['deleter:id,name,email'])
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn ($p) => array_merge($this->planPayload($p, withTimestamps: true), [
                'deleted_at'          => $p->deleted_at,
                'deleted_description' => $p->deleted_description,
                'deleter'             => $p->deleter ? [
                    'id'    => $p->deleter->id,
                    'name'  => $p->deleter->name,
                    'email' => $p->deleter->email,
                ] : null,
            ]));

        return inertia('Plans/Trash', [
            'plans' => $plans,
        ]);
    }

    public function restore(Request $request, $plan): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $model = Plan::onlyTrashed()->findOrFail($plan);
        $model->restore();
        $model->forceFill([
            'deleted_by'          => null,
            'deleted_description' => null,
        ])->save();

        return redirect()
            ->route('system_management.plans.trash')
            ->with('success', __('global.restored_success'));
    }

    /**
     * Hard-delete con triple guard: super + onlyTrashed + nombre exacto.
     * Audit log se escribe ANTES del delete físico (sobrevive al borrado).
     */
    public function forceDelete(ForcePlanDeleteRequest $request, $plan): RedirectResponse
    {
        $model = Plan::onlyTrashed()->findOrFail($plan);
        $data  = $request->validated();

        if (trim($data['name_confirmation']) !== $model->name) {
            return back()->withErrors([
                'name_confirmation' => __('global.force_delete_name_mismatch'),
            ]);
        }

        DB::transaction(function () use ($model, $data, $request) {
            // Lock para evitar race con restore concurrente.
            $locked = Plan::onlyTrashed()->where('id', $model->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Plan {$model->id} no longer available for force-delete");
            }

            // Audit pre-delete con motivo en `note`. El trait Auditable también
            // dispara su propio `force_deleted` automático al hacer forceDelete()
            // — dejamos ambos: este captura la razón, el del trait captura el
            // shape automático estándar (mismo patrón que Regions).
            AuditLog::create([
                'user_id'        => $request->user()?->id,
                'auditable_type' => Plan::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name'      => $locked->name,
                    'slug'      => $locked->slug,
                    'is_active' => $locked->is_active,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $data['reason'],
                'module'         => 'plans',
                'created_at'     => now(),
            ]);
            $locked->forceDelete();
        });

        return redirect()
            ->route('system_management.plans.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    // ─── Helpers privados ─────────────────────────────────────────────────

    private function planPayload(Plan $plan, bool $withTimestamps = false, bool $withAudit = false): array
    {
        $base = [
            'id'                     => $plan->id,
            'slug'                   => $plan->slug,
            'name'                   => $plan->name,
            'tagline'                => $plan->tagline,
            'icon'                   => $plan->icon,
            'color'                  => $plan->color,
            'sort_order'             => $plan->sort_order,
            'max_users'              => $plan->getAttributes()['max_users'],
            'max_records_per_module' => $plan->getAttributes()['max_records_per_module'],
            'export_rate_limit'      => $plan->export_rate_limit,
            'support_level'          => $plan->support_level ?: 'community',
            'features'               => $plan->features ?? [],
            'price_monthly'          => (float) $plan->price_monthly,
            'price_yearly'           => (float) $plan->price_yearly,
            'currency'               => $plan->currency,
            'is_active'              => $plan->is_active,
            'is_public'              => $plan->is_public,
            'tenants_count'          => $plan->tenantsCount(),
        ];
        if ($withTimestamps) {
            $base['created_at'] = $plan->created_at;
            $base['updated_at'] = $plan->updated_at;
            $base['deleted_at'] = $plan->deleted_at;
        }
        if ($withAudit) {
            $base['deleted_description'] = $plan->deleted_description;
            $base['creator'] = $plan->creator ? [
                'id'    => $plan->creator->id,
                'name'  => $plan->creator->name,
                'email' => $plan->creator->email,
            ] : null;
            $base['deleter'] = $plan->deleter ? [
                'id'    => $plan->deleter->id,
                'name'  => $plan->deleter->name,
                'email' => $plan->deleter->email,
            ] : null;
        }
        return $base;
    }

    /** Cuenta dependents para la pantalla Delete. */
    private function countDependents(Plan $plan): array
    {
        $out = [];
        foreach ($plan->dependents() as $key => $config) {
            $count = ($config['count'])();
            if ($count > 0) {
                $out[$key] = [
                    'count' => $count,
                    'label' => $config['label'],
                    'block' => $config['block'] ?? false,
                ];
            }
        }
        return $out;
    }

    /** Asegura todos los feature keys con bool. Defaults a false. */
    private function normalizeFeatures(array $raw): array
    {
        return collect($this->featureKeys())
            ->mapWithKeys(fn ($k) => [$k => (bool) ($raw[$k] ?? false)])
            ->all();
    }

    /**
     * Single source of truth — features booleanas que se gatean por plan.
     *
     * Cada feature aquí DEBE tener:
     *   - label i18n: `plans.feature_{cameled}` en es y en
     *   - valor explícito en PlansSeeder por cada tier
     *   - gate efectivo en código: middleware `plan_feature:X` en routes,
     *     o lógica interna que lee `$tenant->canUseFeature('X')`
     *
     * Categorías:
     *   - GATES UI/ROUTE: bloquean acceso a una acción (export_*, audit_log_view,
     *     saved_views, bulk_operations, api_access, team_management, automations).
     *   - LÓGICA INTERNA: cambian comportamiento sin bloquear UI
     *     (extended_retention afecta purge, higher_export_rate_limit cambia throttle).
     *
     * NOTA: el nivel de soporte NO es una feature booleana — vive en la columna
     * `support_level` (community/email/priority), no en este array.
     */
    private function featureKeys(): array
    {
        return [
            // Exports
            'export_csv', 'export_excel', 'export_pdf', 'export_word',
            'branded_exports',
            // Visibilidad de datos
            'audit_log_view', 'saved_views',
            // Operaciones masivas + importar
            'bulk_operations', 'imports', 'edit_all',
            // Equipos de trabajo — gatea los modulos Users + Roles completos.
            // Reemplaza a custom_roles (que solo cubria roles).
            'team_management',
            // Acceso programatico
            'api_access',
            // Automatizacion
            'automations',
            // Futuras (declaradas, sin gate todavia)
            'scheduled_exports', 'export_webhook_delivery', 'export_email_delivery',
            // Logica interna (no gate, cambia comportamiento)
            'extended_retention', 'higher_export_rate_limit',
        ];
    }
}
