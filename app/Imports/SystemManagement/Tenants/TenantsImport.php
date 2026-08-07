<?php

namespace App\Imports\SystemManagement\Tenants;

use App\Models\Tenant;
use App\Services\SystemManagement\TenantService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Tenants from .xlsx/.csv.
 *
 * Columns:  name (required, max 255), is_active (optional, boolean-ish)
 * Modes:    'create_only' | 'update_or_create'
 *
 * 3-layer duplicate protection:
 *   1. In-file: normalizado (trim+lower+iconv) catchea dupes en el mismo upload
 *   2. App: lookup case + accent insensitive contra DB (mismo pattern que scopeFilter)
 *   3. DB: partial unique index `unaccent(lower(name)) WHERE deleted_at IS NULL`
 *
 * Todo va en transacción. dryRun=true → rollback al final (preview UI).
 */
class TenantsImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, is_active:bool, action:string}> */
    public array $preview = [];

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
        protected ?TenantService $service = null,
    ) {
        $this->service ??= app(TenantService::class);
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file. Map normalized name → row donde apareció.
            $seenInFile = [];

            // +2 = header (fila 1) + indexación desde 0.
            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2;

                $name = $this->normalizeName($row['name'] ?? null);
                if ($name === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_required'),
                        'value'   => '—',
                    ];
                    continue;
                }
                if (mb_strlen($name) > 255) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_too_long'),
                        'value'   => mb_substr($name, 0, 60) . '…',
                    ];
                    continue;
                }

                $normKey = $this->normalizeKey($name);
                if (isset($seenInFile[$normKey])) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFile[$normKey]]),
                        'value'   => $name,
                    ];
                    continue;
                }
                $seenInFile[$normKey] = $absoluteRow;

                $isActive = $this->normalizeBool($row['is_active'] ?? null, default: true);

                // Plan: slug que exista activo en DB, default free. Valor inválido → error fila.
                $planRaw = strtolower(trim((string) ($row['plan'] ?? 'free')));
                $allowedPlans = \App\Models\Plan::activeSlugs() ?: ['free'];
                if (!in_array($planRaw, $allowedPlans, true)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('tenants.plan_invalid', ['allowed' => implode(', ', $allowedPlans)]),
                        'value'   => $planRaw,
                    ];
                    continue;
                }

                $existing = $this->findExistingByNameInsensitive($name);

                // Campos que el import puede tocar de un tenant EXISTENTE. El
                // `plan` NO está aquí: cambiar el plan de un tenant existente
                // significa gestionar su suscripción — eso va por el tab
                // Suscripción, no por import masivo.
                $corePatch = [
                    'name'      => $name,
                    'is_active' => $isActive,
                ];

                $previewBase = [
                    'row'       => $absoluteRow,
                    'name'      => $name,
                    'plan'      => $planRaw,
                    'is_active' => $isActive,
                ];

                if ($existing) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = $previewBase + ['action' => 'skipped'];
                        continue;
                    }
                    $patch = [];
                    foreach ($corePatch as $k => $v) {
                        if ((string) $existing->{$k} !== (string) $v) $patch[$k] = $v;
                    }
                    if (!empty($patch)) {
                        $this->service->update($existing, $patch);
                    }
                    $this->updated++;
                    $this->preview[] = $previewBase + ['action' => 'updated'];
                } else {
                    // Tenant NUEVO. TenantService::create():
                    //   - exige admin_* (un workspace sin admin es inconsistente)
                    //     → auto-provisionamos uno (email derivado + pass random).
                    //   - recibe `plan`: si es pago, arranca un trial; free → sin sub.
                    $this->service->create(
                        $corePatch + ['plan' => $planRaw] + $this->autoAdminFor($name),
                    );
                    $this->created++;
                    $this->preview[] = $previewBase + ['action' => 'created'];
                }
            }

            if ($this->dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function summary(): array
    {
        return [
            'created'      => $this->created,
            'updated'      => $this->updated,
            'skipped'      => $this->skipped,
            'error_count'  => count($this->errors),
            'total_rows'   => $this->created + $this->updated + $this->skipped + count($this->errors),
            'errors'       => array_slice($this->errors, 0, 50),
            'preview'      => array_slice($this->preview, 0, 100),
            'dry_run'      => $this->dryRun,
        ];
    }

    /**
     * Credenciales de admin auto-generadas para un tenant importado.
     *
     * El email es único (slug del nombre + sufijo random) y usa el dominio
     * `.import.local` para que sea identificable como auto-provisionado. El
     * password es random — el owner del workspace lo resetea por "olvidé mi
     * contraseña". Mantiene la invariante "ningún workspace sin admin".
     */
    protected function autoAdminFor(string $name): array
    {
        $base = Str::slug($name) ?: 'workspace';

        return [
            'admin_name'     => "Admin {$name}",
            'admin_email'    => $base . '-' . Str::lower(Str::random(8)) . '@import.local',
            'admin_password' => Str::random(20),
        ];
    }

    protected function normalizeName(mixed $value): ?string
    {
        if ($value === null) return null;
        $name = trim((string) $value);
        return $name === '' ? null : $name;
    }

    /** Lowercase + strip accents (iconv) — mismo pattern que el DB-level layer 2. */
    protected function normalizeKey(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }

    /** Lookup case + accent insensitive (Postgres unaccent / fallback LOWER). */
    protected function findExistingByNameInsensitive(string $name): ?Tenant
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query = Tenant::query();

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name]);
        } else {
            $query->whereRaw('LOWER(name) = LOWER(?)', [$name]);
        }

        return $query->first();
    }

    /** Acepta 1/0, true/false, sí/no, activo/inactivo, yes/no, active/inactive, x. */
    protected function normalizeBool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') return $default;
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return ((int) $value) === 1;

        $normalized = mb_strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'sí', 'si', 's', 'activo', 'active', 'x'], true);
    }
}
