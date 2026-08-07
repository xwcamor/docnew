<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * ConnectionType — catálogo global de grupos de conexión / grupo vectorial
 * (Dyn5, Dyn11, Yy0, …). Metadato descriptivo del transformador (NO eje de
 * diagnóstico). Catálogo GLOBAL (sin tenant). Sin módulo CRUD por ahora.
 */
class ConnectionType extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected string $auditModule = 'connection_types';

    protected $fillable = [
        'slug', 'name', 'code', 'is_active', 'sort_order',
        'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->slug)) {
                do {
                    $slug = Str::random(22);
                } while (static::withTrashed()->where('slug', $slug)->exists());
                $model->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Transformadores con este grupo de conexión (FK connection_type_id). */
    public function transformers(): HasMany
    {
        return $this->hasMany(Transformer::class, 'connection_type_id');
    }
}
