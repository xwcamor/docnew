<?php

namespace App\Services\SystemManagement;

use App\Jobs\SystemManagement\Tenants\BulkTenantsActionJob;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\UniqueNormalizedName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TenantService
{
    /** Días de trial que recibe un workspace nuevo creado en un plan pago. */
    private const NEW_TENANT_TRIAL_DAYS = 14;

    public function __construct(
        private TenantSystemUserService $systemUsers,
        private SubscriptionService $subscriptions,
    ) {}

    /**
     * Crea workspace + admin user + system user. Transacción atómica:
     * si falla cualquier paso, se revierte todo.
     *
     * Espera en $data:
     *   - name, plan, is_active                               (workspace)
     *   - admin_name, admin_email, admin_password             (admin user, obligatorios)
     *
     * Sobre el plan: `tenants.plan` no existe — el plan se deriva de la
     * suscripción vigente. Si `$data['plan']` es un plan PAGO,
     * se crea automáticamente una suscripción en modo TRIAL (14 días) para
     * que el workspace nazca con una membresía real. Si es `free` (o no se
     * pasa plan), NO se crea suscripción: `free` ES la ausencia de suscripción.
     * El super convierte el trial a pago vía el tab Suscripción.
     *
     * Crea workspace + admin + system_user (+ suscripción) en una sola
     * transacción. Si cualquier paso falla, rollback completo.
     */
    public function create(array $data, ?UploadedFile $logo = null): Tenant
    {
        return DB::transaction(function () use ($data, $logo) {
            // 1) Workspace
            $tenant = Tenant::create([
                'name'       => $data['name'],
                'is_active'  => $data['is_active'] ?? true,
                // Si el form no pasa timezone, Tenant::booted() lo autocompleta
                // desde country.timezone del creator (o UTC).
                'timezone'   => $data['timezone'] ?? null,
                'created_by' => auth()->id(),
            ]);

            if ($logo) {
                $tenant->update(['logo' => $this->storeLogo($logo, $tenant->name)]);
            }

            // 2) Admin user — obligatorio. La validación del StoreRequest garantiza
            //    que admin_email/admin_name/admin_password vienen presentes; chequeamos
            //    igual como defense in depth para el caso de llamadas directas al
            //    service desde código (tests, jobs, etc.).
            if (empty($data['admin_email'])) {
                throw new \RuntimeException('TenantService::create requires admin_email/name/password.');
            }

            $admin = User::withoutGlobalScopes()->create([
                'name'       => $data['admin_name'],
                'email'      => $data['admin_email'],
                'password'   => \Illuminate\Support\Facades\Hash::make($data['admin_password']),
                'slug'       => Str::random(22),
                'tenant_id'  => $tenant->id,
                'country_id' => 1,
                'locale_id'  => 1,
                'is_active'  => true,
                'created_by' => auth()->id(),
            ]);

            $adminRole = \App\Models\Role::where('name', 'admin')
                ->where('guard_name', 'web')->first();
            if ($adminRole) {
                $admin->syncRoles([$adminRole]);
            }

            // 3) System user para API tokens (idempotent).
            $this->systemUsers->ensureFor($tenant);

            // 4) Suscripción inicial — solo si se eligió un plan PAGO.
            //    `free` = sin suscripción (el piso). Plan pago → trial de 14d.
            $plan = $data['plan'] ?? 'free';
            if ($plan !== 'free' && $plan !== '') {
                $this->subscriptions->startTrial($tenant, $plan, self::NEW_TENANT_TRIAL_DAYS);
                $tenant->load('activeSubscription');
            }

            return $tenant;
        });
    }

    public function update(Tenant $tenant, array $data, ?UploadedFile $logo = null): Tenant
    {
        $oldTz = $tenant->timezone;
        $tenant->update($data);

        if ($logo) {
            if ($tenant->logo && Storage::disk('public')->exists($tenant->logo)) {
                Storage::disk('public')->delete($tenant->logo);
            }
            $tenant->update(['logo' => $this->storeLogo($logo, $tenant->name)]);
        }

        // Si cambió el TZ del workspace, invalidamos la cache de Tz para
        // todos los users que dependen de este tenant — la próxima request
        // recalculará desde la jerarquía nueva.
        if (array_key_exists('timezone', $data) && $oldTz !== ($data['timezone'] ?? null)) {
            User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->pluck('id')
                ->each(fn ($uid) => \App\Support\Tz::forget($uid));
        }

        return $tenant;
    }

    public function delete(Tenant $tenant, string $reason): void
    {
        $tenant->deleted_description = $reason;
        $tenant->deleted_by          = auth()->id();
        $tenant->is_active           = false;
        $tenant->saveQuietly();
        $tenant->delete();
    }

    public function restore(Tenant $tenant): Tenant
    {
        $tenant->deleted_description = null;
        $tenant->deleted_by          = null;
        $tenant->restore();
        return $tenant;
    }

    /**
     * Hard delete físico. Audit ANTES del delete + transacción para atomicidad.
     */
    public function forceDelete(Tenant $tenant, string $reason): void
    {
        DB::transaction(function () use ($tenant, $reason) {
            $locked = Tenant::onlyTrashed()->where('id', $tenant->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Tenant {$tenant->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Tenant::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name'      => $locked->name,
                    'plan'      => $locked->currentPlan(),
                    'is_active' => $locked->is_active,
                    'slug'      => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent(),
                'note'           => $reason,
                'module'         => 'tenants',
                'created_at'     => now(),
            ]);

            // Borrar logo del disco si existe.
            if ($locked->logo && Storage::disk('public')->exists($locked->logo)) {
                Storage::disk('public')->delete($locked->logo);
            }

            $locked->forceDelete();
        });
    }

    // ─── Bulk ops ────────────────────────────────────────────────────────────

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkTenantsActionJob::asyncThreshold();
    }

    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTenantsActionJob::dispatch(auth()->id(), 'delete', $ids, ['reason' => $reason]);
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $tenants = Tenant::whereIn('id', $ids)->get();

            // Warn-only check: tenants con users vivos no bloquean, pero el log avisa.
            $hasUsers = User::whereIn('tenant_id', $tenants->pluck('id'))->exists();
            // No bloqueamos (block=false en dependents), pero podríamos loguear si quisiéramos.

            foreach ($tenants as $tenant) {
                $this->delete($tenant, $reason);
            }

            return [
                'queued'      => false,
                'count'       => $tenants->count(),
                'deleted_ids' => $tenants->pluck('id')->all(),
            ];
        });
    }

    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTenantsActionJob::dispatch(auth()->id(), 'set_active', $ids, ['is_active' => $isActive]);
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $tenants = Tenant::whereIn('id', $ids)->get();
            $changed = 0;
            foreach ($tenants as $tenant) {
                if ((bool) $tenant->is_active === $isActive) continue;
                $this->update($tenant, ['is_active' => $isActive]);
                $changed++;
            }

            return ['queued' => false, 'count' => $count, 'changed' => $changed];
        });
    }

    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTenantsActionJob::dispatch(auth()->id(), 'restore', $ids, []);
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $tenants = Tenant::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($tenants as $tenant) {
                $this->restore($tenant);
            }

            return ['queued' => false, 'count' => $count, 'restored' => $tenants->count()];
        });
    }

    // ─── Duplicate ────────────────────────────────────────────────────────────

    public function duplicate(Tenant $tenant): ?Tenant
    {
        $base      = $tenant->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql   = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($tenant, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Tenant::query()
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

            // El duplicado nace con el mismo plan que el original. Si era pago,
            // create() le arranca su propio trial fresco.
            return $this->create([
                'name'      => $candidate,
                'plan'      => $tenant->currentPlan(),
                'is_active' => $tenant->is_active,
            ]);
        });
    }

    // ─── Edit-All batch ───────────────────────────────────────────────────────

    public function editAllBatch(array $changes): array
    {
        $errors = [];
        $seen   = [];

        foreach ($changes as $idx => $change) {
            if (!isset($change['name']) || $change['name'] === '') continue;

            $normalized = mb_strtolower(trim($change['name']));
            $stripped   = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;

            if (isset($seen[$stripped]) && $seen[$stripped] !== $change['id']) {
                $errors["changes.{$idx}.name"] = [__('tenants.name_duplicate_in_batch')];
                continue;
            }
            $seen[$stripped] = $change['id'];

            $rule = new UniqueNormalizedName('tenants', 'name', ignoreId: (int) $change['id']);
            $rule->validate("changes.{$idx}.name", $change['name'], function ($msg) use (&$errors, $idx) {
                $errors["changes.{$idx}.name"] = [$msg];
            });
        }

        if (!empty($errors)) {
            return ['errors' => $errors, 'touched' => 0];
        }

        $touched = 0;
        DB::transaction(function () use ($changes, &$touched) {
            $ids     = array_column($changes, 'id');
            $allByPk = Tenant::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $tenant = $allByPk[$change['id']] ?? null;
                if (!$tenant) continue;

                // El plan no es columna del tenant — los cambios de plan
                // van por suscripciones, no por edit-all batch.
                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasRealChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $tenant->{$k} !== (string) $v) { $hasRealChange = true; break; }
                }
                if (!$hasRealChange) continue;

                $this->update($tenant, $patch);
                $touched++;
            }
        });

        return ['errors' => [], 'touched' => $touched];
    }

    // ─── Import ───────────────────────────────────────────────────────────────

    public function processImport(\Illuminate\Http\UploadedFile $file, string $mode, bool $dryRun): array
    {
        $importer = new \App\Imports\SystemManagement\Tenants\TenantsImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $file);
        } catch (\Throwable $e) {
            \Log::error('TenantsImport failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => $this->humanizeImportError($e),
            ];
        }

        return [
            'ok'      => true,
            'dry_run' => $dryRun,
            'summary' => $importer->summary(),
        ];
    }

    protected function humanizeImportError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($e instanceof \Illuminate\Database\QueryException) {
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate')) {
                return __('imports.err_unique_violation');
            }
            if (str_contains($msg, 'NOT NULL') || str_contains($msg, 'null value')) {
                return __('imports.err_not_null_violation');
            }
            if (str_contains($msg, 'foreign key') || str_contains($msg, 'violates foreign')) {
                return __('imports.err_foreign_key_violation');
            }
        }
        return __('imports.process_failed');
    }

    // ─── Export helpers ───────────────────────────────────────────────────────

    public function countForExport(array $options): int
    {
        $scope = $options['scope'] ?? 'filtered';
        if ($scope === 'selected') return count($options['selected_ids'] ?? []);
        if ($scope === 'all')      return Tenant::query()->count();
        return Tenant::query()->filter($options['filters'] ?? [])->count();
    }

    public function recordExportAudit(string $format, array $options): void
    {
        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => 'export_queued',
            'auditable_type' => Tenant::class,
            'auditable_id'   => null,
            'module'         => 'tenants',
            'old_values'     => null,
            'new_values'     => [
                'format'                  => $format,
                'scope'                   => $options['scope']        ?? 'filtered',
                'columns'                 => $options['columns']      ?? [],
                'title'                   => $options['title']        ?? null,
                'orientation'             => $format === 'pdf'   ? ($options['orientation']    ?? null) : null,
                'paper_size'              => $format === 'pdf'   ? ($options['paper_size']     ?? null) : null,
                'autofilter'              => $format === 'excel' ? ($options['autofilter']     ?? null) : null,
                'freeze_header'           => $format === 'excel' ? ($options['freeze_header']  ?? null) : null,
                'include_filters_summary' => $options['include_filters_summary'] ?? false,
                'filters'                 => $options['filters']      ?? [],
                'selected_ids_count'      => count($options['selected_ids'] ?? []),
            ],
            'url'        => route('system_management.tenants.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    // ─── Logo handling ────────────────────────────────────────────────────────

    /**
     * Store the logo en storage/public/logos/{slug}/{timestamp}_{rand}.{ext}.
     *
     * El nombre se genera localmente — NO usamos `getClientOriginalName()` que
     * viene del cliente (podría tener path traversal, doble extensión tipo
     * `shell.php.jpg`, espacios o unicode raro). La extensión se valida con
     * `extension()` (toma el MIME real, no el nombre). Mismo patrón que
     * UserService::storePhoto.
     */
    private function storeLogo(UploadedFile $file, string $tenantName): string
    {
        $slug = Str::slug($tenantName) . '-' . uniqid();
        $ext  = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'], true)) {
            $ext = 'jpg';
        }
        $filename = time() . '_' . Str::random(12) . '.' . $ext;
        return $file->storeAs("logos/{$slug}", $filename, 'public');
    }
}
