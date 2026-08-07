<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\Automations\Support\FilterApplier;
use Tests\TestCase;

/**
 * Conector POR CLÁUSULA del query builder (advanced_where, estilo RENATI): cada
 * cláusula (menos la 1ª) lleva su propio 'conj' ('and'|'or'). Verifica a nivel
 * SQL que el OR une las cláusulas con OR y el AND las encadena, con la
 * precedencia natural (AND antes que OR). No toca BD (solo inspecciona toSql()).
 */
class FilterApplierConjunctionTest extends TestCase
{
    private array $schema = [
        ['key' => 'name', 'type' => 'string'],
        ['key' => 'cod',  'type' => 'string'],
    ];

    public function test_or_joins_clauses_with_or(): void
    {
        $clauses = [
            ['field' => 'name', 'op' => 'contains', 'value' => 'a'],
            ['field' => 'cod',  'op' => '=',        'value' => 'X', 'conj' => 'or'],
        ];
        $q = Customer::query();
        FilterApplier::apply($q, ['where' => $clauses], $this->schema);
        $sql = strtolower($q->toSql());

        // Las 2 cláusulas quedan en un grupo unido por OR: "(... or ...)".
        $this->assertMatchesRegularExpression('/\(.*ilike.*or.*=.*\)/s', $sql, "El conj 'or' no unió las cláusulas con OR. SQL: {$sql}");
    }

    public function test_and_chains_clauses_without_or(): void
    {
        $clauses = [
            ['field' => 'name', 'op' => 'contains', 'value' => 'a'],
            ['field' => 'cod',  'op' => '=',        'value' => 'X', 'conj' => 'and'],
        ];
        $q = Customer::query();
        FilterApplier::apply($q, ['where' => $clauses], $this->schema);
        $sql = strtolower($q->toSql());

        // En AND no debe aparecer un " or " entre las cláusulas del filtro.
        $this->assertStringNotContainsString(' or ', $sql, "El conj 'and' no debería introducir OR. SQL: {$sql}");
    }

    public function test_default_clause_is_and(): void
    {
        // Sin 'conj' en la 2ª cláusula → default 'and'.
        $clauses = [
            ['field' => 'name', 'op' => 'contains', 'value' => 'a'],
            ['field' => 'cod',  'op' => '=',        'value' => 'X'],
        ];
        $q = Customer::query();
        FilterApplier::apply($q, ['where' => $clauses], $this->schema);
        $this->assertStringNotContainsString(' or ', strtolower($q->toSql()));
    }

    public function test_mixed_precedence_and_before_or(): void
    {
        // A contains a  AND  cod = X  OR  name contains z
        // = (A AND B) OR C por la precedencia natural de SQL.
        $clauses = [
            ['field' => 'name', 'op' => 'contains', 'value' => 'a'],
            ['field' => 'cod',  'op' => '=',        'value' => 'X', 'conj' => 'and'],
            ['field' => 'name', 'op' => 'contains', 'value' => 'z', 'conj' => 'or'],
        ];
        $q = Customer::query();
        FilterApplier::apply($q, ['where' => $clauses], $this->schema);
        $sql = strtolower($q->toSql());

        // El grupo lleva AND y OR (precedencia SQL: AND liga antes que OR).
        $this->assertStringContainsString(' and ', $sql, "Falta el AND del grupo. SQL: {$sql}");
        $this->assertStringContainsString(' or ',  $sql, "Falta el OR del grupo. SQL: {$sql}");
    }
}
