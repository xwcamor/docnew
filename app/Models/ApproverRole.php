<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quien puede firmar la aprobacion de un plan.
 *
 * Antes eran tres constantes en codigo —trabajador, supervisor, supervisor
 * HSE—, asi que anadir un «Cliente» o un «Jefe de obra» era tocar el codigo y
 * desplegar. Aqui es una fila.
 *
 * Los tres de siempre nacen globales (`tenant_id` nulo) y los ve todo el mundo;
 * un workspace puede anadir los suyos sin que aparezcan en los demas.
 */
class ApproverRole extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;
    use BelongsToTenantOrGlobal;

    protected $fillable = ['slug', 'code', 'name_es', 'name_en', 'sort_order',
                           'is_active', 'tenant_id', 'created_by', 'deleted_by', 'deleted_description'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    /** Los tres que trae el sistema. Se conservan como constantes porque el
     *  motor de migracion y las reglas sembradas los nombran por su codigo. */
    public const WORKER = 'worker';
    public const SUPERVISOR = 'supervisor';
    public const HSE_SUPERVISOR = 'hse_supervisor';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function rules()
    {
        return $this->hasMany(ApprovalRule::class, 'approver_role', 'code');
    }

    /** El nombre en el idioma en que se esta mirando la aplicacion. */
    public function getLabelAttribute(): string
    {
        return app()->getLocale() === 'en' ? $this->name_en : $this->name_es;
    }

    /** code => etiqueta, para pintar selectores sin repetir la consulta. */
    public static function opciones(): array
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->code => $r->label])
            ->all();
    }
}
