<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Que dos filas VIVAS no puedan compartir codigo en nacionalidades ni en tipos
 * de documento.
 *
 * `document_types` traia `unique(['country_id', 'code', 'deleted_at'])`, que no
 * protege nada entre filas vivas: con `deleted_at` en nulo el motor considera
 * cada tupla distinta —NULL nunca es igual a NULL— asi que se podian dar de
 * alta «DNI» dos veces. `nationalities` no tenia ninguno.
 *
 * En tipos de documento esto duele mas que en el resto del catalogo:
 * `people.doc_type` guarda el TEXTO, asi que una persona dada de alta como
 * «dni» no la encuentra quien busca «DNI».
 *
 * Mismo criterio que la validacion de los FormRequest: por pais, por workspace
 * (los globales cuentan como su propio grupo, de ahi el COALESCE) y sin
 * distinguir mayusculas ni tildes. Solo entre filas vivas: borrar libera el
 * codigo. Es el patron que ya usan `companies` y `work_types` unas migraciones
 * mas atras.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los indices parciales con expresion son de Postgres. En SQLite —que
        // es donde corren las pruebas— manda la validacion del FormRequest,
        // que dice exactamente lo mismo y esta cubierta por pruebas propias.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Se va el viejo. Ojo: `$t->unique(...)` de Laravel crea una RESTRICCION
        // en Postgres, no un indice suelto, asi que `DROP INDEX` falla con
        // «dependent objects still exist». Hay que quitarla por su nombre.
        DB::statement(
            'ALTER TABLE document_types DROP CONSTRAINT IF EXISTS document_types_country_id_code_deleted_at_unique'
        );

        foreach (['nationalities', 'document_types'] as $tabla) {
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$tabla}_code_unique_active " .
                "ON {$tabla} (country_id, COALESCE(tenant_id, 0), unaccent_immutable(LOWER(code))) " .
                'WHERE deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS nationalities_code_unique_active');
        DB::statement('DROP INDEX IF EXISTS document_types_code_unique_active');
    }
};
