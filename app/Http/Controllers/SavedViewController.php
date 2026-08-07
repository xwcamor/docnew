<?php

namespace App\Http\Controllers;

use App\Models\SavedView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SavedViewController — CRUD per-user de vistas guardadas, agnóstico al módulo.
 *
 * Todos los endpoints están scopeados al usuario autenticado: nunca se puede
 * leer/modificar la vista de otro usuario, incluso conociendo el ID.
 */
class SavedViewController extends Controller
{
    /**
     * Lista las vistas del usuario para un módulo dado (?module=regions).
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'module' => 'required|string|max:60',
        ]);

        $views = SavedView::query()
            ->forUser(Auth::id())
            ->forModule($data['module'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default', 'state', 'created_at', 'updated_at']);

        return response()->json(['views' => $views]);
    }

    /**
     * Crea una vista nueva. Si llega is_default=true, las otras del mismo
     * módulo del usuario se marcan como false (single default constraint).
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $view = DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                $this->clearOtherDefaults($data['module']);
            }
            return SavedView::create([
                'user_id'    => Auth::id(),
                'module'     => $data['module'],
                'name'       => $data['name'],
                'is_default' => $data['is_default'],
                'state'      => $data['state'],
            ]);
        });

        return response()->json(['view' => $view], 201);
    }

    /**
     * Actualiza una vista (rename, cambiar default, actualizar state).
     */
    public function update(Request $request, int $id)
    {
        $view = SavedView::query()
            ->forUser(Auth::id())
            ->findOrFail($id);

        $data = $this->validatePayload($request, $view->module);

        DB::transaction(function () use ($view, $data) {
            if ($data['is_default'] && ! $view->is_default) {
                $this->clearOtherDefaults($view->module, exceptId: $view->id);
            }
            $view->update([
                'name'       => $data['name'],
                'is_default' => $data['is_default'],
                'state'      => $data['state'],
            ]);
        });

        return response()->json(['view' => $view->fresh()]);
    }

    /**
     * Borra una vista del usuario.
     */
    public function destroy(int $id)
    {
        $view = SavedView::query()
            ->forUser(Auth::id())
            ->findOrFail($id);

        $view->delete();

        return response()->json(['ok' => true]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Pone is_default=false en todas las otras vistas del mismo (user, module).
     * Esto garantiza el constraint "solo una default por user+module" incluso
     * en drivers donde el partial unique index no se aplica (SQLite, MySQL).
     */
    protected function clearOtherDefaults(string $module, ?int $exceptId = null): void
    {
        SavedView::query()
            ->forUser(Auth::id())
            ->forModule($module)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    /**
     * Validación compartida entre store y update.
     *
     * Para update, el `module` viene del propio recurso (no del request) —
     * no permitimos mudar una vista de un módulo a otro.
     */
    protected function validatePayload(Request $request, ?string $forceModule = null): array
    {
        $rules = [
            'name'       => 'required|string|max:120',
            'is_default' => 'boolean',
            'state'      => 'required|array',
        ];

        if ($forceModule === null) {
            $rules['module'] = 'required|string|max:60';
        }

        $data = $request->validate($rules);

        return [
            'module'     => $forceModule ?? $data['module'],
            'name'       => trim($data['name']),
            'is_default' => filter_var($data['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'state'      => $data['state'],
        ];
    }
}
