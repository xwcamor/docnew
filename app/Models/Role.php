<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasFavorites;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Custom Role model — extiende Spatie con patrón Regions:
 *   - slug random 22 chars (autogenerado, solo super lo ve)
 *   - is_active (toggle sin borrar)
 *   - SoftDeletes con deleted_by + deleted_description
 *   - Auditable (audit log polimórfico)
 *
 * Activación en `config/permission.php` → 'models' => ['role' => App\Models\Role::class].
 */
class Role extends SpatieRole
{
    use SoftDeletes, Auditable, HasFavorites;

    protected string $auditModule = 'roles';

    /** @var array<string> */
    protected $fillable = [
        'name',
        'guard_name',
        'description',
        'is_active',
        'tenant_id',
        'created_by',
        'deleted_by',
        'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($role) {
            if (empty($role->slug)) {
                do {
                    $slug = Str::random(22);
                } while (static::query()->where('slug', $slug)->exists());
                $role->slug = $slug;
            }
            // is_active default = true si no se especifica.
            if ($role->is_active === null) {
                $role->is_active = true;
            }
        });
    }

    /**
     * Route model binding por slug — la URL expone el slug opaco (22 chars
     * random), nunca el id secuencial. Mismo patron que Region/Customer.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Para el `state_text` accessor usado en exports. */
    public function getStateTextAttribute(): string
    {
        return $this->is_active ? __('global.active') : __('global.inactive');
    }

    /**
     * Schema de filtros avanzados ("Agregar filtro" inline). Lo consume el
     * InlineFilterBuilder en el frontend y el FilterApplier en el index.
     */
    public static function filterSchema(): array
    {
        return [
            ['key' => 'name',        'label' => __('roles.name'),         'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'description', 'label' => __('roles.description'),  'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',   'label' => __('global.active'),      'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at',  'label' => __('global.created_at'),  'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at',  'label' => __('global.updated_at'),  'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
