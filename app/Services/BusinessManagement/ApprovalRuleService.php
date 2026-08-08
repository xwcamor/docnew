<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\ApprovalRules\BulkApprovalRulesActionJob;
use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Setting;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Support\LikeQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ApprovalRuleService — las reglas que dicen qué firmas exige un plan.
 *
 * El filtrado y la vista previa viven aquí y no en el modelo: `ApprovalRule` es
 * de dominio —lo usa el motor de aprobaciones— y no debe crecer con lo que una
 * pantalla necesita para pintarse.
 *
 * La pieza que de verdad importa es `previewParaTipo()`. Configurar reglas es
 * fácil de hacer mal porque la que se ve en la tabla no siempre es la que se
 * aplica: si un tipo de trabajo tiene reglas propias, las generales del país
 * dejan de contar PARA ESE TIPO. La vista previa resuelve el flujo con
 * `ApprovalRule::paraPlan()`, el mismo método que usa la creación de un plan
 * real, para que lo que se enseña no pueda desviarse de lo que va a pasar.
 */
class ApprovalRuleService
{
    // ── Consulta ─────────────────────────────────────────────────────────────

    public static function filter(Builder $query, Request $request): Builder
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'approval_rules';

        // El buscador general va contra el código del rol que firma: es lo
        // único textual que tiene una regla.
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
                        $qq->orWhereRaw("unaccent(lower({$tbl}.approver_role)) LIKE unaccent(lower(?))", [$needle]);
                    } else {
                        $qq->orWhereRaw("{$tbl}.approver_role LIKE ? ESCAPE '\\'", [$needle]);
                    }
                }
            });
        });

        $query->when($request->filled('country_id'), fn ($q) => $q->where("{$tbl}.country_id", (int) $request->country_id));
        $query->when($request->filled('approver_role'), fn ($q) => $q->where("{$tbl}.approver_role", $request->approver_role));

        // El tipo de trabajo admite un valor especial: «solo las generales»,
        // que son las que no acotan ningún tipo.
        if ($request->filled('work_type_id')) {
            $valor = $request->work_type_id;
            $valor === 'none'
                ? $query->whereNull("{$tbl}.work_type_id")
                : $query->where("{$tbl}.work_type_id", (int) $valor);
        }

        $query->when($request->filled('is_required'), fn ($q) => $q->where("{$tbl}.is_required", filter_var($request->is_required, FILTER_VALIDATE_BOOLEAN)));
        $query->when($request->filled('is_active'),   fn ($q) => $q->where("{$tbl}.is_active",   filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)));

        $query->when($request->filled('created_from'), fn ($q) => $q->where("{$tbl}.created_at", '>=', $request->created_from . ' 00:00:00'));
        $query->when($request->filled('created_to'),   fn ($q) => $q->where("{$tbl}.created_at", '<=', $request->created_to . ' 23:59:59'));

        $advanced = $request->input('advanced_where');
        if (is_string($advanced)) {
            $advanced = json_decode($advanced, true) ?: null;
        }
        if (is_array($advanced) && ! empty($advanced)) {
            \App\Services\Automations\Support\FilterApplier::apply($query, ['where' => $advanced], static::filterSchema());
        }

        // Por defecto se ordena por nivel: una lista de reglas se lee en el
        // orden en que se firman, no por fecha de alta.
        $sort      = $request->get('sort', 'priority_level');
        $direction = $request->get('direction', 'asc');
        if ($sort === 'tenant' && in_array($direction, ['asc', 'desc'], true)) {
            $query->leftJoin('tenants', "{$tbl}.tenant_id", '=', 'tenants.id')->orderBy('tenants.name', $direction);
        } elseif (in_array($sort, ['id', 'country_id', 'work_type_id', 'approver_role', 'priority_level', 'is_required', 'is_active', 'created_at', 'updated_at'], true)
            && in_array($direction, ['asc', 'desc'], true)) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    public static function filterSchema(): array
    {
        return [
            ['key' => 'approver_role',  'label' => __('approval_rules.approver_role'),  'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'priority_level', 'label' => __('approval_rules.priority_level'), 'type' => 'number',  'operators' => ['=', '!=', '>', '<', '>=', '<=']],
            ['key' => 'is_required',    'label' => __('approval_rules.is_required'),    'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'is_active',      'label' => __('approval_rules.is_active'),      'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at',     'label' => __('global.created_at'),             'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    // ── Vista previa del flujo ───────────────────────────────────────────────

    /**
     * Qué firmas quedan para un país, tipo a tipo.
     *
     * Se arma un plan **sin guardar** y se le pregunta a `ApprovalRule::paraPlan()`,
     * que es exactamente lo que hace `WorkPlanSetupService::seedApprovalsFromRules()`
     * al crear un plan de verdad. Duplicar aquí la lógica de resolución sería
     * garantizar que un día la vista previa y la realidad dejen de coincidir.
     *
     * @return array<int, array{
     *     work_type: array{id:int|null, code:string},
     *     source: string,
     *     signatures: array<int, array{level:int, role:string, label:string, required:bool}>
     * }>
     */
    public function previewPorPais(int $countryId, ?int $tenantId): array
    {
        $etiquetas = ApproverRole::opciones();

        $tipos = WorkType::query()
            ->where('country_id', $countryId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code']);

        // La fila «todos los tipos» va primero: es el flujo que hereda
        // cualquier tipo que no tenga reglas propias.
        $filas = [$this->previewParaTipo($countryId, null, $tenantId, $etiquetas, __('approval_rules.all_work_types'))];

        foreach ($tipos as $tipo) {
            $filas[] = $this->previewParaTipo($countryId, $tipo->id, $tenantId, $etiquetas, $tipo->code);
        }

        return $filas;
    }

    /** Una fila de la vista previa: el flujo resultante para un tipo concreto. */
    protected function previewParaTipo(int $countryId, ?int $workTypeId, ?int $tenantId, array $etiquetas, string $codigo): array
    {
        // Plan de mentira, sin guardar: paraPlan() solo mira país, tipo y workspace.
        $plan = new WorkPlan([
            'country_id'   => $countryId,
            'work_type_id' => $workTypeId,
            'tenant_id'    => $tenantId,
        ]);

        $reglas = ApprovalRule::paraPlan($plan);

        // De dónde salen esas firmas: propias del tipo, heredadas del país, o
        // ninguna. Es la mitad de la explicación que el usuario necesita.
        $propias = $workTypeId !== null && $reglas->isNotEmpty() && $reglas->first()->work_type_id === $workTypeId;
        $origen  = $reglas->isEmpty() ? 'none' : ($workTypeId === null ? 'country' : ($propias ? 'own' : 'inherited'));

        return [
            'work_type'  => ['id' => $workTypeId, 'code' => $codigo],
            'source'     => $origen,
            'signatures' => $reglas->map(fn ($r) => [
                'level'    => (int) $r->priority_level,
                'role'     => $r->approver_role,
                'label'    => $etiquetas[$r->approver_role] ?? $r->approver_role,
                'required' => (bool) $r->is_required,
            ])->values()->all(),
        ];
    }

    /** ¿El workspace exige que las firmas vayan en orden, o solo las ordena? */
    public function ordenObligatorio(): bool
    {
        return (bool) (Setting::get('docufiz.sequential_approvals') ?? false);
    }

    // ── Alta / baja / modificación ───────────────────────────────────────────

    public function create(array $data): ApprovalRule
    {
        $regla = new ApprovalRule($data);
        // El slug lo pone quien crea la fila: el modelo no lo genera solo.
        $regla->slug       = $this->slug();
        $regla->created_by = auth()->id();
        $regla->tenant_id ??= auth()->user()?->tenant_id;
        $regla->save();

        return $regla;
    }

    /** Slug opaco de 22 caracteres, unico en su tabla: la convencion del proyecto. */
    private function slug(): string
    {
        do {
            $slug = \Illuminate\Support\Str::random(22);
        } while (ApprovalRule::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    public function update(ApprovalRule $regla, array $data): ApprovalRule
    {
        $regla->update($data);

        return $regla;
    }

    public function delete(ApprovalRule $regla, string $motivo): void
    {
        $regla->deleted_description = $motivo;
        $regla->deleted_by          = auth()->id();
        $regla->is_active           = false;
        $regla->saveQuietly();
        $regla->delete();
    }

    public function restore(ApprovalRule $regla): ApprovalRule
    {
        $regla->deleted_description = null;
        $regla->deleted_by          = null;
        $regla->restore();

        return $regla;
    }

    public function forceDelete(ApprovalRule $regla, string $motivo): void
    {
        DB::transaction(function () use ($regla, $motivo) {
            $bloqueada = ApprovalRule::onlyTrashed()->where('id', $regla->id)->lockForUpdate()->first();
            if (! $bloqueada) {
                throw new \RuntimeException("ApprovalRule {$regla->id} ya no esta disponible para borrado definitivo");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => ApprovalRule::class,
                'auditable_id'   => $bloqueada->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'approver_role'  => $bloqueada->approver_role,
                    'priority_level' => $bloqueada->priority_level,
                    'slug'           => $bloqueada->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $motivo,
                'module'         => 'approval_rules',
                'created_at'     => now(),
            ]);

            $bloqueada->forceDelete();
        });
    }

    // ── Masivas ──────────────────────────────────────────────────────────────

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkApprovalRulesActionJob::asyncThreshold();
    }

    /** @return array{queued: bool, count: int, deleted: int[]} */
    public function bulkDelete(array $ids, string $motivo): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkApprovalRulesActionJob::dispatch((int) auth()->id(), 'delete', $ids, ['reason' => $motivo]);

            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $motivo) {
            $borradas = [];
            foreach (ApprovalRule::whereIn('id', $ids)->get() as $regla) {
                $this->delete($regla, $motivo);
                $borradas[] = $regla->id;
            }

            return ['queued' => false, 'count' => count($borradas), 'deleted' => $borradas];
        });
    }

    /** @return array{queued: bool, count: int, changed?: int} */
    public function bulkSetActive(array $ids, bool $activa): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkApprovalRulesActionJob::dispatch((int) auth()->id(), 'set_active', $ids, ['is_active' => $activa]);

            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $activa, $count) {
            $cambiadas = 0;
            foreach (ApprovalRule::whereIn('id', $ids)->get() as $regla) {
                if ((bool) $regla->is_active === $activa) {
                    continue;
                }
                $regla->update(['is_active' => $activa]);
                $cambiadas++;
            }

            return ['queued' => false, 'count' => $count, 'changed' => $cambiadas];
        });
    }

    /** @return array{queued: bool, count: int, restored?: int} */
    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkApprovalRulesActionJob::dispatch((int) auth()->id(), 'restore', $ids, []);

            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $reglas = ApprovalRule::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($reglas as $regla) {
                $this->restore($regla);
            }

            return ['queued' => false, 'count' => $count, 'restored' => $reglas->count()];
        });
    }

    /**
     * @param  int[]  $claimIds
     * @return int[]
     */
    public function undoLastDelete(array $claimIds, int $userId): array
    {
        $restauradas = [];
        foreach (ApprovalRule::onlyTrashed()->whereIn('id', $claimIds)->where('deleted_by', $userId)->get() as $regla) {
            $this->restore($regla);
            $restauradas[] = $regla->id;
        }

        return $restauradas;
    }

    /** Edición en masa del nivel y el estado, que es lo que se retoca a menudo. */
    public function editAllUpdate(array $cambios): int
    {
        $tocadas = 0;

        DB::transaction(function () use ($cambios, &$tocadas) {
            $porId = ApprovalRule::whereIn('id', array_column($cambios, 'id'))->get()->keyBy('id');

            foreach ($cambios as $cambio) {
                $regla = $porId[$cambio['id']] ?? null;
                if (! $regla) {
                    continue;
                }

                $parche = array_filter(
                    array_intersect_key($cambio, array_flip(['priority_level', 'is_required', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($parche)) {
                    continue;
                }

                $hayCambio = false;
                foreach ($parche as $k => $v) {
                    if ((string) $regla->{$k} !== (string) $v) {
                        $hayCambio = true;
                        break;
                    }
                }
                if (! $hayCambio) {
                    continue;
                }

                $regla->fill($parche)->save();
                $tocadas++;
            }
        });

        return $tocadas;
    }

    // ── Opciones para los selectores del formulario ──────────────────────────

    /** Países, tipos de trabajo y roles: lo que hace falta para armar una regla. */
    public function opcionesDelFormulario(): array
    {
        return [
            'countries' => Country::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'iso_code'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'iso' => $c->iso_code])->all(),

            // Los tipos van con su país: el selector de tipo se acota al país
            // elegido, porque un tipo de otro país no se puede exigir aquí.
            'work_types' => WorkType::query()->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'country_id'])
                ->map(fn ($t) => ['value' => $t->id, 'label' => $t->code, 'country_id' => $t->country_id])->all(),

            'approver_roles' => collect(ApproverRole::opciones())
                ->map(fn ($label, $code) => ['value' => $code, 'label' => $label])->values()->all(),
        ];
    }
}
