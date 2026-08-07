<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\Customers\BulkCustomersActionJob;
use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CustomerService — operaciones de negocio del modulo Customers.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class CustomerService
{
    /**
     * Crea el cliente y le siembra una jerarquía geográfica por defecto
     * (Ubicación "Sede Principal" → Área "General" → Subestación "Principal"),
     * para que se le puedan agregar transformadores de inmediato. Todo en una
     * transacción: o se crea completo o nada.
     */
    public function create(array $data, ?UploadedFile $logo = null): Customer
    {
        return DB::transaction(function () use ($data, $logo) {
            $userId = auth()->id();
            unset($data['logo']); // el archivo va por $logo, no como atributo

            $customer = new Customer($data);
            $customer->created_by = $userId;
            $customer->save();

            if ($logo) {
                $customer->update(['logo' => $this->storeLogo($logo, $customer->name)]);
            }

            $location = $customer->locations()->create([
                'name' => 'Sede Principal', 'created_by' => $userId,
            ]);
            $area = $location->areas()->create([
                'name' => 'General', 'created_by' => $userId,
            ]);
            $area->substations()->create([
                'name' => 'Principal', 'created_by' => $userId,
            ]);

            return $customer;
        });
    }

    public function update(Customer $customer, array $data, ?UploadedFile $logo = null): Customer
    {
        unset($data['logo']); // el archivo va por $logo, no como atributo
        if ($logo) {
            // Borra el logo anterior antes de guardar el nuevo.
            if ($customer->logo && Storage::disk('public')->exists($customer->logo)) {
                Storage::disk('public')->delete($customer->logo);
            }
            $data['logo'] = $this->storeLogo($logo, $customer->name);
        }
        $customer->update($data);
        return $customer;
    }

    /** Guarda el logo en el disco public bajo logos/{slug}/ y devuelve el path. */
    private function storeLogo(UploadedFile $file, string $customerName): string
    {
        $slug = Str::slug($customerName) . '-' . uniqid();
        $ext  = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'], true)) {
            $ext = 'jpg';
        }
        $filename = time() . '_' . Str::random(12) . '.' . $ext;
        return $file->storeAs("customer-logos/{$slug}", $filename, 'public');
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(Customer $customer, string $reason): void
    {
        $customer->deleted_description = $reason;
        $customer->deleted_by          = auth()->id();
        $customer->is_active           = false;
        $customer->saveQuietly();
        $customer->delete();
    }

    public function restore(Customer $customer): Customer
    {
        $customer->deleted_description = null;
        $customer->deleted_by          = null;
        $customer->restore();
        return $customer;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(Customer $customer, string $reason): void
    {
        DB::transaction(function () use ($customer, $reason) {
            $locked = Customer::onlyTrashed()->where('id', $customer->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Customer {$customer->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Customer::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name' => $locked->name,
                    'cod'  => $locked->cod,
                    'slug' => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'customers',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el customer. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant — se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(Customer $customer): ?Customer
    {
        $base    = $customer->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($customer, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Customer::query()
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

            $clone = new Customer($customer->only(['country_id', 'is_active']));
            $clone->name       = $candidate;
            $clone->cod        = null;
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
    // BulkCustomersActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkCustomersActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkCustomersActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $customers  = Customer::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($customers as $customer) {
                $this->delete($customer, $reason);
                $deletedIds[] = $customer->id;
            }
            return ['queued' => false, 'count' => $customers->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkCustomersActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $customers = Customer::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($customers as $customer) {
                if ((bool) $customer->is_active === $isActive) continue;
                $customer->update(['is_active' => $isActive]);
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
            BulkCustomersActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $customers = Customer::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($customers as $customer) {
                $this->restore($customer);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $customers->count()];
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
        $customers = Customer::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($customers as $customer) {
            $this->restore($customer);
            $restored[] = $customer->id;
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
            $byId  = Customer::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $customer = $byId[$change['id']] ?? null;
                if (!$customer) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $customer->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $customer->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
