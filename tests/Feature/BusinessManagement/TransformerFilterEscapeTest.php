<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Transformer;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Regresión del HY093 en Postgres: cuando serial + tag están activos a la vez,
 * el scopeFilter genera DOS cláusulas LIKE. Si esas cláusulas llevan un
 * `ESCAPE '\'` literal en raw SQL, el parser de placeholders de PDO (pgsql) se
 * descuadra y tira "Invalid parameter number". En Postgres el escape de LIKE ya
 * es backslash por defecto, así que la rama pgsql NO debe emitir ese literal.
 *
 * El test compila la query bajo config pgsql (sin ejecutarla) y verifica:
 *   - la rama pgsql se tomó (usa unaccent),
 *   - no hay ningún `ESCAPE '\'` en el SQL,
 *   - el nº de placeholders `?` coincide con el nº de bindings.
 */
class TransformerFilterEscapeTest extends TestCase
{
    public function test_pgsql_serial_and_tag_filters_emit_no_lone_backslash_escape(): void
    {
        config(['database.default' => 'pgsql']);

        $request = Request::create('/transformers', 'GET', [
            'serial' => ['tr'],
            'tag'    => 'aaa',
        ]);

        $query = Transformer::query()
            ->select('transformers.*')
            ->orderByFavoriteFirst(5)
            ->filter($request);

        $sql      = $query->toSql();
        $bindings = $query->getBindings();

        // Rama pgsql tomada.
        $this->assertStringContainsString('unaccent', $sql);

        // El literal problemático que rompe PDO no debe estar.
        $this->assertStringNotContainsString("ESCAPE '\\'", $sql);

        // Placeholders y bindings cuadran (lo que PDO necesita).
        $this->assertSame(substr_count($sql, '?'), count($bindings));
    }
}
