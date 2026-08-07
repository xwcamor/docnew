<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * HandlesRecordLocking — helpers compartidos para los controllers de módulos que
 * usan el trait Lockable (Customer, Transformer, …). Centraliza:
 *   - poner/sacar el candado con la autorización por nivel (canBeLockedBy /
 *     canBeUnlockedBy),
 *   - separar los IDs bloqueados de una operación masiva para no tocarlos.
 *
 * El lock se hace valer en esta capa (request/controller), NO en eventos de
 * modelo, para no frenar las escrituras internas (ej. el motor de diagnóstico
 * que persiste caché en transformers). Ver app/Traits/Lockable.php.
 */
trait HandlesRecordLocking
{
    /** Pone el candado (super → nivel 'super'; admin → 'tenant'). */
    protected function applyLock(Model $model, Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($model->canBeLockedBy($user), 403);

        if (! $model->is_locked) {
            $model->lock($user);
        }

        return back()->with('success', __('locks.locked'));
    }

    /** Saca el candado si el usuario tiene el nivel suficiente. */
    protected function applyUnlock(Model $model, Request $request): RedirectResponse
    {
        $user = $request->user();
        // Un admin NO puede sacar un candado puesto por el super.
        abort_unless($model->canBeUnlockedBy($user), 403);

        if ($model->is_locked) {
            $model->unlock();
        }

        return back()->with('success', __('locks.unlocked'));
    }

    /**
     * Separa los IDs bloqueados de un lote. Devuelve [permitidos, bloqueados].
     * La query respeta los global scopes (tenant), así que solo mira registros
     * que el usuario realmente alcanza.
     *
     * @param  class-string<Model>  $modelClass
     * @return array{0: array<int>, 1: array<int>}
     */
    protected function splitLockedIds(string $modelClass, array $ids): array
    {
        if (empty($ids)) {
            return [[], []];
        }
        $locked = $modelClass::whereIn('id', $ids)
            ->whereNotNull('locked_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allowed = array_values(array_diff(array_map('intval', $ids), $locked));

        return [$allowed, $locked];
    }

    /**
     * Datos de lock para el payload del front: estado + nivel + si el usuario
     * actual puede poner/sacar el candado.
     */
    protected function lockMeta(Model $model, Request $request): array
    {
        $user = $request->user();
        return [
            'is_locked'  => $model->is_locked,
            'lock_scope' => $model->lock_scope,
            'locked_at'  => $model->locked_at,
            'locked_by'  => $model->is_locked && $model->relationLoaded('locker') && $model->locker
                ? ['id' => $model->locker->id, 'name' => $model->locker->name]
                : null,
            'can_lock'   => ! $model->is_locked && $model->canBeLockedBy($user),
            'can_unlock' => $model->is_locked && $model->canBeUnlockedBy($user),
        ];
    }
}
