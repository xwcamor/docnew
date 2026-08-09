<?php

namespace App\Services\BusinessManagement;

use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Support\LikeQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DocumentTypeService — que documento lleva cada persona: DNI, CE, pasaporte, PTP.
 *
 * El filtrado y las reglas de borrado viven aqui y no en el modelo a proposito:
 * `DocumentType` es un modelo de dominio que tambien usan el alta de personas y
 * la migracion de datos heredados, y no queremos que crezca con las necesidades
 * de una pantalla.
 *
 * Lo que protege este servicio, y que ninguna otra capa puede garantizar: una
 * fila **en uso** no se borra. Borrarla dejaria sin catalogo a las personas que
 * ya llevan ese documento. Se avisa y se ofrece desactivarla, que es lo que el
 * usuario casi siempre queria: que deje de ofrecerse, sin tocar lo que ya
 * estaba registrado.
 */
class DocumentTypeService
{
    // ── Consulta ─────────────────────────────────────────────────────────────

    /**
     * Filtros del listado. Recibe el Request tal cual para que las vistas
     * guardadas puedan reusarlo con un Request armado a mano.
     */
    public static function filter(Builder $query, Request $request): Builder
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'document_types';

        // El buscador principal va contra la sigla Y el nombre largo: quien
        // escribe en la barra no sabe (ni tiene por que) en que columna esta lo
        // que busca, y con «extranjeria» espera encontrar el CE.
        $query->when($request->filled('name'), function ($q) use ($request, $isPgsql, $tbl) {
            $terminos = is_array($request->name) ? $request->name : [$request->name];
            $terminos = array_filter($terminos, fn ($n) => $n !== '');
            if (empty($terminos)) {
                return;
            }
            $q->where(function ($qq) use ($terminos, $isPgsql, $tbl) {
                foreach ($terminos as $termino) {
                    $needle = LikeQuery::contains((string) $termino);
                    foreach (['code', 'name'] as $columna) {
                        if ($isPgsql) {
                            $qq->orWhereRaw("unaccent(lower({$tbl}.{$columna})) LIKE unaccent(lower(?))", [$needle]);
                        } else {
                            $qq->orWhereRaw("{$tbl}.{$columna} LIKE ? ESCAPE '\\'", [$needle]);
                        }
                    }
                }
            });
        });

        $query->when($request->filled('is_active'), fn ($q) => $q->where(
            "{$tbl}.is_active",
            filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN),
        ));

        $query->when($request->filled('created_from'), fn ($q) => $q->where("{$tbl}.created_at", '>=', $request->created_from . ' 00:00:00'));
        $query->when($request->filled('created_to'),   fn ($q) => $q->where("{$tbl}.created_at", '<=', $request->created_to . ' 23:59:59'));

        $advanced = $request->input('advanced_where');
        if (is_string($advanced)) {
            $advanced = json_decode($advanced, true) ?: null;
        }
        if (is_array($advanced) && ! empty($advanced)) {
            \App\Services\Automations\Support\FilterApplier::apply(
                $query,
                ['where' => $advanced],
                static::filterSchema(),
            );
        }

        $sort      = $request->get('sort', 'code');
        $direction = $request->get('direction', 'asc');
        if ($sort === 'tenant' && in_array($direction, ['asc', 'desc'], true)) {
            $query->leftJoin('tenants', "{$tbl}.tenant_id", '=', 'tenants.id')
                  ->orderBy('tenants.name', $direction);
        } elseif (in_array($sort, ['id', 'code', 'name', 'min_length', 'max_length', 'is_active', 'created_at', 'updated_at'], true)
            && in_array($direction, ['asc', 'desc'], true)) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    /** Campos del constructor de filtros avanzados. */
    public static function filterSchema(): array
    {
        return [
            ['key' => 'code',       'label' => __('document_types.code'),       'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'name',       'label' => __('document_types.name'),       'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'min_length', 'label' => __('document_types.min_length'), 'type' => 'number',  'operators' => ['=', '!=', '>', '<', '>=', '<=']],
            ['key' => 'max_length', 'label' => __('document_types.max_length'), 'type' => 'number',  'operators' => ['=', '!=', '>', '<', '>=', '<=']],
            ['key' => 'is_active',  'label' => __('document_types.is_active'),  'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'),         'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    // ── Reglas del dominio ───────────────────────────────────────────────────

    /**
     * Cuantas personas llevan este documento.
     *
     * Se cuenta por CODIGO y pais, no por clave ajena: `people.doc_type` guarda
     * el texto («DNI»), no un `document_type_id`. Es a proposito — la ficha de
     * alguien firmada hace tres años tiene que seguir diciendo «DNI» aunque
     * despues se renombre o se borre la fila del catalogo.
     */
    public function usos(DocumentType $m): int
    {
        return \App\Models\Person::where('country_id', $m->country_id)
            ->where('doc_type', $m->code)->count();
    }

    /**
     * Rellena `usage_count` de una pagina entera con 1 consulta(s)
     * agrupada(s), no una por fila: con 200 filas por pagina la diferencia
     * entre esto y un N+1 se nota en obra, no en un benchmark.
     */
    public function contarUsos(EloquentCollection $filas): void
    {
        $ids = $filas->pluck('id')->all();
        if (empty($ids)) {
            return;
        }

        // Por (pais, codigo), que es como se relacionan: una consulta para
        // toda la pagina en vez de una por fila.
        $porPaisYCodigo = \App\Models\Person::query()
            ->whereIn('country_id', $filas->pluck('country_id')->unique()->all())
            ->whereIn('doc_type', $filas->pluck('code')->unique()->all())
            ->selectRaw('country_id, doc_type, count(*) as total')
            ->groupBy('country_id', 'doc_type')
            ->get()
            ->keyBy(fn ($r) => $r->country_id . '|' . $r->doc_type);

        $filas->transform(function ($fila) use ($porPaisYCodigo) {
            $fila->usage_count = (int) ($porPaisYCodigo[$fila->country_id . '|' . $fila->code]->total ?? 0);

            return $fila;
        });
    }

    /** Los registros que la usan, para enseñarlos en la ficha (maximo 20). */
    public function usados(DocumentType $m): array
    {
        return \App\Models\Person::where('country_id', $m->country_id)
            ->where('doc_type', $m->code)
            ->orderBy('lastname')->limit(20)->get(['slug', 'name', 'lastname', 'is_active'])
            ->map(fn ($p) => ['slug' => $p->slug, 'label' => $p->name . ' ' . $p->lastname, 'is_active' => (bool) $p->is_active])
            ->all();
    }

    /**
     * Por que NO se puede borrar esta fila, o null si si se puede.
     * Devuelve el mensaje ya traducido: la pantalla lo enseña antes de pedir el
     * motivo, en vez de dejar un boton que falla al pulsarlo.
     */
    public function motivoParaNoBorrar(DocumentType $m): ?string
    {
        $enUso = $this->usos($m);

        return $enUso > 0
            ? __('document_types.in_use_cannot_delete', ['count' => $enUso])
            : null;
    }

    // ── Alta / baja / modificacion ───────────────────────────────────────────

    public function create(array $data): DocumentType
    {
        $fila = new DocumentType($data);
        // El slug lo pone quien crea la fila: el modelo no lo genera solo
        // porque tambien lo escriben la migracion y el motor de datos heredados.
        $fila->slug       = $this->slug();
        $fila->created_by = auth()->id();
        $fila->save();

        return $fila;
    }

    /** Slug opaco de 22 caracteres, unico en su tabla: la convencion del proyecto. */
    private function slug(): string
    {
        do {
            $slug = \Illuminate\Support\Str::random(22);
        } while (DocumentType::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    public function update(DocumentType $m, array $data): DocumentType
    {
        $m->update($data);

        return $m;
    }

    /**
     * Borrado logico con motivo. Antes comprueba que se pueda.
     *
     * @throws \DomainException si la fila esta en uso
     */
    public function delete(DocumentType $m, string $motivo): void
    {
        if ($razon = $this->motivoParaNoBorrar($m)) {
            throw new \DomainException($razon);
        }

        $m->deleted_description = $motivo;
        $m->deleted_by          = auth()->id();
        $m->is_active           = false;
        $m->saveQuietly();
        $m->delete();
    }

    /** La salida que se ofrece cuando el borrado se rechaza: dejar de ofrecerla. */
    public function deactivate(DocumentType $m): DocumentType
    {
        $m->update(['is_active' => false]);

        return $m;
    }

    public function restore(DocumentType $m): DocumentType
    {
        $m->deleted_description = null;
        $m->deleted_by          = null;
        $m->restore();

        return $m;
    }

    /** Borrado definitivo. El audit va ANTES para que sobreviva a la fila. */
    public function forceDelete(DocumentType $m, string $motivo): void
    {
        DB::transaction(function () use ($m, $motivo) {
            $bloqueado = DocumentType::onlyTrashed()->where('id', $m->id)->lockForUpdate()->first();
            if (! $bloqueado) {
                throw new \RuntimeException("DocumentType {$m->id} ya no esta disponible para borrado definitivo");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => DocumentType::class,
                'auditable_id'   => $bloqueado->id,
                'event'          => 'force_deleted',
                'old_values'     => ['code' => $bloqueado->code, 'slug' => $bloqueado->slug],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $motivo,
                'module'         => 'document_types',
                'created_at'     => now(),
            ]);

            $bloqueado->forceDelete();
        });
    }

    // ── Masivas ──────────────────────────────────────────────────────────────

    /**
     * Borrado masivo. Las filas en uso se saltan y se cuentan: mas util que
     * abortar el lote entero por una protegida.
     *
     * @return array{count: int, deleted: int[], skipped: int}
     */
    public function bulkDelete(array $ids, string $motivo): array
    {
        $filas = DocumentType::whereIn('id', $ids)->get();

        $borrables = $filas->filter(fn ($f) => $this->motivoParaNoBorrar($f) === null);
        $saltados  = $filas->count() - $borrables->count();

        return DB::transaction(function () use ($borrables, $motivo, $saltados) {
            $borrados = [];
            foreach ($borrables as $fila) {
                $this->delete($fila, $motivo);
                $borrados[] = $fila->id;
            }

            return ['count' => count($borrados), 'deleted' => $borrados, 'skipped' => $saltados];
        });
    }

    /** @return int cuantas filas cambiaron de verdad */
    public function bulkSetActive(array $ids, bool $activo): int
    {
        return DB::transaction(function () use ($ids, $activo) {
            $cambiados = 0;
            foreach (DocumentType::whereIn('id', $ids)->get() as $fila) {
                if ((bool) $fila->is_active === $activo) {
                    continue; // sin cambio real no se ensucia la bitacora
                }
                $fila->update(['is_active' => $activo]);
                $cambiados++;
            }

            return $cambiados;
        });
    }

    /** @return int cuantas filas se restauraron */
    public function bulkRestore(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $filas = DocumentType::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($filas as $fila) {
                $this->restore($fila);
            }

            return $filas->count();
        });
    }

    /**
     * Deshacer dentro de la ventana de 60s. Solo restaura filas borradas por
     * el propio usuario, aunque el claim de sesion diga otra cosa.
     *
     * @param  int[]  $claimIds
     * @return int[]
     */
    public function undoLastDelete(array $claimIds, int $userId): array
    {
        $restaurados = [];
        $filas = DocumentType::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        foreach ($filas as $fila) {
            $this->restore($fila);
            $restaurados[] = $fila->id;
        }

        return $restaurados;
    }
}
