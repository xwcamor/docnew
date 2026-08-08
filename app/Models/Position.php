<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\Lockable;
use App\Traits\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;
    use Lockable;
    use BelongsToTenantOrGlobal;

    // `code` faltaba, y sin el la tabla no se podia poblar: es el nombre del
    // cargo (Tecnico, Supervisor, Mecanico, Electrico). Salio al migrar los 372
    // trabajadores de la v1, que todos traen cargo y llegaban sin ninguno.
    //
    // `legacy_id` faltaba tambien, y este era mas callado: la columna existe y
    // la migracion la escribe, pero al no estar aqui Eloquent la descartaba en
    // silencio. Los cargos llegaban —por eso no se notaba— pero sin de donde
    // venian, que es lo unico que distingue lo que trajo la v1 de lo que se dio
    // de alta a mano.
    protected $fillable = [
        'slug', 'country_id', 'code', 'is_signature_approver', 'is_active', 'legacy_id',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_signature_approver' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    /** Los vinculos persona–empresa que llevan este cargo. */
    public function personCompanyLinks()
    {
        return $this->hasMany(PersonCompanyLink::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
