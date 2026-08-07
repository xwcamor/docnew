<?php

namespace App\Imports\SystemManagement\Languages;

use App\Models\Language;
use App\Services\SystemManagement\LanguageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Languages from .xlsx/.csv.
 *
 * Columns:
 *   - name      (required, max 255)
 *   - iso_code  (required al crear; opcional al update, preserva el existente)
 *   - is_active (optional, boolean-ish)
 *
 * Modes:    'create_only' | 'update_or_create'
 *
 * 3-layer duplicate protection (aplica a `name` Y `iso_code`):
 *   1. In-file: normalizado catchea dupes en el mismo upload
 *   2. App: lookup case + accent insensitive contra DB
 *   3. DB: partial unique indexes para name (unaccent) y iso_code (lower),
 *      `WHERE deleted_at IS NULL`
 *
 * Todo va en transacción. dryRun=true → rollback al final (preview UI).
 */
class LanguagesImport implements ToCollection, WithHeadingRow
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
        protected ?LanguageService $service = null,
    ) {
        $this->service ??= app(LanguageService::class);
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file. Mapas separados para name y iso_code.
            $seenNames = [];
            $seenIsos  = [];

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2;

                // ── name ─────────────────────────────────────────────────────
                $name = $this->normalizeName($row['name'] ?? null);
                if ($name === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_name_required'), 'value' => '—'];
                    continue;
                }
                if (mb_strlen($name) > 255) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_name_too_long'), 'value' => mb_substr($name, 0, 60) . '…'];
                    continue;
                }

                $normName = $this->normalizeKey($name);
                if (isset($seenNames[$normName])) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_duplicate_in_file', ['row' => $seenNames[$normName]]), 'value' => $name];
                    continue;
                }
                $seenNames[$normName] = $absoluteRow;

                // ── iso_code ─────────────────────────────────────────────────
                $isoCode  = trim((string) ($row['iso_code'] ?? ''));
                $isActive = $this->normalizeBool($row['is_active'] ?? null, default: true);

                if ($isoCode !== '' && !preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $isoCode)) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('languages.iso_code_regex'), 'value' => $isoCode];
                    continue;
                }

                if ($isoCode !== '') {
                    $normIso = strtolower($isoCode);
                    if (isset($seenIsos[$normIso])) {
                        $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_duplicate_in_file', ['row' => $seenIsos[$normIso]]), 'value' => $isoCode];
                        continue;
                    }
                    $seenIsos[$normIso] = $absoluteRow;
                }

                // Layer 2: DB lookup case + accent insensitive (ignora soft-deleted).
                $existing = $this->findExistingByNameInsensitive($name);

                // Layer 2 bis: si el ISO viene en la fila, verificar que no exista en
                // otro registro activo distinto al que matchea por name. Si pega
                // contra otro, devolvemos error legible (en lugar de dejar que el
                // unique index de Postgres explote con un mensaje técnico).
                if ($isoCode !== '') {
                    $isoClash = $this->findExistingByIsoCode($isoCode);
                    if ($isoClash && (!$existing || $isoClash->id !== $existing->id)) {
                        $this->errors[] = [
                            'row'     => $absoluteRow,
                            'message' => __('languages.iso_code_unique'),
                            'value'   => $isoCode,
                        ];
                        continue;
                    }
                }

                if ($existing) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'       => $absoluteRow,
                            'name'      => $name,
                            'iso_code'  => $isoCode ?: $existing->iso_code,
                            'is_active' => $isActive,
                            'action'    => 'skipped',
                        ];
                        continue;
                    }
                    $patch = [];
                    if ($isoCode !== '' && $existing->iso_code !== $isoCode) $patch['iso_code'] = $isoCode;
                    if ($existing->is_active !== $isActive)                  $patch['is_active'] = $isActive;
                    if (!empty($patch)) {
                        $this->service->update($existing, $patch);
                    }
                    $this->updated++;
                    $this->preview[] = [
                        'row'       => $absoluteRow,
                        'name'      => $name,
                        'iso_code'  => $isoCode ?: $existing->iso_code,
                        'is_active' => $isActive,
                        'action'    => 'updated',
                    ];
                } else {
                    if ($isoCode === '') {
                        $this->errors[] = ['row' => $absoluteRow, 'message' => __('languages.iso_code_required'), 'value' => '—'];
                        continue;
                    }
                    $this->service->create([
                        'name'      => $name,
                        'iso_code'  => $isoCode,
                        'is_active' => $isActive,
                    ]);
                    $this->created++;
                    $this->preview[] = [
                        'row'       => $absoluteRow,
                        'name'      => $name,
                        'iso_code'  => $isoCode,
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

    /** Layer-2 lookup por iso_code (case-insensitive, ignora soft-deleted). */
    protected function findExistingByIsoCode(string $code): ?Language
    {
        return Language::query()
            ->whereRaw('LOWER(iso_code) = ?', [strtolower($code)])
            ->first();
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

    /** Lowercase + strip accents (iconv) — mismo pattern que el DB-level layer 2. */
    protected function normalizeKey(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }

    /** Lookup case + accent insensitive (Postgres unaccent / fallback LOWER). */
    protected function findExistingByNameInsensitive(string $name): ?Language
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query = Language::query();

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
