<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\TapChangerTechnologies\BulkTapChangerTechnologiesActionJob;
use App\Models\AuditLog;
use App\Models\TapChangerTechnology;
use Illuminate\Support\Facades\DB;

/**
 * TapChangerTechnologyService â€” operaciones de negocio del modulo tap_changer_technologies.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class TapChangerTechnologyService
{
    public function create(array $data): TapChangerTechnology
    {
        $tapChangerTechnology = new TapChangerTechnology($data);
        $tapChangerTechnology->created_by = auth()->id();
        $tapChangerTechnology->save();
        return $tapChangerTechnology;
    }

    public function update(TapChangerTechnology $tapChangerTechnology, array $data): TapChangerTechnology
    {
        $tapChangerTechnology->update($data);
        return $tapChangerTechnology;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(TapChangerTechnology $tapChangerTechnology, string $reason): void
    {
        $tapChangerTechnology->deleted_description = $reason;
        $tapChangerTechnology->deleted_by          = auth()->id();
        $tapChangerTechnology->is_active           = false;
        $tapChangerTechnology->saveQuietly();
        $tapChangerTechnology->delete();
    }

    public function restore(TapChangerTechnology $tapChangerTechnology): TapChangerTechnology
    {
        $tapChangerTechnology->deleted_description = null;
        $tapChangerTechnology->deleted_by          = null;
        $tapChangerTechnology->restore();
        return $tapChangerTechnology;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(TapChangerTechnology $tapChangerTechnology, string $reason): void
    {
        DB::transaction(function () use ($tapChangerTechnology, $reason) {
            $locked = TapChangerTechnology::onlyTrashed()->where('id', $tapChangerTechnology->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("TapChangerTechnology {$tapChangerTechnology->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => TapChangerTechnology::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name' => $locked->name,
                    'code' => $locked->code,
                    'slug' => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'tap_changer_technologies',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el tapChangerTechnology. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant â€” se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(TapChangerTechnology $tapChangerTechnology): ?TapChangerTechnology
    {
        $base    = $tapChangerTechnology->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($tapChangerTechnology, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = TapChangerTechnology::query()
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

            $clone = new TapChangerTechnology($tapChangerTechnology->only(['is_active', 'sort_order']));
            $clone->name       = $candidate;
            $clone->code       = null;
            $clone->created_by = auth()->id();
            $clone->save();

            return $clone;
        });
    }

    // â”€â”€â”€ Bulk ops â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    //
    // Auto-async: si count(ids) excede el umbral, dispatchamos el job y
    // devolvemos un payload "queued" para que el controller redirija con
    // mensaje de cola. Bajo el umbral, corre inline. El umbral vive en
    // BulkTapChangerTechnologiesActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkTapChangerTechnologiesActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTapChangerTechnologiesActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $tapChangerTechnologies  = TapChangerTechnology::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($tapChangerTechnologies as $tapChangerTechnology) {
                $this->delete($tapChangerTechnology, $reason);
                $deletedIds[] = $tapChangerTechnology->id;
            }
            return ['queued' => false, 'count' => $tapChangerTechnologies->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTapChangerTechnologiesActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $tapChangerTechnologies = TapChangerTechnology::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($tapChangerTechnologies as $tapChangerTechnology) {
                if ((bool) $tapChangerTechnology->is_active === $isActive) continue;
                $tapChangerTechnology->update(['is_active' => $isActive]);
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
            BulkTapChangerTechnologiesActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $tapChangerTechnologies = TapChangerTechnology::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($tapChangerTechnologies as $tapChangerTechnology) {
                $this->restore($tapChangerTechnology);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $tapChangerTechnologies->count()];
        });
    }

    /**
     * Undo dentro del window de 60s. Defense in depth: solo restaura las filas
     * que matchean deleted_by = userId, no cualquier id del claim.
     *
     * @param int[] $claimIds
     * @return int[] ids efectivamente restaurados
     */
    public function undoLastDelete(array $claimIds, int $userId): array
    {
        $tapChangerTechnologies = TapChangerTechnology::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($tapChangerTechnologies as $tapChangerTechnology) {
            $this->restore($tapChangerTechnology);
            $restored[] = $tapChangerTechnology->id;
        }
        return $restored;
    }

    /**
     * Batch update de name + is_active. Persistencia en transaccion para
     * atomicidad. Skip filas sin cambio real para evitar audit log noise.
     *
     * @return int touched count
     */
    public function editAllUpdate(array $changes): int
    {
        $touched = 0;

        DB::transaction(function () use ($changes, &$touched) {
            $ids   = array_column($changes, 'id');
            $byId  = TapChangerTechnology::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $tapChangerTechnology = $byId[$change['id']] ?? null;
                if (!$tapChangerTechnology) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $tapChangerTechnology->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $tapChangerTechnology->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
