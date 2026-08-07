<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Validates that a column value is unique in a table using accent + case
 * insensitive comparison (matching the same pattern that scopeFilter uses
 * for searches), and IGNORING soft-deleted rows.
 *
 * On PostgreSQL we use unaccent(lower(...)) which requires the `unaccent`
 * extension to be installed (already required by the project).
 * On other drivers we fall back to LOWER(...) — accent insensitivity needs
 * to come from the column collation in that case.
 *
 * Usage:
 *   new UniqueNormalizedName('regions', 'name', ignoreId: $region->id)
 */
class UniqueNormalizedName implements ValidationRule
{
    public function __construct(
        protected string $table,
        protected string $column = 'name',
        protected ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;  // 'required' rule handles this; we don't double-fail.
        }

        $isPgsql = DB::getDriverName() === 'pgsql';
        $needle  = trim((string) $value);

        $query = DB::table($this->table)->whereNull('deleted_at');

        if ($isPgsql) {
            // Uses the unaccent extension directly. We intentionally don't wrap
            // it in an IMMUTABLE function — a separate plain LOWER() index at
            // the DB level handles race conditions for the case-insensitive
            // half (the most common collision in practice). The accent half is
            // enforced here at the app layer.
            $query->whereRaw(
                "unaccent(LOWER({$this->column})) = unaccent(LOWER(?))",
                [$needle]
            );
        } else {
            $query->whereRaw(
                "LOWER({$this->column}) = LOWER(?)",
                [$needle]
            );
        }

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail(__('regions.name_unique'));
        }
    }
}
