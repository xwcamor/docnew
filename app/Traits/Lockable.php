<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lockable — "congela" un registro: lo vuelve inmutable para el usuario (no se
 * puede editar, eliminar, duplicar-sobre, pisar por import, edit-all ni bulk)
 * hasta que alguien con nivel suficiente lo DESBLOQUEE.
 *
 * Por qué la protección NO va en eventos de modelo (updating/deleting):
 *   El motor de diagnóstico le hace `save()` directo a los transformers para
 *   persistir caché (health_index, gassing_rate, ieee_condition…). Un guard en
 *   `updating` frenaría ESE recálculo interno y rompería el motor. Por eso el
 *   lock se aplica en la CAPA DE REQUEST (FormRequests + controllers): bloquea
 *   las acciones del usuario sin tocar las escrituras internas del sistema.
 *
 * Niveles (lock_scope) — definen quién puede sacarlo:
 *   - 'tenant': lo puso un admin → lo saca ese admin (o el super).
 *   - 'super' : lo puso el super → SOLO el super lo saca. El admin lo ve con un
 *               candado "del sistema" pero no lo puede quitar.
 *
 * Requiere columnas: locked_at (timestamp nullable), locked_by (fk users
 * nullable), lock_scope (string nullable).
 */
trait Lockable
{
    public function initializeLockable(): void
    {
        $this->mergeCasts(['locked_at' => 'datetime']);
    }

    /** ¿Está bloqueado? */
    public function getIsLockedAttribute(): bool
    {
        return $this->locked_at !== null;
    }

    /** ¿Este usuario puede PONER un candado? super o admin. */
    public function canBeLockedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        // Registro GLOBAL/compartido (tenant_id null): solo el super lo gestiona.
        // Un admin no puede mutarlo (el guard de globales lo rechazaría), así que
        // tampoco se le ofrece bloquearlo — el botón Bloquear no aparece.
        if ($this->tenant_id === null && ! $user->hasRole('super')) {
            return false;
        }
        return $user->hasRole('super') || $user->hasRole('admin');
    }

    /** ¿Este usuario puede SACAR el candado actual? (jerarquía de niveles) */
    public function canBeUnlockedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->hasRole('super')) {
            return true; // el super saca cualquiera
        }
        // admin: solo candados de nivel tenant (NUNCA un candado del super).
        return $this->lock_scope === 'tenant' && $user->hasRole('admin');
    }

    /**
     * Pone el candado. El nivel sale del rol de quien bloquea: super → 'super'
     * (solo super lo saca); admin → 'tenant'. Audita vía Auditable (save()).
     */
    public function lock(User $by): void
    {
        $this->locked_at  = now();
        $this->locked_by  = $by->id;
        $this->lock_scope = $by->hasRole('super') ? 'super' : 'tenant';
        $this->save();
    }

    /** Saca el candado. La autorización (quién puede) se valida ANTES, en el controller. */
    public function unlock(): void
    {
        $this->locked_at  = null;
        $this->locked_by  = null;
        $this->lock_scope = null;
        $this->save();
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by')->withTrashed();
    }
}
