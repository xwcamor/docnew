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

    protected $fillable = ['slug', 'code', 'name_es', 'name_en', 'name_i18n', 'sort_order',
                           'is_active', 'tenant_id', 'created_by', 'deleted_by', 'deleted_description'];

    protected $casts = ['name_i18n' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];

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

    /**
     * El nombre en el idioma en que se esta mirando la aplicacion.
     *
     * Con caida al otro. El formulario ya no pide el nombre en ingles —lo que
     * se traduce es la aplicacion, no lo que escribe el cliente— asi que esa
     * columna llega vacia en todo rol nuevo. Sin la caida, la pantalla en
     * ingles enseñaba el selector de aprobadores con las opciones en blanco.
     */
    public function getLabelAttribute(): string
    {
        // es/en en sus columnas, el resto en `name_i18n`; `de()` resuelve
        // idioma pedido → respaldo → el primero que haya. Es la misma pieza que
        // lee los nombres de campo y de formato (ver FormField::label), y lo
        // que hace que un rol como «Supervisor Autorizante - HITACHI» pueda
        // leerse en un idioma que hoy no existe sin tocar ninguna tabla.
        $texto = \App\Support\TextoTraducible::de(\App\Support\TextoTraducible::fundir(
            ['es' => $this->name_es, 'en' => $this->name_en],
            $this->name_i18n,
        ));

        return $texto !== '' ? $texto : (string) $this->code;
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
