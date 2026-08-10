<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los formatos, sus secciones y sus campos recuperan el nombre en los dos
 * idiomas.
 *
 * El motor nacio sabiendo codigos y nada mas: `form_templates.code` = «AST»,
 * `form_sections` solo tenia `position` y `form_fields` solo `code`. En
 * pantalla eso se traducia en una cabecera que decia «AST» —una sigla que hay
 * que saberse— sobre tarjetas sin titulo, y en etiquetas de campo sacadas de
 * humanizar el codigo: `matriz_de_riesgo` → «Matriz de riesgo». Sale legible de
 * chiripa, porque los codigos se escribieron en castellano; el dia que un campo
 * se llame `epp_por_trabajador` la etiqueta es «Epp por trabajador».
 *
 * El sistema anterior si tenia los nombres, y en tres idiomas: no en el codigo,
 * sino en la tabla `translations`, que es de donde `Document#translated_name`
 * sacaba «AST (Análisis de Seguridad en el Trabajo)».
 *
 * Aqui se guardan como columnas, que es como lo resuelve el resto del sistema
 * cuando el texto es un dato y no una cadena de interfaz —`approver_roles`,
 * `positions`— con su accesor `label` eligiendo por el idioma en curso. Un
 * formato lo crea el cliente desde la pantalla: su nombre no puede vivir en
 * `resources/lang`, porque ahi solo esta lo que trae el repositorio.
 *
 * `form_templates.name` se queda: lo usan `scopeFilter()` y las pantallas de
 * documentos que ya estan escritas. Pasa a ser el nombre por defecto —lo que se
 * enseña cuando no hay traduccion— y el seeder lo deja igual a `name_es`.
 *
 * Todas las columnas son nullable y ninguna lleva clave ajena, asi que SQLite
 * no tiene que reconstruir ninguna tabla: aqui no hace falta el guarda de
 * `getDriverName() !== 'sqlite'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_templates', function (Blueprint $table) {
            $table->string('name_es')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_es');
        });

        Schema::table('form_sections', function (Blueprint $table) {
            $table->string('name_es')->nullable()->after('form_template_id');
            $table->string('name_en')->nullable()->after('name_es');
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->string('label_es')->nullable()->after('code');
            $table->string('label_en')->nullable()->after('label_es');
        });
    }

    public function down(): void
    {
        Schema::table('form_templates', fn (Blueprint $t) => $t->dropColumn(['name_es', 'name_en']));
        Schema::table('form_sections', fn (Blueprint $t) => $t->dropColumn(['name_es', 'name_en']));
        Schema::table('form_fields', fn (Blueprint $t) => $t->dropColumn(['label_es', 'label_en']));
    }
};
