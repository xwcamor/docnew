<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los formatos y las reglas de aprobación recuperan su nombre.
 *
 * Los porté con `code` a secas —AST, EPP, IHM, PTF— y en el sistema anterior
 * cada uno tiene un nombre de verdad, que es lo que se leía en pantalla:
 *
 *     AST  → «AST (Análisis de Seguridad en el Trabajo)»
 *     PTF  → «Pare Tome 5»
 *     EPP  → «Inspección de EPP (Equipos de Protección de Seguridad)»
 *     IHM  → «Inspección de Herramientas Manuales y Eléctricas Portátiles»
 *
 * Con las aprobaciones fue peor: la v1 las llama «Supervisor Ejecutante»,
 * «Supervisor Autorizante - HITACHI» y «Supervisor de Seguridad - HITACHI», y yo
 * las reduje al rol genérico —Trabajador, Supervisor, Supervisor HSE—. Se pierde
 * justo lo que distingue una firma de otra: quién autoriza y por parte de quién.
 *
 * `form_templates.name` además ya lo daba por existente `FormTemplate::
 * scopeFilter()`, así que filtrar u ordenar por nombre reventaba con «column
 * name does not exist».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_templates', function (Blueprint $table) {
            $table->string('name')->nullable()->after('code');
        });

        Schema::table('approval_rules', function (Blueprint $table) {
            $table->string('name')->nullable()->after('slug');
        });

        // Lo que ya está migrado se queda sin nombre hasta que se vuelva a
        // correr el comando; mientras tanto la pantalla cae al código, que es
        // lo que enseñaba antes. Nada empeora.
    }

    public function down(): void
    {
        Schema::table('form_templates', fn (Blueprint $t) => $t->dropColumn('name'));
        Schema::table('approval_rules', fn (Blueprint $t) => $t->dropColumn('name'));
    }
};
