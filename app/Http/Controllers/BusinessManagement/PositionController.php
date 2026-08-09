<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\Position\BulkDeletePositionRequest;
use App\Http\Requests\BusinessManagement\Position\BulkSetActivePositionRequest;
use App\Http\Requests\BusinessManagement\Position\DeletePositionRequest;
use App\Http\Requests\BusinessManagement\Position\StorePositionRequest;
use App\Http\Requests\BusinessManagement\Position\UpdatePositionRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Position;
use App\Services\BusinessManagement\PositionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Qué hace cada persona en obra, y cuáles de esos cargos pueden firmar una aprobación.
 *
 * Es un catalogo pequeño, pero lo que cuelga de el no lo es: personas con este cargo. Por eso el borrado pasa siempre por el servicio, que es la unica capa que ve a la vez el catalogo y lo que lo consume.
 */
class PositionController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesGlobalRecords;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /**
     * Pone el candado. En un catalogo no es una formalidad: mientras este
     * puesto, la fila no se edita, no se borra, no se desactiva y las masivas
     * la saltan. Lo que trajo la migracion nace asi.
     */
    public function lock(Request $request, Position $position): RedirectResponse
    {
        return $this->applyLock($position, $request);
    }

    /** Saca el candado. Un admin no puede quitar uno que puso el sistema. */
    public function unlock(Request $request, Position $position): RedirectResponse
    {
        return $this->applyUnlock($position, $request);
    }

    public function index(Request $request, PositionService $service)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200], true) ? $perPage : 10;

        // El catalogo se lee como una lista alfabetica, no como un historial:
        // por defecto manda el nombre y no la fecha de alta.
        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'code', 'direction' => 'asc']);
        }

        $isSuper = $request->user()?->hasRole('super') ?? false;

        $query = Position::query()->select('positions.*')
            ->with('country:id,name')
            ->when($isSuper, fn ($q) => $q->with('tenant:id,name'));

        $positions = PositionService::filter($query, $request)->paginate($perPage)->withQueryString();

        // Cuantos registros dependen de cada fila: es el dato que decide si se
        // puede borrar, asi que se ve en la tabla y no solo al intentarlo.
        $service->contarUsos($positions->getCollection());

        $terminos = $request->get('name', []);
        if (is_string($terminos)) {
            $terminos = $terminos === '' ? [] : [$terminos];
        }

        return inertia('Positions/Index', [
            'positions' => array_merge($positions->toArray(), [
                'total_unfiltered' => Position::count(),
            ]),
            'filters' => [
                'name'         => array_values($terminos),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                'sort'         => $request->get('sort', 'code'),
                'direction'    => $request->get('direction', 'asc'),
                'per_page'     => $perPage,
                'advanced_where' => $this->parseAdvancedWhere($request),
            ],
            'isSuper'      => $isSuper,
            'filterSchema' => PositionService::filterSchema(),
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

    public function show(Request $request, Position $position, PositionService $service)
    {
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', Position::class)
                    ->where('auditable_id', $position->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'auditable_type', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Positions/Show', [
            'position' => array_merge(
                $this->payload($position, withAudit: true, service: $service),
                ['lock' => $this->lockMeta($position, $request)],
            ),
            // Lo que cuelga de esta fila: es la respuesta a "¿por que no me deja
            // borrarla?" puesta antes de que la pregunta se haga.
            'usages'      => $service->usados($position),
            'recordAudit' => $this->recordAuditMeta($position),
            'activity'    => $activity,
        ]);
    }

    public function create(Request $request)
    {
        return inertia('Positions/Form', [
            'position' => null,
            'countryOptions'   => $this->countryOptions(),
            'defaultCountryId' => $request->user()?->country_id,
        ]);
    }

    public function store(StorePositionRequest $request, PositionService $service): RedirectResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Position::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.positions.index')
            ->with('success', __('positions.created'));
    }

    public function edit(Request $request, Position $position, PositionService $service)
    {
        // Bloqueado: ni se abre el formulario.
        abort_if($position->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('Positions/Form', [
            'position' => $this->payload($position, service: $service),
            'countryOptions'   => $this->countryOptions(),
            'defaultCountryId' => $request->user()?->country_id,
        ]);
    }

    public function update(UpdatePositionRequest $request, Position $position, PositionService $service): RedirectResponse
    {
        // Bloqueado: el formulario no se abre, pero el POST puede llegar igual.
        abort_if($position->is_locked, 403, __('locks.cannot_edit_locked'));

        $service->update($position, $request->validated());

        return redirect()
            ->route('business_management.positions.index')
            ->with('success', __('positions.saved'));
    }

    /**
     * Confirmacion de borrado. Si la fila esta en uso, la pantalla lo dice
     * ANTES de pedir el motivo y ofrece desactivarla en su lugar.
     */
    public function delete(Position $position, PositionService $service)
    {
        // Bloqueado: ni se abre la confirmacion.
        abort_if($position->is_locked, 403, __('locks.cannot_delete_locked'));

        return inertia('Positions/Delete', [
            'position' => $this->payload($position, service: $service),
            'blockedReason' => $service->motivoParaNoBorrar($position),
        ]);
    }

    public function deleteSave(DeletePositionRequest $request, Position $position, PositionService $service): RedirectResponse
    {
        // Bloqueado: y tampoco por la puerta de atras.
        abort_if($position->is_locked, 403, __('locks.cannot_delete_locked'));

        try {
            $service->delete($position, $request->validated()['deleted_description']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->storeUndoableDelete([$position->id]);

        return redirect()
            ->route('business_management.positions.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', ['count' => 1, 'seconds' => 60]);
    }

    /** Desactivar: la alternativa al borrado cuando la fila ya esta en uso. */
    public function deactivate(Position $position, PositionService $service): RedirectResponse
    {
        // Desactivar tambien es cambiarlo: los planes nuevos dejarian de verlo.
        abort_if($position->is_locked, 403, __('locks.cannot_edit_locked'));

        $service->deactivate($position);

        return redirect()
            ->route('business_management.positions.index')
            ->with('success', __('positions.deactivated'));
    }

    protected function storeUndoableDelete(array $ids): void
    {
        session(['positions.recent_delete' => [
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

        $positions = Position::onlyTrashed()
            ->when($termino !== '', fn ($q) => $q->where('code', 'like', "%{$termino}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        // Quien borro cada fila, en una consulta y no en N: el modelo no lleva
        // la relacion y la papelera necesita el nombre, no el id.
        $nombres = \App\Models\User::withTrashed()
            ->whereIn('id', $positions->pluck('deleted_by')->filter()->unique())
            ->pluck('name', 'id');
        $positions->getCollection()->transform(function ($fila) use ($nombres) {
            $fila->deleter_name = $nombres[$fila->deleted_by] ?? null;

            return $fila;
        });

        return inertia('Positions/Trash', [
            'positions' => $positions,
            'filters' => ['name' => $termino, 'per_page' => $perPage],
        ]);
    }

    public function restore(Request $request, $slug, PositionService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $fila = Position::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($fila);

        return redirect()
            ->route('business_management.positions.trash')
            ->with('success', __('global.restored_success'));
    }

    public function bulkRestore(Request $request, PositionService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer']);

        $restaurados = $service->bulkRestore($data['ids']);

        return redirect()
            ->route('business_management.positions.trash')
            ->with('success', __('global.restored_success') . " ({$restaurados})");
    }

    // ── Masivas ──────────────────────────────────────────────────────────────

    public function bulkDelete(BulkDeletePositionRequest $request, PositionService $service): RedirectResponse
    {
        $data = $request->validated();

        // Las bloqueadas se apartan antes de llegar al servicio: una masiva no
        // es sitio para saltarse un candado sin que nadie lo vea.
        [$permitidos, $bloqueados] = $this->splitLockedIds(Position::class, $data['ids']);
        if (empty($permitidos)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($bloqueados)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$permitidos, $globalIds] = $this->splitGlobalIds(Position::class, $permitidos);
        if (empty($permitidos)) {
            return back()->with('error', __('global.bulk_skipped_global', ['count' => count($globalIds)]));
        }

        $resultado = $service->bulkDelete($permitidos, $data['deleted_description']);

        if ($resultado['count'] === 0) {
            return back()->with('error', __('positions.bulk_all_protected', ['count' => $resultado['skipped']]));
        }

        $this->storeUndoableDelete($resultado['deleted']);

        $msg = __('global.deleted_success') . ' (' . $resultado['count'] . ')';
        if ($resultado['skipped'] > 0) {
            $msg .= ' · ' . __('positions.bulk_skipped_protected', ['count' => $resultado['skipped']]);
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

    public function bulkSetActive(BulkSetActivePositionRequest $request, PositionService $service): RedirectResponse
    {
        $data = $request->validated();

        [$permitidos, $bloqueados] = $this->splitLockedIds(Position::class, $data['ids']);
        if (empty($permitidos)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($bloqueados)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$permitidos, $globalIds] = $this->splitGlobalIds(Position::class, $permitidos);
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

    public function undoLastDelete(Request $request, PositionService $service): RedirectResponse
    {
        $claim = session('positions.recent_delete');
        if (! $claim || ! is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('positions.recent_delete');

            return back()->with('error', __('global.undo_failed'));
        }

        $restaurados = $service->undoLastDelete($claim['ids'], (int) auth()->id());
        session()->forget('positions.recent_delete');

        return empty($restaurados)
            ? back()->with('error', __('global.undo_failed'))
            : back()->with('success', __('global.undo_done'));
    }

    protected function payload(Position $m, bool $withAudit = false, ?PositionService $service = null): array
    {
        $service ??= app(PositionService::class);

        $base = [
            'id'          => $m->id,
            'slug'        => $m->slug,
            'code'      => $m->code,
            'country_id'  => $m->country_id,
            'country'     => $m->country?->name,
            'is_signature_approver' => (bool) $m->is_signature_approver,
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

    /** Paises activos como opciones del selector. */
    protected function countryOptions(): array
    {
        return \App\Models\Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->iso_code . ')'])
            ->all();
    }
}
