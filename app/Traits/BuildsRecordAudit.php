<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Arma los metadatos de auditoría de un registro (quién/cuándo creó y actualizó)
 * para la card "Auditoría del registro" del tab Historial — IGUAL en todos los
 * módulos. La ve cualquier usuario que pueda abrir el Show (no se gatea).
 *
 * - created_by: relación `creator` del modelo (si existe); si falta, el actor
 *   del evento 'created' del audit log.
 * - updated_by: actor del último evento de edición CON usuario (ignora los
 *   eventos del sistema sin actor). El modelo no necesita columna updated_by.
 *
 * El feed completo de actividad (timeline) es OTRA cosa y SÍ se gatea a
 * super/admin: lo arma cada controller con su propio método de actividad.
 */
trait BuildsRecordAudit
{
    protected function recordAuditMeta(Model $model): array
    {
        $base = AuditLog::where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', $model->getKey());

        $createdBy = (method_exists($model, 'creator') ? $model->creator?->name : null)
            ?: (clone $base)->where('event', 'created')->with('user:id,name')->first()?->user?->name;

        $updated = (clone $base)
            ->whereIn('event', ['updated', 'restored'])
            ->whereNotNull('user_id')
            ->with('user:id,name')
            ->latest('created_at')
            ->first();

        return [
            'created_at' => $model->created_at?->toIso8601String(),
            'created_by' => $createdBy ?: null,
            'updated_at' => $model->updated_at?->toIso8601String(),
            'updated_by' => $updated?->user?->name ?: null,
        ];
    }
}
