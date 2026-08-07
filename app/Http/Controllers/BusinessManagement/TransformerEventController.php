<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\Transformer;
use App\Models\TransformerEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * TransformerEventController — bitácora del transformador (comentarios/eventos).
 *
 * CRUD desde la pestaña "Bitácora" del detalle. Cada evento es puntual o de tramo
 * y cae en una columna del kanban (status). Redirige al "ver".
 */
class TransformerEventController extends Controller
{
    public function store(Request $request, Transformer $transformer): RedirectResponse
    {
        $event = new TransformerEvent();
        $event->forceFill(array_merge($this->validateData($request), [
            'transformer_id' => $transformer->id,
            'created_by'     => auth()->id(),
        ]));
        $event->save();

        return $this->back($transformer, __('bitacora.created'));
    }

    public function update(Request $request, Transformer $transformer, int $event): RedirectResponse
    {
        $model = $transformer->events()->findOrFail($event);
        $model->forceFill($this->validateData($request))->save();

        return $this->back($transformer, __('bitacora.saved'));
    }

    public function destroy(Transformer $transformer, int $event): RedirectResponse
    {
        $transformer->events()->findOrFail($event)->delete();

        return $this->back($transformer, __('bitacora.deleted'));
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'title'     => ['required', 'string', 'max:150'],
            'body'      => ['nullable', 'string', 'max:5000'],
            'status'    => ['required', Rule::in(TransformerEvent::STATUSES)],
            'category'  => ['nullable', Rule::in(TransformerEvent::CATEGORIES)],
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
        $data['color'] = null;

        return $data;
    }

    private function back(Transformer $transformer, string $message): RedirectResponse
    {
        return redirect()
            ->route('business_management.transformers.show', $transformer->slug)
            ->with('success', $message);
    }
}
