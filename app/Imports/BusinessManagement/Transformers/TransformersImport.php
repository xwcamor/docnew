<?php

namespace App\Imports\BusinessManagement\Transformers;

use App\Models\Brand;
use App\Models\ConnectionType;
use App\Models\Customer;
use App\Models\OilType;
use App\Models\TapChangerType;
use App\Models\Transformer;
use App\Models\TransformerPreservation;
use App\Models\TransformerType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Transformers from .xlsx/.csv.
 *
 * Columnas (todas opcionales salvo que quieras dedup por serial):
 *   - serial, tag
 *   - customer            → por `cod` o `name` (del tenant)
 *   - oil_type            → catálogo, por `code` o `name`
 *   - transformer_type    → catálogo, por `code` o `name`
 *   - brand               → catálogo, por `code` o `name`
 *   - tap_changer_type    → catálogo, por `code` o `name`
 *   - connection_type     → catálogo, por `code` o `name`
 *   - preservation        → catálogo, por `code` o `name`
 *   - voltage_kv, power_mva  → numérico
 *   - manufacture_year       → entero (1900..año+1)
 *   - paper_type             → kraft | upgraded
 *   - phases                 → 1 | 2 | 3
 *   - oil_treated_at         → fecha (YYYY-MM-DD)
 *
 * Las FKs se resuelven por code/name (case + accent insensitive). Si una FK
 * viene con valor pero no existe en el catálogo, la fila se RECHAZA con error
 * (no se autocrean catálogos ni clientes). Vacío = se deja null.
 *
 * Dedup por `serial` (case+accent insensitive, scoped al tenant). Modes:
 * 'create_only' | 'update_or_create'. En update se actualizan los campos
 * provistos que cambiaron. Todo en transacción; dryRun=true → rollback (preview).
 */
class TransformersImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, serial:?string, action:string}> */
    public array $preview = [];

    protected ?int $tenantId;
    protected int $maxRecords;
    protected int $currentCount;

    /** Cache de resolución de FKs: "campo|claveNormalizada" => ?id. */
    protected array $fkCache = [];

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user           = Auth::user();
        $this->tenantId = $user?->tenant_id;

        if ($user && $user->tenant) {
            $this->maxRecords = $user->tenant->maxRecordsPerModule();
        } else {
            $this->maxRecords = PHP_INT_MAX;
        }

        $this->currentCount = $this->tenantId !== null
            ? Transformer::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->count()
            : Transformer::withoutGlobalScopes()->count();
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            $seenInFileBySerial = [];
            $newRecordsCount = 0;

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2; // header + base-0

                $serial = $this->normalizeName($row['serial'] ?? null);
                $tag    = $this->normalizeName($row['tag'] ?? null);

                if ($serial !== null && mb_strlen($serial) > 100) {
                    $this->error($absoluteRow, __('imports.err_name_too_long'), mb_substr($serial, 0, 60) . '…');
                    continue;
                }
                if ($tag !== null && mb_strlen($tag) > 100) {
                    $this->error($absoluteRow, __('imports.err_name_too_long'), mb_substr($tag, 0, 60) . '…');
                    continue;
                }

                // Resolver el resto de columnas (FKs + numéricos + enums + fecha).
                [$attrs, $rowError] = $this->resolveAttributes($row, $absoluteRow, $serial);
                if ($rowError !== null) {
                    continue; // ya se registró el error
                }
                $attrs['serial'] = $serial;
                $attrs['tag']    = $tag;

                // Dedup por serial (opcional).
                $existing = null;
                if ($serial !== null) {
                    $normKey = $this->normalizeKey($serial);
                    if (isset($seenInFileBySerial[$normKey])) {
                        $this->error($absoluteRow, __('imports.err_duplicate_in_file', ['row' => $seenInFileBySerial[$normKey]]), $serial);
                        continue;
                    }
                    $seenInFileBySerial[$normKey] = $absoluteRow;
                    $existing = $this->findExistingBySerialInsensitive($serial);
                }

                if ($existing) {
                    // Registro BLOQUEADO (Lockable): el import no lo pisa.
                    if ($existing->is_locked) {
                        $this->skipped++;
                        $this->preview[] = ['row' => $absoluteRow, 'serial' => $serial, 'action' => 'skipped', 'reason' => 'locked'];
                        continue;
                    }

                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = ['row' => $absoluteRow, 'serial' => $serial, 'action' => 'skipped'];
                        continue;
                    }

                    // Update: solo los campos provistos que cambiaron.
                    $patch = [];
                    foreach ($attrs as $k => $v) {
                        if ($k === 'serial') continue;            // la clave no se toca
                        if ($v === null) continue;                // vacío no pisa lo existente
                        if ($existing->{$k} != $v) $patch[$k] = $v;
                    }
                    if (!empty($patch)) {
                        $existing->fill($patch)->save();
                    }

                    $this->updated++;
                    $this->preview[] = ['row' => $absoluteRow, 'serial' => $serial, 'action' => 'updated'];
                } else {
                    if ($this->maxRecords > 0 && $this->maxRecords !== PHP_INT_MAX
                        && ($this->currentCount + $newRecordsCount) >= $this->maxRecords) {
                        $this->error($absoluteRow, __('plans.limit_records_reached', ['max' => $this->maxRecords]), $serial ?? '—');
                        continue;
                    }

                    $attrs['created_by'] = Auth::id();
                    Transformer::create(array_filter($attrs, fn ($v) => $v !== null)); // tenant_id lo auto-fillea el trait

                    $newRecordsCount++;
                    $this->created++;
                    $this->preview[] = ['row' => $absoluteRow, 'serial' => $serial, 'action' => 'created'];
                }
            }

            $this->dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Resuelve FKs + numéricos + enums + fecha de una fila. Devuelve
     * [attrs, error]: si error !== null, la fila ya fue rechazada (con su error
     * registrado) y debe saltarse.
     *
     * @return array{0: array<string,mixed>, 1: ?string}
     */
    protected function resolveAttributes(Collection $row, int $absoluteRow, ?string $serial): array
    {
        $attrs = [];

        // ── FKs (por code/name) ──
        $fks = [
            ['customer',         Customer::class,               'customer_id',                 ['cod', 'name'], true],
            ['oil_type',         OilType::class,                'oil_type_id',                 ['code', 'name'], false],
            ['transformer_type', TransformerType::class,        'transformer_type_id',         ['code', 'name'], false],
            ['brand',            Brand::class,                  'brand_id',                    ['code', 'name'], true],
            ['tap_changer_type', TapChangerType::class,         'tap_changer_type_id',         ['code', 'name'], false],
            ['connection_type',  ConnectionType::class,         'connection_type_id',          ['code', 'name'], false],
            ['preservation',     TransformerPreservation::class, 'transformer_preservation_id', ['code', 'name'], false],
        ];
        foreach ($fks as [$field, $model, $attrKey, $cols, $tenantScoped]) {
            $val = $this->normalizeName($row[$field] ?? null);
            if ($val === null) {
                continue;
            }
            $id = $this->resolveFk($model, $field, $val, $cols, $tenantScoped);
            if ($id === null) {
                $this->error($absoluteRow, __('imports.err_fk_not_found', ['field' => $field, 'value' => $val]), $serial ?? $val);
                return [$attrs, 'error'];
            }
            $attrs[$attrKey] = $id;
        }

        // ── Numéricos ──
        foreach (['voltage_kv', 'power_mva'] as $field) {
            $raw = $this->normalizeName($row[$field] ?? null);
            if ($raw === null) {
                continue;
            }
            $num = str_replace(',', '.', $raw);
            if (!is_numeric($num)) {
                $this->error($absoluteRow, __('imports.err_not_numeric', ['field' => $field, 'value' => $raw]), $serial ?? '—');
                return [$attrs, 'error'];
            }
            $attrs[$field] = (float) $num;
        }

        // ── Año de fabricación ──
        $year = $this->normalizeName($row['manufacture_year'] ?? null);
        if ($year !== null) {
            if (!ctype_digit($year) || (int) $year < 1900 || (int) $year > ((int) date('Y') + 1)) {
                $this->error($absoluteRow, __('imports.err_bad_year', ['value' => $year]), $serial ?? '—');
                return [$attrs, 'error'];
            }
            $attrs['manufacture_year'] = (int) $year;
        }

        // ── paper_type (kraft | upgraded) ──
        $paper = $this->normalizeName($row['paper_type'] ?? null);
        if ($paper !== null) {
            $p = mb_strtolower($paper);
            if (!in_array($p, ['kraft', 'upgraded'], true)) {
                $this->error($absoluteRow, __('imports.err_bad_paper', ['value' => $paper]), $serial ?? '—');
                return [$attrs, 'error'];
            }
            $attrs['paper_type'] = $p;
        }

        // ── phases (1|2|3) ──
        $phases = $this->normalizeName($row['phases'] ?? null);
        if ($phases !== null) {
            if (!in_array($phases, ['1', '2', '3'], true)) {
                $this->error($absoluteRow, __('imports.err_bad_phases', ['value' => $phases]), $serial ?? '—');
                return [$attrs, 'error'];
            }
            $attrs['phases'] = (int) $phases;
        }

        // ── oil_treated_at (fecha) ──
        $date = $this->normalizeName($row['oil_treated_at'] ?? null);
        if ($date !== null) {
            try {
                $attrs['oil_treated_at'] = Carbon::parse($date)->toDateString();
            } catch (\Throwable $e) {
                $this->error($absoluteRow, __('imports.err_bad_date', ['value' => $date]), $serial ?? '—');
                return [$attrs, 'error'];
            }
        }

        return [$attrs, null];
    }

    /** Resuelve un catálogo/cliente por sus columnas (code/name o cod/name). */
    protected function resolveFk(string $modelClass, string $field, string $value, array $cols, bool $tenantScoped): ?int
    {
        $ck = $field . '|' . $this->normalizeKey($value);
        if (array_key_exists($ck, $this->fkCache)) {
            return $this->fkCache[$ck];
        }

        $isPgsql = DB::getDriverName() === 'pgsql';
        $cmp = fn ($col) => $isPgsql
            ? "unaccent(LOWER({$col})) = unaccent(LOWER(?))"
            : "LOWER({$col}) = LOWER(?)";

        $q = $modelClass::query()->withoutGlobalScopes();
        if ($tenantScoped && $this->tenantId !== null) {
            // Per-tenant (Customer) o catálogo con tenant (Brand): del tenant o global.
            $q->where(fn ($w) => $w->where('tenant_id', $this->tenantId)->orWhereNull('tenant_id'));
        }
        $q->where(function ($w) use ($cmp, $cols, $value) {
            foreach ($cols as $col) {
                $w->orWhereRaw($cmp($col), [$value]);
            }
        });

        $id = $q->value('id');
        return $this->fkCache[$ck] = ($id !== null ? (int) $id : null);
    }

    protected function error(int $row, string $message, ?string $value = null): void
    {
        $this->errors[] = ['row' => $row, 'message' => $message, 'value' => $value];
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

    protected function normalizeName(mixed $value): ?string
    {
        if ($value === null) return null;
        $name = trim((string) $value);
        return $name === '' ? null : $name;
    }

    protected function normalizeKey(string $name): string
    {
        $lower    = mb_strtolower(trim($name));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }

    protected function findExistingBySerialInsensitive(string $serial): ?Transformer
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query   = Transformer::query()->withoutGlobalScopes();

        if ($this->tenantId !== null) {
            $query->where('transformers.tenant_id', $this->tenantId);
        }

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(transformers.serial)) = unaccent(LOWER(?))', [$serial]);
        } else {
            $query->whereRaw('LOWER(transformers.serial) = LOWER(?)', [$serial]);
        }

        return $query->first();
    }
}
