<?php

namespace App\Imports\AutomationManagement\Automations;

use App\Models\Automation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Automations from .xlsx/.csv.
 *
 * Columns (matching AutomationsImportTemplate):
 *   - name                (required, max 255)
 *   - description         (optional)
 *   - is_active           (boolean-ish; default true)
 *   - trigger_kind        (cron|daily|weekly|monthly; default daily)
 *   - trigger_time        (HH:MM for daily/weekly/monthly)
 *   - trigger_expression  (cron string when kind=cron)
 *   - trigger_day         (int — day-of-week 0..6 for weekly, day-of-month 1..31 for monthly)
 *   - data_source         (required)
 *   - action_type         (required)
 *   - action_config_json  (required JSON string parsed to array)
 *
 * Modes: 'create_only' | 'update_or_create'
 *
 * 3-layer duplicate protection (todo dentro del tenant scope):
 *   1. In-file: normalizado (trim+lower+iconv) catchea dupes en el mismo upload
 *   2. App: lookup case + accent insensitive contra DB del tenant
 *   3. DB: el unique constraint que exista
 *
 * Tenant scoping:
 *   - El trait BelongsToTenant auto-fillea tenant_id en `creating` desde
 *     auth()->user()->tenant_id. Esto cubre todos los `create()` aquí.
 *   - Para super (sin tenant_id) caemos en lookup sin scope.
 *
 * Enforce `Tenant::maxRecordsPerModule()`:
 *   - Si el tenant tiene limite, contamos cuantas automations tiene HOY +
 *     cuantas vamos a CREAR. Si supera, marcamos las filas excedentes como
 *     errores (no se crean). Las filas que actualizan existentes no cuentan
 *     contra el limite.
 *
 * Todo va en transaccion. dryRun=true → rollback al final (preview UI).
 */
class AutomationsImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, is_active:bool, action:string}> */
    public array $preview = [];

    /** tenant_id del usuario autenticado, capturado al construir. */
    protected ?int $tenantId;

    /** Limite de records del plan (>0 = aplica; 0 o PHP_INT_MAX = ilimitado). */
    protected int $maxRecords;

    /** Count actual de automations del tenant (pre-import). */
    protected int $currentCount;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user           = Auth::user();
        $this->tenantId = $user?->tenant_id;

        // Plan limit del tenant. super sin tenant_id → sin limite.
        if ($user && $user->tenant) {
            $this->maxRecords = $user->tenant->maxRecordsPerModule();
        } else {
            $this->maxRecords = PHP_INT_MAX;
        }

        // Snapshot del count actual. Para super contamos sin scope.
        $this->currentCount = $this->tenantId !== null
            ? Automation::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->count()
            : Automation::withoutGlobalScopes()->count();
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file por nombre normalizado.
            $seenInFileByName = [];

            $newRecordsCount = 0; // contador de filas que crearian una nueva automation

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2; // +2 = header (1) + indexacion desde 0.

                $name = $this->normalizeString($row['name'] ?? null);
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

                $normNameKey = $this->normalizeKey($name);
                if (isset($seenInFileByName[$normNameKey])) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFileByName[$normNameKey]]),
                        'value'   => $name,
                    ];
                    continue;
                }
                $seenInFileByName[$normNameKey] = $absoluteRow;

                $description = $this->normalizeString($row['description'] ?? null);
                $isActive    = $this->normalizeBool($row['is_active'] ?? null, default: true);

                // ─── Trigger ─────────────────────────────────────────────
                $triggerKind = $this->normalizeString($row['trigger_kind'] ?? null) ?? 'daily';
                $triggerKind = mb_strtolower($triggerKind);
                if (!in_array($triggerKind, ['cron', 'daily', 'weekly', 'monthly'], true)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('automations.err_trigger_kind_invalid'),
                        'value'   => $triggerKind,
                    ];
                    continue;
                }

                $triggerTime = $this->normalizeString($row['trigger_time'] ?? null);
                $triggerExpr = $this->normalizeString($row['trigger_expression'] ?? null);
                $triggerDay  = $this->normalizeString($row['trigger_day'] ?? null);

                $triggerConfig = match ($triggerKind) {
                    'cron'    => ['kind' => 'cron', 'expression' => $triggerExpr ?? ''],
                    'daily'   => ['kind' => 'daily', 'time' => $triggerTime ?? '09:00'],
                    'weekly'  => ['kind' => 'weekly', 'day' => (int) ($triggerDay ?? 1), 'time' => $triggerTime ?? '09:00'],
                    'monthly' => ['kind' => 'monthly', 'day' => (int) ($triggerDay ?? 1), 'time' => $triggerTime ?? '09:00'],
                };

                if ($triggerKind === 'cron' && !$triggerExpr) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('automations.err_cron_expression_required'),
                        'value'   => $name,
                    ];
                    continue;
                }

                // ─── Data source / action ─────────────────────────────────
                $dataSource = $this->normalizeString($row['data_source'] ?? null);
                if (!$dataSource) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('automations.err_data_source_required'),
                        'value'   => $name,
                    ];
                    continue;
                }

                $actionType = $this->normalizeString($row['action_type'] ?? null);
                if (!$actionType) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('automations.err_action_type_required'),
                        'value'   => $name,
                    ];
                    continue;
                }

                $actionJson   = $this->normalizeString($row['action_config_json'] ?? null);
                $actionConfig = null;
                if ($actionJson === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('automations.err_action_config_required'),
                        'value'   => $name,
                    ];
                    continue;
                }
                $actionConfig = json_decode($actionJson, true);
                if (!is_array($actionConfig)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('automations.err_action_config_invalid_json'),
                        'value'   => mb_substr($actionJson, 0, 60) . '…',
                    ];
                    continue;
                }

                // Layer 2: DB lookup case + accent insensitive (scoped al tenant).
                $existing = $this->findExistingByNameInsensitive($name);

                if ($existing) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'       => $absoluteRow,
                            'name'      => $name,
                            'is_active' => $isActive,
                            'action'    => 'skipped',
                        ];
                        continue;
                    }

                    // Solo tocar campos que cambian (evita audit logs vacios).
                    $patch = [];
                    if ((bool) $existing->is_active !== $isActive)         $patch['is_active']      = $isActive;
                    if ($description !== null && $existing->description !== $description) $patch['description'] = $description;
                    if ($existing->trigger_type !== 'schedule')             $patch['trigger_type']   = 'schedule';
                    if (($existing->trigger_config ?? null) != $triggerConfig) $patch['trigger_config'] = $triggerConfig;
                    if ($existing->data_source !== $dataSource)             $patch['data_source']    = $dataSource;
                    if ($existing->action_type !== $actionType)             $patch['action_type']    = $actionType;
                    if (($existing->action_config ?? null) != $actionConfig) $patch['action_config'] = $actionConfig;

                    if (!empty($patch)) {
                        $existing->fill($patch)->save();
                        // Si el trigger cambio, recalcular next_run_at.
                        if (array_key_exists('trigger_config', $patch) || array_key_exists('is_active', $patch)) {
                            $existing->next_run_at = $existing->is_active
                                ? $existing->computeNextRunAt(now())
                                : null;
                            $existing->save();
                        }
                    }

                    $this->updated++;
                    $this->preview[] = [
                        'row'       => $absoluteRow,
                        'name'      => $name,
                        'is_active' => $isActive,
                        'action'    => 'updated',
                    ];
                } else {
                    // Antes de crear, validar limite del plan.
                    if ($this->maxRecords > 0 && $this->maxRecords !== PHP_INT_MAX) {
                        if (($this->currentCount + $newRecordsCount) >= $this->maxRecords) {
                            $this->errors[] = [
                                'row'     => $absoluteRow,
                                'message' => __('plans.limit_records_reached', ['max' => $this->maxRecords]),
                                'value'   => $name,
                            ];
                            continue;
                        }
                    }

                    $automation = new Automation([
                        'name'           => $name,
                        'description'    => $description,
                        'is_active'      => $isActive,
                        'trigger_type'   => 'schedule',
                        'trigger_config' => $triggerConfig,
                        'data_source'    => $dataSource,
                        'data_filter'    => ['where' => [], 'limit' => 100],
                        'action_type'    => $actionType,
                        'action_config'  => $actionConfig,
                        'created_by'     => Auth::id(),
                        // tenant_id lo auto-fillea el trait BelongsToTenant.
                    ]);
                    $automation->save();

                    // Calcular el primer next_run_at.
                    $automation->next_run_at = $automation->is_active
                        ? $automation->computeNextRunAt(now())
                        : null;
                    $automation->save();

                    $newRecordsCount++;
                    $this->created++;
                    $this->preview[] = [
                        'row'       => $absoluteRow,
                        'name'      => $name,
                        'is_active' => $isActive,
                        'action'    => 'created',
                    ];
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

    protected function normalizeString(mixed $value): ?string
    {
        if ($value === null) return null;
        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    /** Lowercase + strip accents (iconv) — mismo pattern que el DB-level layer 2. */
    protected function normalizeKey(string $name): string
    {
        $lower    = mb_strtolower(trim($name));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }

    /**
     * Lookup case + accent insensitive (Postgres unaccent / fallback LOWER),
     * scoped al tenant del usuario.
     */
    protected function findExistingByNameInsensitive(string $name): ?Automation
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query   = Automation::query()->withoutGlobalScopes();

        if ($this->tenantId !== null) {
            $query->where('automations.tenant_id', $this->tenantId);
        }

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(automations.name)) = unaccent(LOWER(?))', [$name]);
        } else {
            $query->whereRaw('LOWER(automations.name) = LOWER(?)', [$name]);
        }

        return $query->first();
    }

    /** Acepta 1/0, true/false, si/no, activo/inactivo, yes/no, active/inactive, x. */
    protected function normalizeBool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') return $default;
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return ((int) $value) === 1;

        $normalized = mb_strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'sí', 'si', 's', 'activo', 'active', 'x'], true);
    }
}
