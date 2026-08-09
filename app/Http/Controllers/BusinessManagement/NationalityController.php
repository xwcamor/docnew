<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\Nationality\BulkDeleteNationalityRequest;
use App\Http\Requests\BusinessManagement\Nationality\BulkSetActiveNationalityRequest;
use App\Http\Requests\BusinessManagement\Nationality\DeleteNationalityRequest;
use App\Http\Requests\BusinessManagement\Nationality\StoreNationalityRequest;
use App\Http\Requests\BusinessManagement\Nationality\UpdateNationalityRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Nationality;
use App\Services\BusinessManagement\NationalityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * La nacionalidad que se anota en la ficha de cada persona.
 *
 * Es un catalogo pequeño, pero lo que cuelga de el no lo es: personas con esta nacionalidad. Por eso el borrado pasa siempre por el servicio, que es la unica capa que ve a la vez el catalogo y lo que lo consume.
 */
class NationalityController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesGlobalRecords;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /**
     * Pone el candado. En un catalogo no es una formalidad: mientras este
     * puesto, la fila no se edita, no se borra, no se desactiva y las masivas
     * la saltan. Lo que trajo la migracion nace asi.
     */
    public function lock(Request $request, Nationality $nationality): RedirectResponse
    {
        return $this->applyLock($nationality, $request);
    }

    /** Saca el candado. Un admin no puede quitar uno que puso el sistema. */
    public function unlock(Request $request, Nationality $nationality): RedirectResponse
    {
        return $this->applyUnlock($nationality, $request);
    }

    public function index(Request $request, NationalityService $service)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200], true) ? $perPage : 10;

        // El catalogo se lee como una lista alfabetica, no como un historial:
        // por defecto manda el nombre y no la fecha de alta.
        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'code', 'direction' => 'asc']);
        }

        $isSuper = $request->user()?->hasRole('super') ?? false;

        $query = Nationality::query()->select('nationalities.*')
            ->with('country:id,name')
            ->when($isSuper, fn ($q) => $q->with('tenant:id,name'));

        $nationalities = NationalityService::filter($query, $request)->paginate($perPage)->withQueryString();

        // Cuantos registros dependen de cada fila: es el dato que decide si se
        // puede borrar, asi que se ve en la tabla y no solo al intentarlo.
        $service->contarUsos($nationalities->getCollection());

        $terminos = $request->get('name', []);
        if (is_string($terminos)) {
            $terminos = $terminos === '' ? [] : [$terminos];
        }

        return inertia('Nationalities/Index', [
            'nationalities' => array_merge($nationalities->toArray(), [
                'total_unfiltered' => Nationality::count(),
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
            'filterSchema' => NationalityService::filterSchema(),
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

    public function show(Request $request, Nationality $nationality, NationalityService $service)
    {
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', Nationality::class)
                    ->where('auditable_id', $nationality->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'auditable_type', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Nationalities/Show', [
            'nationality' => array_merge(
                $this->payload($nationality, withAudit: true, service: $service),
                ['lock' => $this->lockMeta($nationality, $request)],
            ),
            // Lo que cuelga de esta fila: es la respuesta a "¿por que no me deja
            // borrarla?" puesta antes de que la pregunta se haga.
            'usages'      => $service->usados($nationality),
            'recordAudit' => $this->recordAuditMeta($nationality),
            'activity'    => $activity,
        ]);
    }

    public function create(Request $request)
    {
        return inertia('Nationalities/Form', [
            'nationality' => null,
            'countryOptions'   => $this->countryOptions(),
            'defaultCountryId' => $request->user()?->country_id,
        ]);
    }

    public function store(StoreNationalityRequest $request, NationalityService $service): RedirectResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Nationality::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.nationalities.index')
            ->with('success', __('nationalities.created'));
    }

    public function edit(Request $request, Nationality $nationality, NationalityService $service)
    {
        // Bloqueado: ni se abre el formulario.
        abort_if($nationality->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('Nationalities/Form', [
            'nationality' => $this->payload($nationality, service: $service),
            'countryOptions'   => $this->countryOptions(),
            'defaultCountryId' => $request->user()?->country_id,
        ]);
    }

    public function update(UpdateNationalityRequest $request, Nationality $nationality, NationalityService $service): RedirectResponse
    {
        // Bloqueado: el formulario no se abre, pero el POST puede llegar igual.
        abort_if($nationality->is_locked, 403, __('locks.cannot_edit_locked'));

        $service->update($nationality, $request->validated());

        return redirect()
            ->route('business_management.nationalities.index')
            ->with('success', __('nationalities.saved'));
    }

    /**
     * Confirmacion de borrado. Si la fila esta en uso, la pantalla lo dice
     * ANTES de pedir el motivo y ofrece desactivarla en su lugar.
     */
    public function delete(Nationality $nationality, NationalityService $service)
    {
        // Bloqueado: ni se abre la confirmacion.
        abort_if($nationality->is_locked, 403, __('locks.cannot_delete_locked'));

        return inertia('Nationalities/Delete', [
            'nationality' => $this->payload($nationality, service: $service),
            'blockedReason' => $service->motivoParaNoBorrar($nationality),
        ]);
    }

    public function deleteSave(DeleteNationalityRequest $request, Nationality $nationality, NationalityService $service): RedirectResponse
    {
        // Bloqueado: y tampoco por la puerta de atras.
        abort_if($nationality->is_locked, 403, __('locks.cannot_delete_locked'));

        try {
            $service->delete($nationality, $request->validated()['deleted_description']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->storeUndoableDelete([$nationality->id]);

        return redirect()
            ->route('business_management.nationalities.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', ['count' => 1, 'seconds' => 60]);
    }

    /** Desactivar: la alternativa al borrado cuando la fila ya esta en uso. */
    public function deactivate(Nationality $nationality, NationalityService $service): RedirectResponse
    {
        // Desactivar tambien es cambiarlo: los planes nuevos dejarian de verlo.
        abort_if($nationality->is_locked, 403, __('locks.cannot_edit_locked'));

        $service->deactivate($nationality);

        return redirect()
            ->route('business_management.nationalities.index')
            ->with('success', __('nationalities.deactivated'));
    }

    protected function storeUndoableDelete(array $ids): void
    {
        session(['nationalities.recent_delete' => [
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

        $nationalities = Nationality::onlyTrashed()
            ->when($termino !== '', fn ($q) => $q->where('code', 'like', "%{$termino}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        // Quien borro cada fila, en una consulta y no en N: el modelo no lleva
        // la relacion y la papelera necesita el nombre, no el id.
        $nombres = \App\Models\User::withTrashed()
            ->whereIn('id', $nationalities->pluck('deleted_by')->filter()->unique())
            ->pluck('name', 'id');
        $nationalities->getCollection()->transform(function ($fila) use ($nombres) {
            $fila->deleter_name = $nombres[$fila->deleted_by] ?? null;

            return $fila;
        });

        return inertia('Nationalities/Trash', [
            'nationalities' => $nationalities,
            'filters' => ['name' => $termino, 'per_page' => $perPage],
        ]);
    }

    public function restore(Request $request, $slug, NationalityService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $fila = Nationality::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($fila);

        return redirect()
            ->route('business_management.nationalities.trash')
            ->with('success', __('global.restored_success'));
    }

    public function bulkRestore(Request $request, NationalityService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer']);

        $restaurados = $service->bulkRestore($data['ids']);

        return redirect()
            ->route('business_management.nationalities.trash')
            ->with('success', __('global.restored_success') . " ({$restaurados})");
    }

    // ── Masivas ──────────────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteNationalityRequest $request, NationalityService $service): RedirectResponse
    {
        $data = $request->validated();

        // Las bloqueadas se apartan antes de llegar al servicio: una masiva no
        // es sitio para saltarse un candado sin que nadie lo vea.
        [$permitidos, $bloqueados] = $this->splitLockedIds(Nationality::class, $data['ids']);
        if (empty($permitidos)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($bloqueados)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$permitidos, $globalIds] = $this->splitGlobalIds(Nationality::class, $permitidos);
        if (empty($permitidos)) {
            return back()->with('error', __('global.bulk_skipped_global', ['count' => count($globalIds)]));
        }

        $resultado = $service->bulkDelete($permitidos, $data['deleted_description']);

        if ($resultado['count'] === 0) {
            return back()->with('error', __('nationalities.bulk_all_protected', ['count' => $resultado['skipped']]));
        }

        $this->storeUndoableDelete($resultado['deleted']);

        $msg = __('global.deleted_success') . ' (' . $resultado['count'] . ')';
        if ($resultado['skipped'] > 0) {
            $msg .= ' · ' . __('nationalities.bulk_skipped_protected', ['count' => $resultado['skipped']]);
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

    public function bulkSetActive(BulkSetActiveNationalityRequest $request, NationalityService $service): RedirectResponse
    {
        $data = $request->validated();

        [$permitidos, $bloqueados] = $this->splitLockedIds(Nationality::class, $data['ids']);
        if (empty($permitidos)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($bloqueados)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$permitidos, $globalIds] = $this->splitGlobalIds(Nationality::class, $permitidos);
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

    public function undoLastDelete(Request $request, NationalityService $service): RedirectResponse
    {
        $claim = session('nationalities.recent_delete');
        if (! $claim || ! is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('nationalities.recent_delete');

            return back()->with('error', __('global.undo_failed'));
        }

        $restaurados = $service->undoLastDelete($claim['ids'], (int) auth()->id());
        session()->forget('nationalities.recent_delete');

        return empty($restaurados)
            ? back()->with('error', __('global.undo_failed'))
            : back()->with('success', __('global.undo_done'));
    }

    protected function payload(Nationality $m, bool $withAudit = false, ?NationalityService $service = null): array
    {
        $service ??= app(NationalityService::class);

        $base = [
            'id'          => $m->id,
            'slug'        => $m->slug,
            'code'      => $m->code,
            'country_id'  => $m->country_id,
            'country'     => $m->country?->name,
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
