<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TransformerEvent — una entrada de la bitácora del transformador.
 *
 * Comentario/evento puntual (starts_at) o de tramo (starts_at..ends_at). Alimenta
 * la línea de tiempo y el kanban del detalle. `status` = columna del kanban.
 */
class TransformerEvent extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenant;

    protected string $auditModule = 'transformer_events';

    /** Columnas válidas del kanban (orden = orden de las columnas). */
    public const STATUSES = ['pendiente', 'en_seguimiento', 'resuelto'];

    /** Categorías sugeridas (etiqueta libre, no obligatoria). */
    public const CATEGORIES = ['observacion', 'mantenimiento', 'alarma', 'ensayo'];

    protected $fillable = [
        'transformer_id', 'title', 'body', 'status', 'category', 'color',
        'starts_at', 'ends_at', 'tenant_id', 'created_by', 'deleted_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    public function transformer(): BelongsTo
    {
        return $this->belongsTo(Transformer::class);
    }
}
