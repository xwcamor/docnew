<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\Transformers\BulkTransformersActionJob;
use App\Models\AuditLog;
use App\Models\Transformer;
use Illuminate\Support\Facades\DB;

/**
 * TransformerService — operaciones de negocio del modulo Transformers.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class TransformerService
{
    public function create(array $data): Transformer
    {
        $transformer = new Transformer($data);
        $transformer->created_by = auth()->id();
        $transformer->save();
        return $transformer;
    }

    public function update(Transformer $transformer, array $data): Transformer
    {
        $transformer->update($data);
        return $transformer;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(Transformer $transformer, string $reason): void
    {
        $transformer->deleted_description = $reason;
        $transformer->deleted_by          = auth()->id();
        $transformer->saveQuietly();
        $transformer->delete();
    }

    public function restore(Transformer $transformer): Transformer
    {
        $transformer->deleted_description = null;
        $transformer->deleted_by          = null;
        $transformer->restore();
        return $transformer;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(Transformer $transformer, string $reason): void
    {
        DB::transaction(function () use ($transformer, $reason) {
            $locked = Transformer::onlyTrashed()->where('id', $transformer->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Transformer {$transformer->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Transformer::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'serial' => $locked->serial,
                    'tag'    => $locked->tag,
                    'slug'   => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'transformers',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el transformer. El identificador (serial) no es unico, por eso solo
     * se le agrega el sufijo "(copia)" sin verificacion de unicidad. El tag se
     * copia tal cual.
     */
    public function duplicate(Transformer $transformer): ?Transformer
    {
        return DB::transaction(function () use ($transformer) {
            $clone = new Transformer($transformer->only([
                'customer_id', 'oil_type_id', 'transformer_type_id',
                'voltage_kv', 'power_mva', 'manufacture_year', 'tap_changer_type_id',
            ]));
            $clone->serial     = $transformer->serial ? $transformer->serial . ' (' . __('global.duplicate_suffix') . ')' : null;
            $clone->tag        = $transformer->tag;
            $clone->created_by = auth()->id();
            $clone->save();

            return $clone;
        });
    }

    // ─── Bulk ops ──────────────────────────────────────────────────────────
    //
    // Auto-async: si count(ids) excede el umbral, dispatchamos el job y
    // devolvemos un payload "queued" para que el controller redirija con
    // mensaje de cola. Bajo el umbral, corre inline. El umbral vive en
    // BulkTransformersActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkTransformersActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTransformersActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $transformers  = Transformer::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($transformers as $transformer) {
                $this->delete($transformer, $reason);
                $deletedIds[] = $transformer->id;
            }
            return ['queued' => false, 'count' => $transformers->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, restored?: int}
     */
    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTransformersActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $transformers = Transformer::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($transformers as $transformer) {
                $this->restore($transformer);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $transformers->count()];
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
        $transformers = Transformer::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($transformers as $transformer) {
            $this->restore($transformer);
            $restored[] = $transformer->id;
        }
        return $restored;
    }

    /**
     * Batch update de serial (editar-todos). Persistencia en transaccion para
     * atomicidad. Skip filas sin cambio real para evitar audit log noise.
     *
     * @return int touched count
     */
    public function editAllUpdate(array $changes): int
    {
        $touched = 0;

        DB::transaction(function () use ($changes, &$touched) {
            $ids   = array_column($changes, 'id');
            $byId  = Transformer::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $transformer = $byId[$change['id']] ?? null;
                if (!$transformer) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['serial'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $transformer->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $transformer->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
