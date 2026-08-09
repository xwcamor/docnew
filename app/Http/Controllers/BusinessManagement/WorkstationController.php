<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\Workstation\BulkDeleteWorkstationRequest;
use App\Http\Requests\BusinessManagement\Workstation\BulkSetActiveWorkstationRequest;
use App\Http\Requests\BusinessManagement\Workstation\DeleteWorkstationRequest;
use App\Http\Requests\BusinessManagement\Workstation\StoreWorkstationRequest;
use App\Http\Requests\BusinessManagement\Workstation\UpdateWorkstationRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Workstation;
use App\Services\BusinessManagement\WorkstationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Los puestos de cada sede. Un plan de trabajo se hace en uno de ellos.
 *
 * Es un catalogo pequeño, pero lo que cuelga de el no lo es: planes que lo usan. Por eso el borrado pasa siempre por el servicio, que es la unica capa que ve a la vez el catalogo y lo que lo consume.
 */
class WorkstationController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesGlobalRecords;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /**
     * Pone el candado. En un catalogo no es una formalidad: mientras este
     * puesto, la fila no se edita, no se borra, no se desactiva y las masivas
     * la saltan. Lo que trajo la migracion nace asi.
     */
    public function lock(Request $request, Workstation $workstation): RedirectResponse
    {
        return $this->applyLock($workstation, $request);
    }

    /** Saca el candado. Un admin no puede quitar uno que puso el sistema. */
    public function unlock(Request $request, Workstation $workstation): RedirectResponse
    {
        return $this->applyUnlock($workstation, $request);
    }

    public function index(Request $request, WorkstationService $service)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200], true) ? $perPage : 10;

        // El catalogo se lee como una lista alfabetica, no como un historial:
        // por defecto manda el nombre y no la fecha de alta.
        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'name', 'direction' => 'asc']);
        }

        $isSuper = $request->user()?->hasRole('super') ?? false;

        $query = Workstation::query()->select('workstations.*')
            ->with('workLocation:id,name')
            ->when($isSuper, fn ($q) => $q->with('tenant:id,name'));

        $workstations = WorkstationService::filter($query, $request)->paginate($perPage)->withQueryString();

        // Cuantos registros dependen de cada fila: es el dato que decide si se
        // puede borrar, asi que se ve en la tabla y no solo al intentarlo.
        $service->contarUsos($workstations->getCollection());

        $terminos = $request->get('name', []);
        if (is_string($terminos)) {
            $terminos = $terminos === '' ? [] : [$terminos];
        }

        return inertia('Workstations/Index', [
            'workstations' => array_merge($workstations->toArray(), [
                'total_unfiltered' => Workstation::count(),
            ]),
            'filters' => [
                'name'         => array_values($terminos),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                'sort'         => $request->get('sort', 'name'),
                'direction'    => $request->get('direction', 'asc'),
                'per_page'     => $perPage,
                'advanced_where' => $this->parseAdvancedWhere($request),
            ],
            'isSuper'      => $isSuper,
            'filterSchema' => WorkstationService::filterSchema(),
        ]);
    }

    /** Normaliza `advanced_where`: llega como JSON o como array segun Inertia. */
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

    public function show(Request $request, Workstation $workstation, WorkstationService $service)
    {
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', Workstation::class)
                    ->where('auditable_id', $workstation->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'auditable_type', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Workstations/Show', [
            'workstation' => array_merge(
                $this->payload($workstation, withAudit: true, service: $service),
                ['lock' => $this->lockMeta($workstation, $request)],
            ),
            // Lo que cuelga de esta fila: es la respuesta a "¿por que no me deja
            // borrarla?" puesta antes de que la pregunta se haga.
            'usages'      => $service->usados($workstation),
            'recordAudit' => $this->recordAuditMeta($workstation),
            'activity'    => $activity,
        ]);
    }

    public function create(Request $request)
    {
        return inertia('Workstations/Form', [
            'workstation' => null,
            'workLocationOptions' => $this->workLocationOptions(),
        ]);
    }

    public function store(StoreWorkstationRequest $request, WorkstationService $service): RedirectResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Workstation::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.workstations.index')
            ->with('success', __('workstations.created'));
    }

    public function edit(Request $request, Workstation $workstation, WorkstationService $service)
    {
        // Bloqueado: ni se abre el formulario.
        abort_if($workstation->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('Workstations/Form', [
            'workstation' => $this->payload($workstation, service: $service),
            'workLocationOptions' => $this->workLocationOptions($workstation),
        ]);
    }

    public function update(UpdateWorkstationRequest $request, Workstation $workstation, WorkstationService $service): RedirectResponse
    {
        // Bloqueado: el formulario no se abre, pero el POST puede llegar igual.
        abort_if($workstation->is_locked, 403, __('locks.cannot_edit_locked'));

        $service->update($workstation, $request->validated());

        return redirect()
            ->route('business_management.workstations.index')
            ->with('success', __('workstations.saved'));
    }

    /**
     * Confirmacion de borrado. Si la fila esta en uso, la pantalla lo dice
     * ANTES de pedir el motivo y ofrece desactivarla en su lugar.
     */
    public function delete(Workstation $workstation, WorkstationService $service)
    {
        // Bloqueado: ni se abre la confirmacion.
        abort_if($workstation->is_locked, 403, __('locks.cannot_delete_locked'));

        return inertia('Workstations/Delete', [
            'workstation' => $this->payload($workstation, service: $service),
            'blockedReason' => $service->motivoParaNoBorrar($workstation),
        ]);
    }

    public function deleteSave(DeleteWorkstationRequest $request, Workstation $workstation, WorkstationService $service): RedirectResponse
    {
        // Bloqueado: y tampoco por la puerta de atras.
        abort_if($workstation->is_locked, 403, __('locks.cannot_delete_locked'));

        try {
            $service->delete($workstation, $request->validated()['deleted_description']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->storeUndoableDelete([$workstation->id]);

        return redirect()
            ->route('business_management.workstations.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', ['count' => 1, 'seconds' => 60]);
    }

    /** Desactivar: la alternativa al borrado cuando la fila ya esta en uso. */
    public function deactivate(Workstation $workstation, WorkstationService $service): RedirectResponse
    {
        // Desactivar tambien es cambiarlo: los planes nuevos dejarian de verlo.
        abort_if($workstation->is_locked, 403, __('locks.cannot_edit_locked'));

        $service->deactivate($workstation);

        return redirect()
            ->route('business_management.workstations.index')
            ->with('success', __('workstations.deactivated'));
    }

    protected function storeUndoableDelete(array $ids): void
    {
        session(['workstations.recent_delete' => [
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

        $workstations = Workstation::onlyTrashed()
            ->when($termino !== '', fn ($q) => $q->where('name', 'like', "%{$termino}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        // Quien borro cada fila, en una consulta y no en N: el modelo no lleva
        // la relacion y la papelera necesita el nombre, no el id.
        $nombres = \App\Models\User::withTrashed()
            ->whereIn('id', $workstations->pluck('deleted_by')->filter()->unique())
            ->pluck('name', 'id');
        $workstations->getCollection()->transform(function ($fila) use ($nombres) {
            $fila->deleter_name = $nombres[$fila->deleted_by] ?? null;

            return $fila;
        });

        return inertia('Workstations/Trash', [
            'workstations' => $workstations,
            'filters' => ['name' => $termino, 'per_page' => $perPage],
        ]);
    }

    public function restore(Request $request, $slug, WorkstationService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $fila = Workstation::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($fila);

        return redirect()
            ->route('business_management.workstations.trash')
            ->with('success', __('global.restored_success'));
    }

    public function bulkRestore(Request $request, WorkstationService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer']);

        $restaurados = $service->bulkRestore($data['ids']);

        return redirect()
            ->route('business_management.workstations.trash')
            ->with('success', __('global.restored_success') . " ({$restaurados})");
    }

    // ── Masivas ──────────────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteWorkstationRequest $request, WorkstationService $service): RedirectResponse
    {
        $data = $request->validated();

        // Las bloqueadas se apartan antes de llegar al servicio: una masiva no
        // es sitio para saltarse un candado sin que nadie lo vea.
        [$permitidos, $bloqueados] = $this->splitLockedIds(Workstation::class, $data['ids']);
        if (empty($permitidos)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($bloqueados)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$permitidos, $globalIds] = $this->splitGlobalIds(Workstation::class, $permitidos);
        if (empty($permitidos)) {
            return back()->with('error', __('global.bulk_skipped_global', ['count' => count($globalIds)]));
        }

        $resultado = $service->bulkDelete($permitidos, $data['deleted_description']);

        if ($resultado['count'] === 0) {
            return back()->with('error', __('workstations.bulk_all_protected', ['count' => $resultado['skipped']]));
        }

        $this->storeUndoableDelete($resultado['deleted']);

        $msg = __('global.deleted_success') . ' (' . $resultado['count'] . ')';
        if ($resultado['skipped'] > 0) {
            $msg .= ' · ' . __('workstations.bulk_skipped_protected', ['count' => $resultado['skipped']]);
        }
        if (! empty($bloqueados)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($bloqueados)]);
        }
        if (!empty($globalIds)) {
            $msg .= ' · ' . __('global.bulk_skipped_global', ['count' => count($globalIds)]);
        }

        return back()
            ->with('success', $msg)
            ->with('recentDelete', ['count' => $resultado['count'], 'seconds' => 60]);
    }

    public function bulkSetActive(BulkSetActiveWorkstationRequest $request, WorkstationService $service): RedirectResponse
    {
        $data = $request->validated();

        [$permitidos, $bloqueados] = $this->splitLockedIds(Workstation::class, $data['ids']);
        if (empty($permitidos)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($bloqueados)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$permitidos, $globalIds] = $this->splitGlobalIds(Workstation::class, $permitidos);
        if (empty($permitidos)) {
            return back()->with('error', __('global.bulk_skipped_global', ['count' => count($globalIds)]));
        }

        $cambiados = $service->bulkSetActive($permitidos, (bool) $data['is_active']);

        $msg = __('global.updated_success') . " ({$cambiados})";
        if (! empty($bloqueados)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($bloqueados)]);
        }
        if (!empty($globalIds)) {
            $msg .= ' · ' . __('global.bulk_skipped_global', ['count' => count($globalIds)]);
        }

        return back()->with('success', $msg);
    }

    public function undoLastDelete(Request $request, WorkstationService $service): RedirectResponse
    {
        $claim = session('workstations.recent_delete');
        if (! $claim || ! is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('workstations.recent_delete');

            return back()->with('error', __('global.undo_failed'));
        }

        $restaurados = $service->undoLastDelete($claim['ids'], (int) auth()->id());
        session()->forget('workstations.recent_delete');

        return empty($restaurados)
            ? back()->with('error', __('global.undo_failed'))
            : back()->with('success', __('global.undo_done'));
    }

    protected function payload(Workstation $m, bool $withAudit = false, ?WorkstationService $service = null): array
    {
        $service ??= app(WorkstationService::class);

        $base = [
            'id'          => $m->id,
            'slug'        => $m->slug,
            'name'      => $m->name,
            'work_location_id' => $m->work_location_id,
            'work_location'    => $m->workLocation?->name,
            'is_active'   => $m->is_active,
            'tenant_id'   => $m->tenant_id,
            'usage_count' => $service->usos($m),
            'created_at'  => $m->created_at,
            'updated_at'  => $m->updated_at,
            'deleted_at'  => $m->deleted_at,
        ];

        if ($withAudit) {
            $base['deleted_description'] = $m->deleted_description;
            $base['deleter'] = $m->deleted_by
                ? ['name' => \App\Models\User::withTrashed()->find($m->deleted_by)?->name]
                : null;
        }

        return $base;
    }

    /**
     * Las sedes que se pueden elegir. Solo las activas: un puesto nuevo en una
     * sede que ya no se usa no lo quiere nadie, y la lista corta se lee mejor
     * con guantes.
     *
     * Excepcion: la sede que el puesto YA tiene entra siempre, aunque este
     * desactivada. Este mismo modulo ofrece «desactivar en su lugar» como
     * alternativa al borrado, asi que la situacion se da sola; y sin la opcion
     * en la lista el selector no encuentra su valor y pinta el numero crudo del
     * id donde deberia decir «Lurín».
     */
    protected function workLocationOptions(?Workstation $workstation = null): array
    {
        $sedes = \App\Models\WorkLocation::query()
            ->where(function ($q) use ($workstation) {
                $q->where('is_active', true);
                if ($workstation?->work_location_id) {
                    $q->orWhere('id', $workstation->work_location_id);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return $sedes
            ->map(fn ($s) => [
                'value' => $s->id,
                // Que este desactivada se dice, no se esconde: el supervisor
                // tiene que saber que esa sede ya no se ofrece en los planes.
                'label' => $s->is_active ? $s->name : $s->name . ' — ' . __('global.inactive'),
            ])
            ->all();
    }
}
