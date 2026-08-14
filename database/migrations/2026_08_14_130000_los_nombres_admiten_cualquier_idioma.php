<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los nombres que escribe el cliente admiten cualquier idioma, no solo dos.
 *
 * EL ULTIMO SITIO DONDE «IDIOMA NUEVO» SIGNIFICABA «LLAMAR AL PROGRAMADOR»
 * ------------------------------------------------------------------------
 * Todo lo demas ya escala solo: los textos del producto se traducen creando
 * `lang/{idioma}`, y los catalogos de los formatos —respuestas, grupos,
 * bandas— aceptan el mapa `{es, en, pt, …}` desde `TextoTraducible`. Pero el
 * nombre de un campo, el titulo de una seccion, el nombre del formato y el del
 * rol aprobador vivian en parejas de columnas fijas (`label_es`/`label_en`,
 * `name_es`/`name_en`): el texto MAS visible de un formato era el unico atado a
 * exactamente dos idiomas, y el tercero pedia migracion de esquema.
 *
 * LA REGLA, PARA NO TENER DOS VERDADES
 * ------------------------------------
 * El espanol y el ingles SIGUEN viviendo en sus columnas — ni un dato existente
 * se toca, y todo lo que las lee en crudo (exports, filtros, ordenaciones)
 * sigue funcionando igual. La columna nueva guarda SOLO los idiomas que no
 * tienen columna propia, y el accessor funde las dos fuentes con las columnas
 * mandando en es/en. Asi no puede haber una copia rancia: cada idioma tiene
 * exactamente un sitio donde vivir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $tabla) {
            $tabla->json('label_i18n')->nullable()->after('label_en');
        });

        Schema::table('form_sections', function (Blueprint $tabla) {
            $tabla->json('name_i18n')->nullable()->after('name_en');
        });

        Schema::table('form_templates', function (Blueprint $tabla) {
            $tabla->json('name_i18n')->nullable()->after('name_en');
        });

        Schema::table('approver_roles', function (Blueprint $tabla) {
            $tabla->json('name_i18n')->nullable()->after('name_en');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', fn (Blueprint $tabla) => $tabla->dropColumn('label_i18n'));
        Schema::table('form_sections', fn (Blueprint $tabla) => $tabla->dropColumn('name_i18n'));
        Schema::table('form_templates', fn (Blueprint $tabla) => $tabla->dropColumn('name_i18n'));
        Schema::table('approver_roles', fn (Blueprint $tabla) => $tabla->dropColumn('name_i18n'));
    }
};
