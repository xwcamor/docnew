<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\ApproverRoles\BulkApproverRolesActionJob;
use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\AuditLog;
use App\Support\LikeQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ApproverRoleService — el catalogo de quien puede firmar un plan.
 *
 * El filtrado y el esquema de filtros viven aqui y no en el modelo a proposito:
 * `ApproverRole` es un modelo de dominio compartido con el motor de aprobaciones
 * y con la migracion de datos heredados, y no queremos que crezca con las
 * necesidades de una pantalla.
 *
 * Lo que este servicio protege, y que ninguna otra capa puede garantizar:
 *
 *  - un rol **en uso por alguna regla** no se borra. Borrarlo dejaria reglas
 *    apuntando a un codigo que ya no existe, y con eso planes que exigen la
 *    firma de un rol fantasma. Se avisa y se ofrece desactivarlo, que es lo
 *    que el usuario casi siempre queria: que deje de ofrecerse, sin romper lo
 *    que ya estaba configurado;
 *  - los dos que trae el sistema (`supervisor`, `hse_supervisor`)
 *    tampoco. El motor de migracion y las reglas sembradas los nombran por su
 *    codigo: si desaparecen, lo que se rompe no es esta pantalla.
 */
class ApproverRoleService
{
    /** Los que trae el sistema: viven en el modelo como constantes por lo mismo. */
    public const DEL_SISTEMA = [
        ApproverRole::SUPERVISOR,
        ApproverRole::HSE_SUPERVISOR,
    ];

    // ── Consulta ─────────────────────────────────────────────────────────────

    /**
     * Filtros del listado. Recibe el Request tal cual para que exports y jobs
     * puedan reusarlo con un Request armado a mano.
     */
    public static function filter(Builder $query, Request $request): Builder
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'approver_roles';

        // El buscador principal va contra los dos nombres y el codigo a la vez:
        // quien busca "HSE" no sabe (ni tiene por que) en que columna esta.
        $query->when($request->filled('name'), function ($q) use ($request, $isPgsql, $tbl) {
            $terminos = is_array($request->name) ? $request->name : [$request->name];
            $terminos = array_filter($terminos, fn ($n) => $n !== '');
            if (empty($terminos)) {
                return;
            }
            $q->where(function ($qq) use ($terminos, $isPgsql, $tbl) {
                foreach ($terminos as $termino) {
                    $needle = LikeQuery::contains((string) $termino);
                    foreach (['name_es', 'name_en', 'code'] as $col) {
                        if ($isPgsql) {
                            $qq->orWhereRaw("unaccent(lower({$tbl}.{$col})) LIKE unaccent(lower(?))", [$needle]);
                        } else {
                            $qq->orWhereRaw("{$tbl}.{$col} LIKE ? ESCAPE '\\'", [$needle]);
                        }
                    }
                }
            });
        });

        $query->when($request->filled('code'), fn ($q) => $q->whereRaw(
            $isPgsql ? "{$tbl}.code LIKE ?" : "{$tbl}.code LIKE ? ESCAPE '\\'",
            [LikeQuery::contains((string) $request->code)],
        ));

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

        // Por defecto manda `sort_order`: el orden del catalogo es el orden en
        // que se ofrecen los roles al armar un flujo, no un capricho estetico.
        $sort      = $request->get('sort', 'sort_order');
        $direction = $request->get('direction', 'asc');
        if ($sort === 'tenant' && in_array($direction, ['asc', 'desc'], true)) {
            $query->leftJoin('tenants', "{$tbl}.tenant_id", '=', 'tenants.id')
                  ->orderBy('tenants.name', $direction);
        } elseif (in_array($sort, ['id', 'code', 'name_es', 'name_en', 'sort_order', 'is_active', 'created_at', 'updated_at'], true)
            && in_array($direction, ['asc', 'desc'], true)) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    /** Campos del constructor de filtros avanzados. */
    public static function filterSchema(): array
    {
        return [
            ['key' => 'code',       'label' => __('approver_roles.code'),       'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'name_es',    'label' => __('approver_roles.name_es'),    'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'name_en',    'label' => __('approver_roles.name_en'),    'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'sort_order', 'label' => __('approver_roles.sort_order'), 'type' => 'number',  'operators' => ['=', '!=', '>', '<', '>=', '<=']],
            ['key' => 'is_active',  'label' => __('approver_roles.is_active'),  'type' => 'boolean', 'operators' => ['=']],
        ];
    }

    // ── Reglas del dominio ───────────────────────────────────────────────────

    /** Los tres del sistema son globales: se reconocen por codigo Y sin workspace. */
    public function esDelSistema(ApproverRole $rol): bool
    {
        return $rol->tenant_id === null && in_array($rol->code, self::DEL_SISTEMA, true);
    }

    /** Cuantas reglas de flujo firman con este rol hoy. */
    public function reglasQueLoUsan(ApproverRole $rol): int
    {
        return ApprovalRule::where('approver_role', $rol->code)->count();
    }

    /**
     * Por que NO se puede borrar este rol, o null si si se puede.
     * Devuelve el mensaje ya traducido: el controller lo pasa a un flash y el
     * usuario lee el motivo en vez de ver un boton que no hace nada.
     */
    public function motivoParaNoBorrar(ApproverRole $rol): ?string
    {
        if ($this->esDelSistema($rol)) {
            return __('approver_roles.system_cannot_delete', ['code' => $rol->code]);
        }

        $enUso = $this->reglasQueLoUsan($rol);
        if ($enUso > 0) {
            return __('approver_roles.in_use_cannot_delete', ['count' => $enUso]);
        }

        return null;
    }

    // ── Alta / baja / modificacion ───────────────────────────────────────────

    public function create(array $data): ApproverRole
    {
        $rol = new ApproverRole($this->sinPedirTraduccion($data));
        // El slug lo pone quien crea la fila: el modelo no lo genera solo
        // porque tambien lo escriben la migracion y el motor de datos heredados.
        $rol->slug       = $this->slug();
        $rol->created_by = auth()->id();
        $rol->save();

        return $rol;
    }

    /**
     * El nombre en ingles deja de pedirse, pero la columna es NOT NULL.
     *
     * Se guarda vacia, no con una copia del castellano: una copia seria decir
     * que ese ES el nombre en ingles, y no lo es — nadie lo escribio. Vacia
     * significa «no se tradujo», que es la verdad, y `ApproverRole::label` cae
     * al otro idioma cuando lo ve.
     *
     * No se hace nullable la columna a proposito: en SQLite cambiar el tipo
     * reconstruye la tabla, y `approver_roles` lleva un indice unico creado con
     * SQL crudo —`lower(code), coalesce(tenant_id,0)`— que la reconstruccion se
     * llevaria por delante sin avisar.
     */
    private function sinPedirTraduccion(array $data): array
    {
        $data['name_en'] = $data['name_en'] ?? '';

        return $data;
    }

    /** Slug opaco de 22 caracteres, unico en su tabla: la convencion del proyecto. */
    private function slug(): string
    {
        do {
            $slug = \Illuminate\Support\Str::random(22);
        } while (ApproverRole::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    public function update(ApproverRole $rol, array $data): ApproverRole
    {
        // El codigo de un rol del sistema no se cambia: las reglas sembradas y
        // el motor de migracion lo nombran asi. Los nombres si, que son etiqueta.
        if ($this->esDelSistema($rol)) {
            unset($data['code']);
        }

        // Si la peticion no trae el nombre en ingles, el que hubiera se queda:
        // los roles de antes lo tienen escrito y no se borra por editar el
        // nombre en castellano.
        if (! array_key_exists('name_en', $data) || $data['name_en'] === null) {
            unset($data['name_en']);
        }

        $rol->update($data);

        return $rol;
    }

    /**
     * Borrado logico con motivo. Antes comprueba que se pueda: es la unica
     * capa que ve a la vez el catalogo y las reglas que lo consumen.
     *
     * @throws \DomainException si el rol esta en uso o es del sistema
     */
    public function delete(ApproverRole $rol, string $motivo): void
    {
        if ($razon = $this->motivoParaNoBorrar($rol)) {
            throw new \DomainException($razon);
        }

        $rol->deleted_description = $motivo;
        $rol->deleted_by          = auth()->id();
        $rol->is_active           = false;
        $rol->saveQuietly();
        $rol->delete();
    }

    /** La salida que se ofrece cuando el borrado se rechaza: dejar de ofrecerlo. */
    public function deactivate(ApproverRole $rol): ApproverRole
    {
        $rol->update(['is_active' => false]);

        return $rol;
    }

    public function restore(ApproverRole $rol): ApproverRole
    {
        $rol->deleted_description = null;
        $rol->deleted_by          = null;
        $rol->restore();

        return $rol;
    }

    /** Borrado definitivo. El audit va ANTES para que sobreviva a la fila. */
    public function forceDelete(ApproverRole $rol, string $motivo): void
    {
        DB::transaction(function () use ($rol, $motivo) {
            $bloqueado = ApproverRole::onlyTrashed()->where('id', $rol->id)->lockForUpdate()->first();
            if (! $bloqueado) {
                throw new \RuntimeException("ApproverRole {$rol->id} ya no esta disponible para borrado definitivo");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => ApproverRole::class,
                'auditable_id'   => $bloqueado->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'code'    => $bloqueado->code,
                    'name_es' => $bloqueado->name_es,
                    'slug'    => $bloqueado->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $motivo,
                'module'         => 'approver_roles',
                'created_at'     => now(),
            ]);

            $bloqueado->forceDelete();
        });
    }

    // ── Masivas ──────────────────────────────────────────────────────────────

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkApproverRolesActionJob::asyncThreshold();
    }

    /**
     * Borrado masivo. Los que no se pueden borrar se saltan y se cuentan: mas
     * util que abortar el lote entero por uno protegido.
     *
     * @return array{queued: bool, count: int, deleted: int[], skipped: int}
     */
    public function bulkDelete(array $ids, string $motivo): array
    {
        $roles = ApproverRole::whereIn('id', $ids)->get();

        $borrables = $roles->filter(fn ($r) => $this->motivoParaNoBorrar($r) === null);
        $saltados  = $roles->count() - $borrables->count();

        if ($this->shouldDispatchAsync($borrables->count())) {
            BulkApproverRolesActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $borrables->pluck('id')->all(),
                ['reason' => $motivo],
            );

            return ['queued' => true, 'count' => $borrables->count(), 'deleted' => [], 'skipped' => $saltados];
        }

        return DB::transaction(function () use ($borrables, $motivo, $saltados) {
            $borrados = [];
            foreach ($borrables as $rol) {
                $this->delete($rol, $motivo);
                $borrados[] = $rol->id;
            }

            return ['queued' => false, 'count' => count($borrados), 'deleted' => $borrados, 'skipped' => $saltados];
        });
    }

    /** @return array{queued: bool, count: int, changed?: int} */
    public function bulkSetActive(array $ids, bool $activo): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkApproverRolesActionJob::dispatch((int) auth()->id(), 'set_active', $ids, ['is_active' => $activo]);

            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $activo, $count) {
            $cambiados = 0;
            foreach (ApproverRole::whereIn('id', $ids)->get() as $rol) {
                if ((bool) $rol->is_active === $activo) {
                    continue;
                }
                $rol->update(['is_active' => $activo]);
                $cambiados++;
            }

            return ['queued' => false, 'count' => $count, 'changed' => $cambiados];
        });
    }

    /** @return array{queued: bool, count: int, restored?: int} */
    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkApproverRolesActionJob::dispatch((int) auth()->id(), 'restore', $ids, []);

            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $roles = ApproverRole::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($roles as $rol) {
                $this->restore($rol);
            }

            return ['queued' => false, 'count' => $count, 'restored' => $roles->count()];
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
        $roles = ApproverRole::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        foreach ($roles as $rol) {
            $this->restore($rol);
            $restaurados[] = $rol->id;
        }

        return $restaurados;
    }

    /**
     * Edicion en masa del nombre en español y el estado. Las filas sin cambio
     * real se saltan para no ensuciar la bitacora con updates vacios.
     */
    public function editAllUpdate(array $cambios): int
    {
        $tocados = 0;

        DB::transaction(function () use ($cambios, &$tocados) {
            $porId = ApproverRole::whereIn('id', array_column($cambios, 'id'))->get()->keyBy('id');

            foreach ($cambios as $cambio) {
                $rol = $porId[$cambio['id']] ?? null;
                if (! $rol) {
                    continue;
                }

                $parche = array_filter(
                    array_intersect_key($cambio, array_flip(['name_es', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($parche)) {
                    continue;
                }

                $hayCambio = false;
                foreach ($parche as $k => $v) {
                    if ((string) $rol->{$k} !== (string) $v) {
                        $hayCambio = true;
                        break;
                    }
                }
                if (! $hayCambio) {
                    continue;
                }

                $rol->fill($parche)->save();
                $tocados++;
            }
        });

        return $tocados;
    }
}
