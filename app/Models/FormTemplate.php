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
 * FormTemplate — un **documento** de los que se llenan en obra: el AST, el Pare
 * Tome 5, la inspección de EPP, la de herramientas.
 *
 * En pantalla se llaman «documentos», que es como se dicen en obra y como los
 * llamaba el sistema anterior (`plan_documents`, `f1_documents`).
 * `form_templates` es el nombre de la tabla.
 *
 * El docblock que había aquí describía un catálogo de marcas de
 * transformadores: viene de que el módulo se generó clonando `Brand`, y
 * mencionaba una columna `sort_order` que esta tabla no tiene.
 *
 * Es PER-TENANT (BelongsToTenantOrGlobal, con bypass de super) y mantiene
 * SoftDeletes + Auditable + HasFavorites. Columnas propias: `country_id`,
 * `code`, `name`, `kind`, `status`, `version`, `requires_signature`,
 * `pdf_template`, `published_at`, `is_active`.
 */
class FormTemplate extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal, HasFavorites, \App\Traits\Lockable;

    protected string $auditModule = 'form_templates';


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

    /** El país al que pertenece el documento (columna NOT NULL). */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
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
     * scopeFilter — mismo patrón que Customer, sobre la tabla form_templates.
     * Soporta name (multi-tag accent-insensitive), code (substring),
     * is_active (bool), rangos de fecha/id, filtros avanzados y favoritos.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'form_templates';

        $query->when($request->filled('name'), function ($q) use ($request, $isPgsql, $tbl) {
            $names = is_array($request->name) ? $request->name : [$request->name];
            $names = array_filter($names, fn ($n) => $n !== '');
            if (empty($names)) return;
            $q->where(function ($qq) use ($names, $isPgsql, $tbl) {
                foreach ($names as $name) {
                    $needle = LikeQuery::contains((string) $name);
                    if ($isPgsql) {
                        $qq->orWhereRaw("unaccent(lower({$tbl}.name)) LIKE unaccent(lower(?))", [$needle]);
                    } else {
                        $qq->orWhereRaw("{$tbl}.name LIKE ? ESCAPE '\\'", [$needle]);
                    }
                }
            });
        });

        $query->when($request->filled('code'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "{$tbl}.code LIKE ?" : "{$tbl}.code LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->code)]);
        });

        $query->when($request->filled('is_active'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.is_active", filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        });

        // Publicado / borrador. Es la pregunta que se hace de verdad en este
        // listado —«cuáles puede usar ya un plan»— y no se podía filtrar.
        $query->when($request->filled('status'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.status", (string) $request->status);
        });

        $query->when($request->filled('kind'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.kind", (string) $request->kind);
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
        if ($sort === 'tenant' && in_array($direction, ['asc', 'desc'])) {
            // Orden por workspace: nombre vía left join (nulls = global).
            $query->leftJoin('tenants', "{$tbl}.tenant_id", '=', 'tenants.id')
                  ->orderBy('tenants.name', $direction);
        } elseif (in_array($sort, ['id', 'name', 'code', 'is_active', 'status', 'kind', 'version', 'created_at', 'updated_at']) && in_array($direction, ['asc', 'desc'])) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}>
     */
    public static function filterSchema(): array
    {
        return [
            ['key' => 'name',       'label' => __('form_templates.name'),     'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'code',       'label' => __('form_templates.code'),     'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'status',     'label' => __('form_templates.status'),   'type' => 'enum',    'operators' => ['=', '!='], 'options' => [
                ['value' => 'draft',     'label' => __('form_templates.status_draft')],
                ['value' => 'published', 'label' => __('form_templates.status_published')],
                ['value' => 'archived',  'label' => __('form_templates.status_archived')],
            ]],
            ['key' => 'kind',       'label' => __('form_templates.kind'),     'type' => 'enum',    'operators' => ['=', '!='], 'options' => [
                ['value' => self::STRUCTURED,  'label' => __('form_templates.kind_structured')],
                ['value' => self::UPLOAD_ONLY, 'label' => __('form_templates.kind_upload_only')],
                ['value' => self::HYBRID,      'label' => __('form_templates.kind_hybrid')],
            ]],
            ['key' => 'is_active',  'label' => __('form_templates.is_active'), 'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    // `name` es el nombre de verdad del formato — «AST (Análisis de Seguridad en
    // el Trabajo)»—, no el código. Faltaba, y `scopeFilter()` ya lo daba por
    // existente: filtrar por nombre reventaba con «column name does not exist».
    protected $fillable = ['slug', 'country_id', 'code', 'name', 'kind', 'status', 'version',
                           'requires_signature', 'pdf_template', 'published_at', 'is_active',
                           'tenant_id', 'created_by', 'deleted_by', 'deleted_description'];
    protected $casts = ['requires_signature' => 'boolean', 'is_active' => 'boolean',
                        'published_at' => 'datetime'];

    /** structured: campos propios · upload_only: solo se fotografia el papel · hybrid: ambos. */
    public const STRUCTURED  = 'structured';
    public const UPLOAD_ONLY = 'upload_only';
    public const HYBRID      = 'hybrid';

    /** Los tres, para validar el formulario y pintar el selector. */
    public const KINDS = [self::STRUCTURED, self::UPLOAD_ONLY, self::HYBRID];

    public function sections() { return $this->hasMany(FormSection::class)->orderBy('position'); }
    public function fields() { return $this->hasManyThrough(FormField::class, FormSection::class); }
    public function submissions() { return $this->hasMany(FormSubmission::class); }
    public function workTypes() { return $this->belongsToMany(WorkType::class, 'work_type_form_templates'); }

    public function scopePublished($q) { return $q->where('status', 'published'); }

}
