<?php

namespace App\Services\AutomationManagement;

use App\Jobs\AutomationManagement\Automations\BulkAutomationsActionJob;
use App\Models\AuditLog;
use App\Models\Automation;
use Illuminate\Support\Facades\DB;

/**
 * AutomationService — operaciones de negocio del modulo Automations.
 *
 * Patron clonado de RegionService/RoleService/CustomerService. Maneja la
 * particularidad de next_run_at: se recalcula al crear, al update, al
 * restore y al toggle (es is_active dependiente — null si is_active=false).
 */
class AutomationService
{
    public function create(array $data): Automation
    {
        $automation = new Automation($data);
        $automation->created_by = auth()->id();
        $automation->is_active  = $data['is_active'] ?? true;

        // Super envia tenant_id explicito en el form; admin no lo envia (el
        // trait BelongsToTenant lo autoasigna con su propio tenant en el
        // evento `creating`). Esto cubre ambos casos sin condicionales aquí.
        if (!empty($data['tenant_id'])) {
            $automation->tenant_id = $data['tenant_id'];
        }

        // Calcular next_run_at en memoria ANTES del save. Si lo hacíamos
        // después con un segundo save() y este fallaba (lock contention,
        // conexión caída), la automation quedaba persistida con next_run_at
        // NULL y el scheduler la ignoraba para siempre — un solo save evita
        // ese estado parcial.
        $automation->next_run_at = $automation->computeNextRunAt(now());
        $automation->save();

        return $automation;
    }

    public function update(Automation $automation, array $data): Automation
    {
        $automation->fill($data);
        $automation->is_active = $data['is_active'] ?? $automation->is_active;
        // Permitir al super cambiar el workspace en edit. Admin nunca lo envia.
        if (!empty($data['tenant_id'])) {
            $automation->tenant_id = $data['tenant_id'];
        }

        // Recalcular el next_run_at: si cambio el trigger, hay que reprogramar.
        // Como en create(), lo hacemos en memoria y persistimos con un solo
        // save() para que un fallo no deje la automation con next_run_at NULL.
        $automation->next_run_at = $automation->computeNextRunAt(now());
        $automation->save();

        return $automation;
    }

    /**
     * Soft-delete con motivo. Apaga la automation (is_active=false) y limpia
     * next_run_at para que el scheduler no la considere.
     */
    public function delete(Automation $automation, string $reason, int $userId): void
    {
        $automation->forceFill([
            'deleted_by'          => $userId,
            'deleted_description' => $reason,
            'is_active'           => false,
            'next_run_at'         => null,
        ])->save();
        $automation->delete();
    }

    /**
     * Restaura + recalcula next_run_at acorde al trigger original.
     */
    public function restore(Automation $automation): Automation
    {
        $automation->restore();
        $automation->forceFill([
            'deleted_by'          => null,
            'deleted_description' => null,
            'next_run_at'         => $automation->computeNextRunAt(now()),
        ])->save();
        return $automation;
    }

    /**
     * Hard delete. Audit ANTES + transaccion + lockForUpdate.
     */
    public function forceDelete(Automation $automation, string $reason): void
    {
        DB::transaction(function () use ($automation, $reason) {
            $locked = Automation::onlyTrashed()->where('id', $automation->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Automation {$automation->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Automation::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name'        => $locked->name,
                    'action_type' => $locked->action_type,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'automations',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona la automation. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El clon arranca pausado (is_active=false) y con counters limpios —
     * fail-safe: previene que un clon recien copiado corra antes de revision.
     */
    public function duplicate(Automation $automation): ?Automation
    {
        $base    = $automation->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($automation, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Automation::query()
                    ->when($isPgsql,
                        fn ($q) => $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$candidate]),
                        fn ($q) => $q->whereRaw('LOWER(name) = LOWER(?)', [$candidate]),
                    )
                    ->lockForUpdate()
                    ->exists();

                if (!$exists) break;
                $candidate = $base . ' ' . $i;
                $i++;
                if ($i > 100) return null;
            }

            $new = $automation->replicate([
                'runs_count', 'failures_count', 'last_run_at', 'next_run_at',
                'deleted_at', 'deleted_by', 'deleted_description',
            ]);
            $new->name           = $candidate;
            $new->is_active      = false; // fail-safe — el clon arranca pausado
            $new->created_by     = auth()->id();
            $new->runs_count     = 0;
            $new->failures_count = 0;
            $new->last_run_at    = null;
            $new->next_run_at    = null;
            $new->save();

            return $new;
        });
    }

    /**
     * Toggle is_active. Si queda activa, recalculamos next_run_at; si queda
     * pausada, lo limpiamos para que el scheduler la ignore.
     */
    public function toggleActive(Automation $automation): Automation
    {
        $automation->is_active   = !$automation->is_active;
        $automation->next_run_at = $automation->is_active
            ? $automation->computeNextRunAt(now())
            : null;
        $automation->save();
        return $automation;
    }

    // ─── Bulk ops ──────────────────────────────────────────────────────────
    //
    // Auto-async: si count(ids) excede el umbral, dispatchamos el job y
    // devolvemos un payload "queued" para que el controller redirija con
    // mensaje de cola. Bajo el umbral, corre inline.

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkAutomationsActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason, int $userId): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkAutomationsActionJob::dispatch(
                $userId,
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason, $userId) {
            $automations = Automation::whereIn('id', $ids)->get();
            $deletedIds  = [];
            foreach ($automations as $a) {
                $this->delete($a, $reason, $userId);
                $deletedIds[] = $a->id;
            }
            return ['queued' => false, 'count' => $automations->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkAutomationsActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $automations = Automation::whereIn('id', $ids)->get();
            $changed     = 0;
            foreach ($automations as $a) {
                if ((bool) $a->is_active === $isActive) continue;
                $a->is_active   = $isActive;
                $a->next_run_at = $isActive ? $a->computeNextRunAt(now()) : null;
                $a->save();
                $changed++;
            }
            return ['queued' => false, 'count' => $count, 'changed' => $changed];
        });
    }

    /**
     * @return array{queued: bool, count: int, restored?: int}
     */
    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkAutomationsActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $automations = Automation::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($automations as $a) {
                $this->restore($a);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $automations->count()];
        });
    }

    /**
     * Undo dentro del window de 60s. Solo restaura las filas que matchean
     * deleted_by = userId, no cualquier id del claim.
     *
     * @param int[] $claimIds
     * @return int[]
     */
    public function undoLastDelete(array $claimIds, int $userId): array
    {
        $automations = Automation::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($automations as $a) {
            $this->restore($a);
            $restored[] = $a->id;
        }
        return $restored;
    }

    /**
     * Batch update de name + is_active. Transaccional. Si toggleamos is_active
     * recalculamos next_run_at acorde.
     */
    public function editAllUpdate(array $changes): int
    {
        $touched = 0;

        DB::transaction(function () use ($changes, &$touched) {
            $ids = array_column($changes, 'id');
            $all = Automation::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $automation = $all[$change['id']] ?? null;
                if (!$automation) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasRealChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $automation->{$k} !== (string) $v) { $hasRealChange = true; break; }
                }
                if (!$hasRealChange) continue;

                $automation->fill($patch);

                if (array_key_exists('is_active', $patch)) {
                    $automation->next_run_at = $automation->is_active
                        ? $automation->computeNextRunAt(now())
                        : null;
                }

                $automation->save();
                $touched++;
            }
        });

        return $touched;
    }
}
