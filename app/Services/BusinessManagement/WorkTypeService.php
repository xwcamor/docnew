<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\WorkTypes\BulkWorkTypesActionJob;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\FormTemplate;
use App\Models\WorkType;
use App\Support\LikeQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WorkTypeService — el tipo de trabajo y, sobre todo, los formatos que exige.
 *
 * El CRUD es lo de menos: un tipo de trabajo son tres campos. Lo que hay aquí
 * y no en el modelo es la gestión del pivote `work_type_form_templates`, que es
 * donde se decide qué papeles obliga cada clase de maniobra.
 *
 * Dos cosas que conviene tener juntas y explicadas:
 *
 *  - `matrizDeFormatos()` devuelve TODOS los formatos candidatos del país, no
 *    sólo los ya exigidos. Una pantalla que enseña únicamente lo que ya está
 *    marcado no deja añadir nada sin buscarlo a ciegas.
 *  - `planesAbiertos()` cuenta a quién alcanza el cambio. El pivote se lee en
 *    vivo, así que tocarlo cambia lo que hoy se le pide a un plan que está en
 *    obra; los cerrados no se tocan porque su documentación ya está firmada.
 */
class WorkTypeService
{
    // ── Consulta ─────────────────────────────────────────────────────────────

    public static function filter(Builder $query, Request $request): Builder
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'work_types';

        // El buscador general va contra el código: es lo único textual que
        // tiene un tipo de trabajo, y es como se le llama en obra.
        $query->when($request->filled('name'), function ($q) use ($request, $isPgsql, $tbl) {
            $terminos = is_array($request->name) ? $request->name : [$request->name];
            $terminos = array_filter($terminos, fn ($n) => $n !== '');
            if (empty($terminos)) {
                return;
            }
            $q->where(function ($qq) use ($terminos, $isPgsql, $tbl) {
                foreach ($terminos as $termino) {
                    $needle = LikeQuery::contains((string) $termino);
                    if ($isPgsql) {
                        $qq->orWhereRaw("unaccent(lower({$tbl}.code)) LIKE unaccent(lower(?))", [$needle]);
                    } else {
                        $qq->orWhereRaw("{$tbl}.code LIKE ? ESCAPE '\\'", [$needle]);
                    }
                }
            });
        });

        $query->when($request->filled('country_id'), fn ($q) => $q->where("{$tbl}.country_id", (int) $request->country_id));
        $query->when($request->filled('is_active'),  fn ($q) => $q->where("{$tbl}.is_active", filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)));

        // «Sin formatos» es el filtro que de verdad importa: un tipo que no
        // exige ningún papel deja salir el trabajo sin documentación.
        if ($request->filled('has_forms')) {
            filter_var($request->has_forms, FILTER_VALIDATE_BOOLEAN)
                ? $query->has('formTemplates')
                : $query->doesntHave('formTemplates');
        }

        $query->when($request->filled('form_template_id'), fn ($q) => $q->whereHas(
            'formTemplates',
            fn ($w) => $w->where('form_templates.id', (int) $request->form_template_id),
        ));

        $query->when($request->filled('created_from'), fn ($q) => $q->where("{$tbl}.created_at", '>=', $request->created_from . ' 00:00:00'));
        $query->when($request->filled('created_to'),   fn ($q) => $q->where("{$tbl}.created_at", '<=', $request->created_to . ' 23:59:59'));

        $advanced = $request->input('advanced_where');
        if (is_string($advanced)) {
            $advanced = json_decode($advanced, true) ?: null;
        }
        if (is_array($advanced) && ! empty($advanced)) {
            \App\Services\Automations\Support\FilterApplier::apply($query, ['where' => $advanced], static::filterSchema());
        }

        // Por defecto se ordena por código: la lista se lee como un catálogo, y
        // un catálogo se busca por su nombre, no por su fecha de alta.
        $sort      = $request->get('sort', 'code');
        $direction = $request->get('direction', 'asc');
        if ($sort === 'tenant' && in_array($direction, ['asc', 'desc'], true)) {
            $query->leftJoin('tenants', "{$tbl}.tenant_id", '=', 'tenants.id')->orderBy('tenants.name', $direction);
        } elseif (in_array($sort, ['id', 'code', 'country_id', 'is_active', 'created_at', 'updated_at'], true)
            && in_array($direction, ['asc', 'desc'], true)) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    public static function filterSchema(): array
    {
        return [
            ['key' => 'code',       'label' => __('work_types.code'),      'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',  'label' => __('work_types.is_active'), 'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'),    'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    // ── Los formatos que exige el tipo ───────────────────────────────────────

    /**
     * La matriz de documentos del tipo: todos los candidatos, con su marca.
     *
     * Se ofrecen los documentos activos del mismo país —uno de otro país no lo
     * puede exigir este tipo— y se marca cuáles están exigidos y con qué
     * carácter. Los borradores se listan igual, pero avisados: un documento sin
     * publicar no se puede llenar, así que exigirlo hoy sería mandar al usuario
     * de campo a una pantalla que revienta al abrirla.
     *
     * Y se añade SIEMPRE lo que el tipo ya exige, aunque hoy no sea candidato
     * —lo desactivaron en Documentos, o el tipo cambió de país—. Si no
     * apareciera, la pantalla no lo enseñaría, el siguiente guardado mandaría la
     * lista sin él y el `sync()` lo quitaría del tipo sin que nadie lo hubiera
     * decidido: un documento obligatorio que deja de pedirse en silencio.
     *
     * @return array<int, array{
     *     id:int, code:string, name:string|null, status:string, is_published:bool,
     *     is_selected:bool, is_required:bool, is_available:bool
     * }>
     */
    public function matrizDeFormatos(WorkType $tipo): array
    {
        $marcados = $tipo->formTemplates()
            ->get(['form_templates.id'])
            ->mapWithKeys(fn ($f) => [$f->id => (bool) $f->pivot->is_required]);

        $yaExigidos = $marcados->keys()->all();

        return FormTemplate::query()
            ->where(function ($q) use ($tipo, $yaExigidos) {
                $q->where(fn ($candidato) => $candidato
                    ->where('country_id', $tipo->country_id)
                    ->where('is_active', true));

                if ($yaExigidos !== []) {
                    $q->orWhereIn('id', $yaExigidos);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'status', 'is_active', 'country_id'])
            ->map(fn ($f) => [
                'id'           => $f->id,
                'code'         => $f->code,
                'name'         => $f->name,
                'status'       => $f->status,
                'is_published' => $f->status === 'published',
                'is_selected'  => $marcados->has($f->id),
                'is_required'  => (bool) $marcados->get($f->id, false),
                // Sigue exigido pero ya no se puede elegir: la pantalla lo marca
                // y sólo deja quitarlo.
                'is_available' => (bool) $f->is_active && (int) $f->country_id === (int) $tipo->country_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Cuántos planes ABIERTOS de este tipo alcanza un cambio en los formatos.
     *
     * Los cerrados no entran: son de sólo lectura y su documentación ya está
     * firmada. Pedirle a un plan cerrado un formato que se añadió hoy sería
     * inventar un incumplimiento que nunca existió.
     */
    public function planesAbiertos(WorkType $tipo): int
    {
        return $tipo->workPlans()->where('is_closed', false)->count();
    }

    /**
     * Deja el pivote exactamente como dice el formulario.
     *
     * Devuelve qué cambió —no cuántas filas se escribieron— porque lo que hay
     * que contarle al usuario es «esto es lo que acabas de mover», y sin el
     * detalle el aviso de los planes afectados no significa nada.
     *
     * @param  array<int, array{id:int, is_required:bool}>  $formatos
     * @return array{added:int, removed:int, changed:int, touched:bool}
     */
    public function sincronizarFormatos(WorkType $tipo, array $formatos): array
    {
        $deseado = [];
        foreach ($formatos as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id > 0) {
                $deseado[$id] = ['is_required' => (bool) ($f['is_required'] ?? false)];
            }
        }

        $antes = $tipo->formTemplates()->get(['form_templates.id'])
            ->mapWithKeys(fn ($f) => [$f->id => (bool) $f->pivot->is_required])
            ->all();

        $resultado = $tipo->formTemplates()->sync($deseado);

        $cambiados = 0;
        foreach ($resultado['updated'] as $id) {
            if (($antes[$id] ?? null) !== $deseado[$id]['is_required']) {
                $cambiados++;
            }
        }

        $anadidos = count($resultado['attached']);
        $quitados = count($resultado['detached']);
        $tocado   = ($anadidos + $quitados + $cambiados) > 0;

        if ($tocado) {
            $this->auditarFormatos($tipo, $antes, $deseado);
        }

        return [
            'added'   => $anadidos,
            'removed' => $quitados,
            'changed' => $cambiados,
            'touched' => $tocado,
        ];
    }

    /**
     * Deja constancia de qué papeles pasó a exigir el tipo.
     *
     * `sync()` sobre el pivote no dispara eventos de modelo, así que el cambio
     * más consecuente del módulo —el que alcanza en vivo a todos los planes
     * abiertos— no salía en el historial de la ficha: se veía quién tocó el
     * código y no quién quitó el AST.
     *
     * Se guarda como un `updated` normal, con la lista antes y después en texto,
     * para que el historial compartido lo pinte como cualquier otra edición.
     *
     * @param  array<int, bool>  $antes
     * @param  array<int, array{is_required: bool}>  $despues
     */
    protected function auditarFormatos(WorkType $tipo, array $antes, array $despues): void
    {
        $codigos = FormTemplate::withTrashed()
            ->whereIn('id', array_unique([...array_keys($antes), ...array_keys($despues)]))
            ->pluck('code', 'id');

        $listar = function (array $estados) use ($codigos) {
            $partes = [];
            foreach ($estados as $id => $obligatorio) {
                $obligatorio = is_array($obligatorio) ? ($obligatorio['is_required'] ?? false) : $obligatorio;
                $partes[] = ($codigos[$id] ?? "#{$id}")
                    . ' (' . ($obligatorio ? __('work_types.required') : __('work_types.optional')) . ')';
            }
            sort($partes);

            return $partes === [] ? '—' : implode(', ', $partes);
        };

        AuditLog::create([
            'user_id'        => auth()->id(),
            'auditable_type' => WorkType::class,
            'auditable_id'   => $tipo->id,
            'event'          => 'updated',
            'old_values'     => ['documents' => $listar($antes)],
            'new_values'     => ['documents' => $listar($despues)],
            'url'            => request()?->fullUrl(),
            'ip_address'     => request()?->ip(),
            'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
            'module'         => 'work_types',
            'created_at'     => now(),
        ]);
    }

    // ── Alta / baja / modificación ───────────────────────────────────────────

    /**
     * @param  array{code:string, country_id:int, is_active?:bool, form_templates?:array}  $data
     */
    public function create(array $data): WorkType
    {
        $formatos = $data['form_templates'] ?? [];
        unset($data['form_templates']);

        return DB::transaction(function () use ($data, $formatos) {
            $tipo = new WorkType($data);
            // El slug lo pone quien crea la fila: el modelo no lo genera solo.
            $tipo->slug       = $this->slug();
            $tipo->created_by = auth()->id();
            $tipo->tenant_id ??= auth()->user()?->tenant_id;
            $tipo->save();

            if ($formatos !== []) {
                $this->sincronizarFormatos($tipo, $formatos);
            }

            return $tipo;
        });
    }

    /** Slug opaco de 22 caracteres, unico en su tabla: la convencion del proyecto. */
    private function slug(): string
    {
        do {
            $slug = \Illuminate\Support\Str::random(22);
        } while (WorkType::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * @param  array{code?:string, country_id?:int, is_active?:bool, form_templates?:array}  $data
     * @return array{added:int, removed:int, changed:int, touched:bool}
     */
    public function update(WorkType $tipo, array $data): array
    {
        $formatos = $data['form_templates'] ?? null;
        unset($data['form_templates']);

        return DB::transaction(function () use ($tipo, $data, $formatos) {
            if ($data !== []) {
                $tipo->update($data);
            }

            // `null` es «el formulario no traía la matriz» y no «vacíala»: la
            // ficha y el formulario largo mandan cosas distintas.
            return $formatos === null
                ? ['added' => 0, 'removed' => 0, 'changed' => 0, 'touched' => false]
                : $this->sincronizarFormatos($tipo, $formatos);
        });
    }

    public function delete(WorkType $tipo, string $motivo): void
    {
        $tipo->deleted_description = $motivo;
        $tipo->deleted_by          = auth()->id();
        $tipo->is_active           = false;
        $tipo->saveQuietly();
        $tipo->delete();
    }

    public function restore(WorkType $tipo): WorkType
    {
        $tipo->deleted_description = null;
        $tipo->deleted_by          = null;
        $tipo->restore();

        return $tipo;
    }

    public function forceDelete(WorkType $tipo, string $motivo): void
    {
        DB::transaction(function () use ($tipo, $motivo) {
            $bloqueado = WorkType::onlyTrashed()->where('id', $tipo->id)->lockForUpdate()->first();
            if (! $bloqueado) {
                throw new \RuntimeException("WorkType {$tipo->id} ya no esta disponible para borrado definitivo");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => WorkType::class,
                'auditable_id'   => $bloqueado->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'code' => $bloqueado->code,
                    'slug' => $bloqueado->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $motivo,
                'module'         => 'work_types',
                'created_at'     => now(),
            ]);

            $bloqueado->forceDelete();
        });
    }

    // ── Masivas ──────────────────────────────────────────────────────────────

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkWorkTypesActionJob::asyncThreshold();
    }

    /** @return array{queued: bool, count: int, deleted: int[]} */
    public function bulkDelete(array $ids, string $motivo): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkWorkTypesActionJob::dispatch((int) auth()->id(), 'delete', $ids, ['reason' => $motivo]);

            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $motivo) {
            $borrados = [];
            foreach (WorkType::whereIn('id', $ids)->get() as $tipo) {
                $this->delete($tipo, $motivo);
                $borrados[] = $tipo->id;
            }

            return ['queued' => false, 'count' => count($borrados), 'deleted' => $borrados];
        });
    }

    /** @return array{queued: bool, count: int, changed?: int} */
    public function bulkSetActive(array $ids, bool $activo): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkWorkTypesActionJob::dispatch((int) auth()->id(), 'set_active', $ids, ['is_active' => $activo]);

            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $activo, $count) {
            $cambiados = 0;
            foreach (WorkType::whereIn('id', $ids)->get() as $tipo) {
                if ((bool) $tipo->is_active === $activo) {
                    continue;
                }
                $tipo->update(['is_active' => $activo]);
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
            BulkWorkTypesActionJob::dispatch((int) auth()->id(), 'restore', $ids, []);

            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $tipos = WorkType::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($tipos as $tipo) {
                $this->restore($tipo);
            }

            return ['queued' => false, 'count' => $count, 'restored' => $tipos->count()];
        });
    }

    /**
     * @param  int[]  $claimIds
     * @return int[]
     */
    public function undoLastDelete(array $claimIds, int $userId): array
    {
        $restaurados = [];
        foreach (WorkType::onlyTrashed()->whereIn('id', $claimIds)->where('deleted_by', $userId)->get() as $tipo) {
            $this->restore($tipo);
            $restaurados[] = $tipo->id;
        }

        return $restaurados;
    }

    // ── Opciones para los selectores del formulario ──────────────────────────

    /** Países y formatos: lo que hace falta para armar un tipo de trabajo. */
    public function opcionesDelFormulario(): array
    {
        return [
            'countries' => Country::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'iso_code'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'iso' => $c->iso_code])->all(),

            // Los formatos van con su país: el selector se acota al país
            // elegido, porque un formato de otro país no se puede exigir aquí.
            'form_templates' => FormTemplate::query()->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'status', 'country_id'])
                ->map(fn ($f) => [
                    'value'        => $f->id,
                    'label'        => $f->name ?: $f->code,
                    'code'         => $f->code,
                    'status'       => $f->status,
                    'is_published' => $f->status === 'published',
                    'country_id'   => $f->country_id,
                ])->all(),
        ];
    }
}
