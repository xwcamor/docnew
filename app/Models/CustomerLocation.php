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
 * CustomerLocation — Ubicación de un cliente (nivel 1 de la jerarquía).
 *   Customer → Ubicación → Área → Subestación → Transformador
 */
class CustomerLocation extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal;

    protected string $auditModule = 'customer_locations';

    protected $fillable = [
        'slug', 'customer_id', 'name',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(CustomerArea::class)->orderBy('name');
    }
}
