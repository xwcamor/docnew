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
 * CustomerSubstation — Subestación de un área (nivel 3). Cuelgan transformadores.
 */
class CustomerSubstation extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal;

    protected string $auditModule = 'customer_substations';

    protected $fillable = [
        'slug', 'customer_area_id', 'name',
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

    public function area(): BelongsTo
    {
        return $this->belongsTo(CustomerArea::class, 'customer_area_id');
    }

    public function transformers(): HasMany
    {
        return $this->hasMany(Transformer::class)->orderBy('serial');
    }
}
