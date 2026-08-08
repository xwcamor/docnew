<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\WorkType\BulkDeleteWorkTypeRequest;
use App\Http\Requests\BusinessManagement\WorkType\BulkRestoreWorkTypeRequest;
use App\Http\Requests\BusinessManagement\WorkType\BulkSetActiveWorkTypeRequest;
use App\Http\Requests\BusinessManagement\WorkType\DeleteWorkTypeRequest;
use App\Http\Requests\BusinessManagement\WorkType\ForceDeleteWorkTypeRequest;
use App\Http\Requests\BusinessManagement\WorkType\StoreWorkTypeRequest;
use App\Http\Requests\BusinessManagement\WorkType\UpdateFormTemplatesRequest;
use App\Http\Requests\BusinessManagement\WorkType\UpdateWorkTypeRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\WorkType;
use App\Services\BusinessManagement\WorkTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Los tipos de trabajo: qué clase de maniobra es, y qué papeles exige.
 *
 * Lo que esta pantalla tiene que dejar claro, porque es donde la gente se
 * equivoca: el pivote `work_type_form_templates` no es una etiqueta, es una
 * puerta. Un formato marcado como obligatorio aquí NO se puede quitar del plan
 * de un martes —`WorkPlanSetupService::removeFormTemplate()` lo rechaza—, y el
 * mensaje que el supervisor recibe entonces le manda justo aquí.
 *
 * De ahí que cada pantalla que toca el pivote traiga el número de planes
 * ABIERTOS del tipo: el pivote se lee en vivo, así que el cambio alcanza hoy
 * mismo a lo que hay en obra. Los cerrados no se tocan.
 */
class WorkTypeController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    public function index(Request $request, WorkTypeService $service)
    {
        $perPage = (int) $request->get('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200], true) ? $perPage : 25;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'code', 'direction' => 'asc']);
        }

        $isSuper = $request->user()?->hasRole('super') ?? false;

        // Cuántos formatos exige cada tipo y cuántos de ellos son obligatorios:
        // es la columna por la que se entra al módulo, y sacarla en la misma
        // consulta evita una por fila.
        $tipos = WorkTypeService::filter(
            WorkType::query()->select('work_types.*')
                ->with(['country:id,name,iso_code'])
                ->withCount([
                    'formTemplates as forms_count',
                    'formTemplates as required_forms_count' => fn ($q) => $q->where('work_type_form_templates.is_required', true),
                    'workPlans as open_plans_count' => fn ($q) => $q->where('is_closed', false),
                ]),
            $request,
        )->paginate($perPage)->withQueryString();

        $terminos = $request->get('name', []);
        if (is_string($terminos)) {
            $terminos = $terminos === '' ? [] : [$terminos];
        }

        return inertia('WorkTypes/Index', [
            'work_types' => array_merge($tipos->toArray(), [
                'total_unfiltered' => WorkType::count(),
            ]),
            'options' => $service->opcionesDelFormulario(),
            'filters' => [
                'name'             => array_values($terminos),
                'country_id'       => $request->get('country_id') !== null ? (int) $request->country_id : null,
                'form_template_id' => $request->get('form_template_id', null),
                'has_forms'        => $request->filled('has_forms') ? filter_var($request->has_forms, FILTER_VALIDATE_BOOLEAN) : null,
                'is_active'        => $request->filled('is_active') ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN) : null,
                'created_from'     => $request->get('created_from', ''),
                'created_to'       => $request->get('created_to', ''),
                'sort'             => $request->get('sort', 'code'),
                'direction'        => $request->get('direction', 'asc'),
                'per_page'         => $perPage,
                'advanced_where'   => $this->parseAdvancedWhere($request),
            ],
            'isSuper'      => $isSuper,
            'filterSchema' => WorkTypeService::filterSchema(),
        ]);
    }

    protected function parseAdvancedWhere(Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, fn ($c) => is_array($c) && ! empty($c['field']) && ! empty($c['op'])));
    }

    public function show(Request $request, WorkType $workType, WorkTypeService $service)
    {
        $workType->load(['country:id,name,iso_code']);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', WorkType::class)
                    ->where('auditable_id', $workType->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('WorkTypes/Show', [
            'workType'      => $this->payload($workType, withAudit: true),
            // La matriz se edita aquí mismo: cambiar qué papeles exige un tipo
            // es lo que más se hace, y no debería obligar a abrir el formulario
            // entero (donde de paso se toca el país, que es otra magnitud).
            'formTemplates' => $service->matrizDeFormatos($workType),
            'openPlans'     => $service->planesAbiertos($workType),
            'canEditForms'  => $request->user()?->can('work_types.edit') ?? false,
            'recordAudit'   => $this->recordAuditMeta($workType),
            'activity'      => $activity,
        ]);
    }

    public function create(Request $request, WorkTypeService $service)
    {
        return inertia('WorkTypes/Form', [
            'workType'         => null,
            'formTemplates'    => [],
            'openPlans'        => 0,
            'options'          => $service->opcionesDelFormulario(),
            'defaultCountryId' => $request->user()?->country_id,
        ]);
    }

    public function store(StoreWorkTypeRequest $request, WorkTypeService $service): RedirectResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && WorkType::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.work_types.index')
            ->with('success', __('work_types.created'));
    }

    public function edit(WorkType $workType, WorkTypeService $service)
    {
        return inertia('WorkTypes/Form', [
            'workType'      => $this->payload($workType),
            'formTemplates' => $service->matrizDeFormatos($workType),
            'openPlans'     => $service->planesAbiertos($workType),
            'options'       => $service->opcionesDelFormulario(),
        ]);
    }

    public function update(UpdateWorkTypeRequest $request, WorkType $workType, WorkTypeService $service): RedirectResponse
    {
        // Se cuentan los planes ANTES de guardar: si el cambio incluye el país,
        // después ya no sería el mismo conjunto de planes el que se avisa.
        $abiertos = $service->planesAbiertos($workType);
        $cambios  = $service->update($workType, $request->validated());

        return redirect()
            ->route('business_management.work_types.index')
            ->with('success', $this->mensajeDeGuardado($cambios, $abiertos));
    }

    /**
     * Guardar sólo la matriz de formatos, desde la ficha.
     *
     * Vuelve a la misma ficha (y no al listado) porque el usuario está mirando
     * el resultado: si se lo llevan a otra pantalla no puede comprobar lo que
     * acaba de mover.
     */
    public function updateFormTemplates(UpdateFormTemplatesRequest $request, WorkType $workType, WorkTypeService $service): RedirectResponse
    {
        $abiertos = $service->planesAbiertos($workType);
        $cambios  = $service->sincronizarFormatos($workType, $request->validated()['form_templates']);

        return redirect()
            ->route('business_management.work_types.show', $workType->slug)
            ->with('success', $this->mensajeDeGuardado($cambios, $abiertos));
    }

    /**
     * «Guardado» a secas no sirve cuando el cambio alcanza a planes en obra.
     *
     * Si el pivote no se movió, el mensaje es el de siempre. Si se movió, se
     * dice a cuántos planes abiertos les cambia hoy lo que se les pide — y que
     * los cerrados se quedan como están, que es la otra mitad de la duda.
     *
     * @param  array{added:int, removed:int, changed:int, touched:bool}  $cambios
     */
    protected function mensajeDeGuardado(array $cambios, int $planesAbiertos): string
    {
        if (! $cambios['touched']) {
            return __('work_types.saved');
        }

        if ($planesAbiertos === 0) {
            return __('work_types.saved_forms_no_plans');
        }

        return trans_choice('work_types.saved_forms_affects_plans', $planesAbiertos, ['count' => $planesAbiertos]);
    }

    public function delete(WorkType $workType, WorkTypeService $service)
    {
        return inertia('WorkTypes/Delete', [
            'workType' => $this->payload($workType),
            // Un tipo con planes abiertos detrás no se borra a la ligera: esos
            // planes se quedan apuntando a un tipo que ya no está y dejan de
            // saber qué formatos se les exigen.
            'openPlans'  => $service->planesAbiertos($workType),
            'plansCount' => $workType->workPlans()->count(),
            'formsCount' => $workType->formTemplates()->count(),
        ]);
    }

    public function deleteSave(DeleteWorkTypeRequest $request, WorkType $workType, WorkTypeService $service): RedirectResponse
    {
        $service->delete($workType, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$workType->id]);

        return redirect()
            ->route('business_management.work_types.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', ['count' => 1, 'seconds' => 60]);
    }

    protected function storeUndoableDelete(array $ids): void
    {
        session(['work_types.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds(60)->toIso8601String(),
        ]]);
    }

    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $termino = $request->get('name', '');
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

        $tipos = WorkType::onlyTrashed()
            ->with(['country:id,name'])
            ->when($termino !== '', fn ($q) => $q->where('code', 'like', "%{$termino}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        // Quién borró cada tipo, en una consulta y no en N.
        $nombres = \App\Models\User::withTrashed()
            ->whereIn('id', $tipos->pluck('deleted_by')->filter()->unique())
            ->pluck('name', 'id');
        $tipos->getCollection()->transform(function ($tipo) use ($nombres) {
            $tipo->deleter_name = $nombres[$tipo->deleted_by] ?? null;

            return $tipo;
        });

        return inertia('WorkTypes/Trash', [
            'work_types' => $tipos,
            'filters'    => ['name' => $termino, 'per_page' => $perPage],
        ]);
    }

    public function restore(Request $request, $slug, WorkTypeService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $tipo = WorkType::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($tipo);

        return redirect()
            ->route('business_management.work_types.trash')
            ->with('success', __('global.restored_success'));
    }

    public function bulkRestore(BulkRestoreWorkTypeRequest $request, WorkTypeService $service): RedirectResponse
    {
        $resultado = $service->bulkRestore($request->validated()['ids']);

        if (! empty($resultado['queued'])) {
            return redirect()
                ->route('business_management.work_types.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        return redirect()
            ->route('business_management.work_types.trash')
            ->with('success', __('global.restored_success') . " ({$resultado['restored']})");
    }

    public function forceDelete(ForceDeleteWorkTypeRequest $request, $slug, WorkTypeService $service): RedirectResponse
    {
        $tipo = WorkType::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data = $request->validated();

        // Se confirma escribiendo el código: es lo que identifica el tipo a ojo
        // en la papelera, y lo único que el usuario reconoce.
        if (trim($data['name_confirmation']) !== $tipo->code) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($tipo, $data['reason']);

        return redirect()
            ->route('business_management.work_types.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    // ── Masivas ──────────────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteWorkTypeRequest $request, WorkTypeService $service): RedirectResponse
    {
        $data = $request->validated();
        $resultado = $service->bulkDelete($data['ids'], $data['deleted_description']);

        if (! empty($resultado['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        $this->storeUndoableDelete($resultado['deleted']);

        return back()
            ->with('success', __('global.deleted_success') . ' (' . $resultado['count'] . ')')
            ->with('recentDelete', ['count' => $resultado['count'], 'seconds' => 60]);
    }

    public function undoLastDelete(Request $request, WorkTypeService $service): RedirectResponse
    {
        $claim = session('work_types.recent_delete');
        if (! $claim || ! is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('work_types.recent_delete');

            return back()->with('error', __('global.undo_failed'));
        }

        $restaurados = $service->undoLastDelete($claim['ids'], (int) auth()->id());
        session()->forget('work_types.recent_delete');

        return empty($restaurados)
            ? back()->with('error', __('global.undo_failed'))
            : back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveWorkTypeRequest $request, WorkTypeService $service): RedirectResponse
    {
        $data = $request->validated();
        $resultado = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if (! empty($resultado['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        return back()->with('success', __('global.updated_success') . " ({$resultado['changed']})");
    }

    protected function payload(WorkType $m, bool $withAudit = false): array
    {
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'code'       => $m->code,
            'country_id' => $m->country_id,
            'country'    => $m->country?->name,
            'is_active'  => (bool) $m->is_active,
            'tenant_id'  => $m->tenant_id,
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
            'deleted_at' => $m->deleted_at,
        ];

        if ($withAudit) {
            $base['deleted_description'] = $m->deleted_description;
            $base['deleter'] = $m->deleted_by
                ? ['name' => \App\Models\User::withTrashed()->find($m->deleted_by)?->name]
                : null;
        }

        return $base;
    }
}
