<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AutomationRun — log inmutable de cada ejecución.
 *
 * Sin soft-delete (append-only). Status: running → success | failed.
 * Si falla, error_message tiene el detalle. records_matched indica cuántas
 * filas devolvió la data_source (NULL si la action no consume datos).
 */
class AutomationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'automation_id', 'tenant_id',
        'started_at', 'finished_at', 'status',
        'records_matched', 'output_summary', 'error_message',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class)->withTrashed();
    }
}
