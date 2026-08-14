<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El documento, la cara y el consentimiento dejan de estar en claro en la base.
 *
 * EL AGUJERO
 * ----------
 * No habia una sola columna cifrada. Quien abriera un backup —el `.sql` de la
 * noche, la copia que se baja para probar algo en local, el disco del droplet—
 * leia los DNI de 14 000 personas, los 128 numeros de cada cara enrolada y el
 * texto de consentimiento que cada uno acepto. Un descriptor facial no se puede
 * cambiar como se cambia una contraseña: es la cara de esa persona para
 * siempre.
 *
 * QUE HACE ESTA MIGRACION
 * -----------------------
 * Solo prepara el sitio. **No cifra ni una fila**: eso lo hace
 * `php artisan docufiz:cifrar-datos-sensibles`, que va por lotes y se puede
 * repetir. Separarlo es deliberado — cifrar 14 000 filas dentro de una
 * migracion deja la tabla bloqueada un rato largo y, si se corta a la mitad,
 * `migrate` no sabe volver atras.
 *
 * LOS TRES CAMBIOS DE FORMA
 * -------------------------
 *   1. `people.num_doc` y `person_biometrics.face_descriptor` pasan a `text`.
 *      Un `Crypt::encryptString('47019236')` son unos 240 caracteres, o sea que
 *      un `varchar(255)` cabe por los pelos y revienta con el primer pasaporte
 *      largo. Y el descriptor era `json`: Postgres rechaza un base64 ahi, que
 *      no es JSON valido.
 *
 *   2. `people.num_doc_hash`, el indice ciego. Es lo que permite seguir
 *      buscando por documento sin descifrar la tabla entera en cada busqueda.
 *      El por que del HMAC y de la normalizacion esta en
 *      `App\Support\DocumentoBuscable`.
 *
 *   3. El indice unico del documento se muda al hash. Era
 *      `(tenant_id, country_id, doc_type, num_doc)` y con la columna cifrada no
 *      valdria para nada: el mismo DNI cifrado dos veces da dos textos
 *      distintos —el IV es aleatorio— asi que la base dejaria entrar duplicados
 *      del mismo trabajador sin rechistar. Sobre el hash sigue funcionando,
 *      porque el hash si es determinista.
 *
 * El indice normal sobre `num_doc` se retira: un indice sobre texto cifrado no
 * responde a ninguna consulta y solo cuesta escrituras.
 *
 * `consent_text` ya era `text` y nunca estuvo indexado, asi que no necesita
 * ningun cambio de forma: le basta con el cast del modelo.
 *
 * POR QUE LOS CAMBIOS DE TIPO SOLO SE APLICAN EN POSTGRES
 * -------------------------------------------------------
 * SQLite —el motor de las pruebas— no tiene tipos de columna de verdad: guarda
 * lo que le des en cualquier columna. Pedirle un cambio de tipo obliga a
 * Laravel a rehacer la tabla entera copiando filas, indices y claves ajenas, y
 * eso es un riesgo real a cambio de cero beneficio.
 */
return new class extends Migration
{
    public function up(): void
    {
        $esPostgres = DB::getDriverName() === 'pgsql';

        Schema::table('people', function (Blueprint $table) {
            // 64 caracteres: un HMAC-SHA256 en hexadecimal, siempre. `char` y no
            // `string` porque el largo es fijo y asi la base lo dice.
            //
            // Nullable a proposito y no `default ''`: mientras el comando de
            // migracion no haya pasado, una fila sin hash es una fila sin
            // migrar, y eso hay que poder contarlo. Un valor por defecto las
            // haria indistinguibles de las ya hechas y ademas chocaria contra
            // el indice unico en cuanto hubiera dos.
            $table->char('num_doc_hash', 64)->nullable()->after('num_doc')
                ->comment('HMAC-SHA256 del documento normalizado: es por donde se busca, ver App\Support\DocumentoBuscable');
        });

        // El indice sobre el documento en claro ya no responde a nada.
        // `dropIndexIfExists` no existe, y en una base que venga de un `migrate`
        // parcial el indice puede no estar: se intenta y se sigue.
        try {
            Schema::table('people', fn (Blueprint $table) => $table->dropIndex('people_num_doc_index'));
        } catch (\Throwable) {
            // Ya no estaba. No hay nada que arreglar.
        }

        Schema::table('people', function (Blueprint $table) {
            $table->index('num_doc_hash', 'idx_people_num_doc_hash');
        });

        if (! $esPostgres) {
            return;
        }

        DB::statement('ALTER TABLE people ALTER COLUMN num_doc TYPE text');
        DB::statement('ALTER TABLE person_biometrics ALTER COLUMN face_descriptor TYPE text USING face_descriptor::text');

        // La unicidad del documento se muda al hash. Se tira la vieja PRIMERO:
        // dejar las dos convive mal — la de `num_doc` no rechazaria nada (cada
        // cifrado es distinto) pero seguiria costando una escritura por fila y,
        // peor, daria la impresion de que la regla sigue en pie.
        DB::statement('DROP INDEX IF EXISTS people_document_unique_active');
        DB::statement(
            'CREATE UNIQUE INDEX people_document_unique_active '
            . 'ON people (tenant_id, country_id, doc_type, num_doc_hash) WHERE deleted_at IS NULL',
        );
    }

    /**
     * Volver atras devuelve la FORMA, no el contenido.
     *
     * Los valores se quedan cifrados, y sin `num_doc_hash` no se puede buscar
     * por documento. O sea que revertir esto **no** devuelve una base
     * funcionando: es la salida de emergencia de un despliegue que se tuerce
     * antes de correr el comando, no una vuelta atras despues.
     *
     * Los tipos tampoco se devuelven a `varchar` y `json`, y no es un olvido:
     * un valor cifrado no cabe en el uno ni es JSON valido para el otro, asi
     * que el `ALTER` fallaria a media vuelta atras. `text` los admite igual.
     *
     * Descifrar aqui seria peor: obligaria a meter el `APP_KEY` en una
     * migracion y dejaria los 14 000 DNI en claro justo cuando alguien esta
     * deshaciendo cosas a toda prisa.
     */
    public function down(): void
    {
        $esPostgres = DB::getDriverName() === 'pgsql';

        if ($esPostgres) {
            DB::statement('DROP INDEX IF EXISTS people_document_unique_active');
        }

        try {
            Schema::table('people', fn (Blueprint $table) => $table->dropIndex('idx_people_num_doc_hash'));
        } catch (\Throwable) {
            // No estaba.
        }

        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('num_doc_hash');
            $table->index('num_doc', 'people_num_doc_index');
        });

        if (! $esPostgres) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX people_document_unique_active '
            . 'ON people (tenant_id, country_id, doc_type, num_doc) WHERE deleted_at IS NULL',
        );
    }
};
