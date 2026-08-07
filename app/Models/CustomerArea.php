<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * CustomerArea — Área de una ubicación (nivel 2 de la jerarquía).
 */
class CustomerArea extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal;

    protected string $auditModule = 'customer_areas';

    protected $fillable = [
        'slug', 'customer_location_id', 'name',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(CustomerLocation::class, 'customer_location_id');
    }

    public function substations(): HasMany
    {
        return $this->hasMany(CustomerSubstation::class)->orderBy('name');
    }
}
