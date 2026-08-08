<?php

namespace App\Models;

use App\Support\LikeQuery;
use App\Traits\Auditable;
use App\Traits\BelongsToTenantOrGlobal;
use App\Traits\HasFavorites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Person — la identidad de quien trabaja en obra.
 *
 * En el sistema v1 una misma persona aparecía repetida una vez por cada empresa
 * en la que trabajaba. Aquí la identidad es única (documento + país) y lo que
 * cambia por empresa es el vínculo (`companyLinks`). La cara enrolada
 * (`activeBiometric`) y la firma de referencia también cuelgan de la persona,
 * no del vínculo. Es PER-TENANT, por eso usa BelongsToTenantOrGlobal.
 */
class Person extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal, HasFavorites, \App\Traits\Lockable;

    protected string $auditModule = 'people';


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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    /** Texto traducido del estado — consumido por exports (CSV/Excel/PDF/Word). */
    public function getStateTextAttribute(): string
    {
        return $this->is_active ? __('global.active') : __('global.inactive');
    }

    /**
     * scopeFilter — mismo patrón que Customer, sobre la tabla people.
     * Soporta name (multi-tag accent-insensitive), code (substring),
     * is_active (bool), rangos de fecha/id, filtros avanzados y favoritos.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'people';

        // Se busca por nombre O apellido indistintamente: nadie recuerda en qué
        // orden se cargó "Juan Carlos Pérez Gómez".
        $query->when($request->filled('name'), function ($q) use ($request, $isPgsql, $tbl) {
            $names = is_array($request->name) ? $request->name : [$request->name];
            $names = array_filter($names, fn ($n) => $n !== '');
            if (empty($names)) return;
            $q->where(function ($qq) use ($names, $isPgsql, $tbl) {
                foreach ($names as $name) {
                    $needle = LikeQuery::contains((string) $name);
                    foreach (['name', 'lastname'] as $col) {
                        if ($isPgsql) {
                            $qq->orWhereRaw("unaccent(lower({$tbl}.{$col})) LIKE unaccent(lower(?))", [$needle]);
                        } else {
                            $qq->orWhereRaw("{$tbl}.{$col} LIKE ? ESCAPE '\\'", [$needle]);
                        }
                    }
                }
            });
        });

        $query->when($request->filled('num_doc'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "{$tbl}.num_doc LIKE ?" : "{$tbl}.num_doc LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->num_doc)]);
        });

        $query->when($request->filled('doc_type'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.doc_type", (string) $request->doc_type);
        });

        $query->when($request->filled('country_id'), function ($q) use ($request, $tbl) {
            $ids = array_filter((array) $request->input('country_id'), fn ($v) => $v !== '' && $v !== null);
            if (empty($ids)) return;
            $q->whereIn("{$tbl}.country_id", array_map('intval', $ids));
        });

        // Rol en obra: trabajador, supervisor o supervisor HSE.
        $query->when($request->filled('role'), function ($q) use ($request) {
            $roles = array_filter((array) $request->input('role'), fn ($v) => $v !== '' && $v !== null);
            if (empty($roles)) return;
            $q->whereHas('roles', fn ($r) => $r->whereIn('role', $roles)->where('is_active', true));
        });

        // Empresa en la que trabaja hoy (el vínculo, no la identidad).
        $query->when($request->filled('company_id'), function ($q) use ($request) {
            $ids = array_filter((array) $request->input('company_id'), fn ($v) => $v !== '' && $v !== null);
            if (empty($ids)) return;
            $q->whereHas('companyLinks', fn ($l) => $l->whereIn('company_id', array_map('intval', $ids)));
        });

        // Cara enrolada: sin biometría vigente la persona no puede firmar en obra.
        $query->when($request->filled('has_biometric'), function ($q) use ($request) {
            $tiene = filter_var($request->has_biometric, FILTER_VALIDATE_BOOLEAN);
            $existe = fn ($b) => $b->where('is_active', true);
            $tiene ? $q->whereHas('biometrics', $existe) : $q->whereDoesntHave('biometrics', $existe);
        });

        $query->when($request->filled('is_active'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.is_active", filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        });

        $query->when($request->filled('created_from'), fn ($q) => $q->where("{$tbl}.created_at", '>=', $request->created_from . ' 00:00:00'));
        $query->when($request->filled('created_to'),   fn ($q) => $q->where("{$tbl}.created_at", '<=', $request->created_to . ' 23:59:59'));
        $query->when($request->filled('updated_from'), fn ($q) => $q->where("{$tbl}.updated_at", '>=', $request->updated_from . ' 00:00:00'));
        $query->when($request->filled('updated_to'),   fn ($q) => $q->where("{$tbl}.updated_at", '<=', $request->updated_to . ' 23:59:59'));
        $query->when($request->filled('id_from'), fn ($q) => $q->where("{$tbl}.id", '>=', (int) $request->id_from));
        $query->when($request->filled('id_to'),   fn ($q) => $q->where("{$tbl}.id", '<=', (int) $request->id_to));

        $advanced = $request->input('advanced_where');
        if (is_string($advanced)) {
            $advanced = json_decode($advanced, true) ?: null;
        }
        if (is_array($advanced) && !empty($advanced)) {
            \App\Services\Automations\Support\FilterApplier::apply(
                $query,
                ['where' => $advanced],
                static::filterSchema()
            );
        }

        if ($request->filled('only_favorites') && filter_var($request->only_favorites, FILTER_VALIDATE_BOOLEAN)) {
            $userId = auth()->id();
            if ($userId) {
                $query->whereExists(function ($q) use ($userId, $tbl) {
                    $q->select(\DB::raw(1))
                      ->from('user_favorites')
                      ->whereColumn('user_favorites.favoritable_id', "{$tbl}.id")
                      ->where('user_favorites.favoritable_type', static::class)
                      ->where('user_favorites.user_id', $userId);
                });
            }
        }

        $sort = $request->get('sort', 'id');
        $direction = $request->get('direction', 'desc');
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }
        if ($sort === 'tenant') {
            // Orden por workspace: nombre vía left join (nulls = global).
            $query->leftJoin('tenants', "{$tbl}.tenant_id", '=', 'tenants.id')
                  ->orderBy('tenants.name', $direction);
        } elseif ($sort === 'country') {
            $query->leftJoin('countries', "{$tbl}.country_id", '=', 'countries.id')
                  ->orderBy('countries.name', $direction);
        } elseif (in_array($sort, ['companies_count', 'company_links_count', 'signatures_count'], true)) {
            // Alias del withCount del controller — sin prefijo de tabla.
            $query->orderBy($sort === 'companies_count' ? 'company_links_count' : $sort, $direction);
        } elseif (in_array($sort, ['id', 'name', 'num_doc', 'doc_type', 'is_active', 'lastname', 'created_at', 'updated_at'], true)) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}>
     */
    public static function filterSchema(array $opts = []): array
    {
        return [
            ['key' => 'name',       'label' => __('people.name'),      'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'lastname',   'label' => __('people.lastname'),  'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'num_doc',    'label' => __('people.num_doc'),   'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'doc_type',   'label' => __('people.doc_type'),  'type' => 'enum',    'operators' => ['=', '!='], 'options' => $opts['doc_types'] ?? []],
            ['key' => 'country_id', 'label' => __('people.country'),   'type' => 'enum',    'operators' => ['=', '!=', 'in'], 'options' => $opts['countries'] ?? []],
            ['key' => 'is_active',  'label' => __('people.is_active'), 'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    use SoftDeletes;

    protected $fillable = [
        'slug', 'country_id', 'nationality_id', 'doc_type', 'num_doc',
        'name', 'lastname', 'birthdate', 'is_active', 'legacy_id', 'legacy_table',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = ['is_active' => 'boolean', 'birthdate' => 'date'];

    public function getFullNameAttribute(): string
    {
        return trim($this->name . ' ' . $this->lastname);
    }

    /**
     * El apellido primero, que es como se lista a la gente en obra y como lo
     * hacia el sistema anterior (`Worker#str_complete_name_pro`).
     */
    public function getListNameAttribute(): string
    {
        return trim($this->lastname . ' ' . $this->name);
    }

    /**
     * El documento tal y como puede verlo quien esta mirando: entero si tiene
     * `people.view_private_info`, y si no `******78`.
     *
     * **Esto es lo que se manda al navegador.** `num_doc` a secas se queda en
     * el servidor: sirve para buscar y para comparar, no para pintar. La regla
     * viene del sistema anterior, donde el DNI solo salia completo con
     * `display_private_info`, y me la habia saltado.
     */
    public function getSafeNumDocAttribute(): ?string
    {
        return \App\Support\PrivateInfo::documento($this->num_doc);
    }

    public function country() { return $this->belongsTo(Country::class); }
    public function companyLinks() { return $this->hasMany(PersonCompanyLink::class); }
    public function companies() { return $this->belongsToMany(Company::class, 'person_company_links'); }
    public function roles() { return $this->hasMany(PersonRole::class); }
    public function biometrics() { return $this->hasMany(PersonBiometric::class); }
    public function signatures() { return $this->hasMany(PersonSignature::class); }
    public function signatureEvents() { return $this->hasMany(SignatureEvent::class); }

    /** Biometria vigente: es la que se compara al firmar. */
    public function activeBiometric()
    {
        return $this->hasOne(PersonBiometric::class)->where('is_active', true)->latestOfMany();
    }

    /** Firma de referencia vigente. */
    public function currentSignature()
    {
        return $this->hasOne(PersonSignature::class)->whereNull('valid_to')->latestOfMany();
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('role', $role)->where('is_active', true)->exists();
    }

}
