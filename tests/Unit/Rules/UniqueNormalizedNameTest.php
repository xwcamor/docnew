<?php

namespace Tests\Unit\Rules;

use App\Models\Region;
use App\Rules\UniqueNormalizedName;
use Tests\Feature\SystemManagement\Regions\RegionTestCase;

/**
 * Unit tests para la regla UniqueNormalizedName. Bajo SQLite la regla cae al
 * fallback LOWER (case-insensitive sin acentos); los tests de acentos viven
 * en el suite Feature que requiere Postgres.
 */
class UniqueNormalizedNameTest extends RegionTestCase
{
    /** Helper: corre la rule y devuelve el mensaje de error o null. */
    private function runRule(string $value, ?int $ignoreId = null): ?string
    {
        $rule  = new UniqueNormalizedName('regions', 'name', $ignoreId);
        $error = null;
        $rule->validate('name', $value, function ($msg) use (&$error) {
            $error = $msg;
        });
        return $error;
    }

    public function test_passes_when_no_collision(): void
    {
        $error = $this->runRule('Mar del Plata');
        $this->assertNull($error);
    }

    public function test_returns_early_on_null_or_empty(): void
    {
        $this->assertNull($this->runRule(''));
        // El cast a string convierte null→'', pero igual lo testeamos por contrato.
        $rule  = new UniqueNormalizedName('regions', 'name');
        $error = null;
        $rule->validate('name', null, fn ($m) => $error = $m);
        $this->assertNull($error, 'Null debe pasar (lo agarra la rule "required")');
    }

    public function test_fails_on_exact_collision(): void
    {
        Region::factory()->create(['name' => 'Pampa']);

        $error = $this->runRule('Pampa');
        $this->assertNotNull($error);
    }

    public function test_fails_case_insensitively(): void
    {
        Region::factory()->create(['name' => 'Patagonia']);

        $this->assertNotNull($this->runRule('PATAGONIA'));
        $this->assertNotNull($this->runRule('patagonia'));
        $this->assertNotNull($this->runRule('PaTaGoNia'));
    }

    public function test_fails_trims_whitespace(): void
    {
        Region::factory()->create(['name' => 'Cuyo']);

        $this->assertNotNull($this->runRule('  Cuyo  '));
    }

    public function test_passes_when_only_match_is_soft_deleted(): void
    {
        // Soft-deleted no cuenta — partial unique index excluye deleted.
        Region::factory()->trashed()->create(['name' => 'NOA']);

        $this->assertNull($this->runRule('NOA'));
    }

    public function test_ignore_id_lets_same_record_keep_its_name(): void
    {
        $region = Region::factory()->create(['name' => 'Litoral']);

        // Update sobre sí mismo: debe pasar.
        $this->assertNull($this->runRule('Litoral', ignoreId: $region->id));
        // Otro registro con mismo nombre: sigue fallando.
        $this->assertNotNull($this->runRule('Litoral'));
    }
}
