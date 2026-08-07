<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Híbrido por tenant (Fase 3): rule_sets puede ser GLOBAL (tenant_id null, el
 * estándar de fábrica IEC/IEEE) o PROPIO de un tenant (override). El motor
 * resuelve primero el del tenant del transformador, y si no hay, cae al global.
 * Aditivo: todas las filas existentes quedan en null (global) → comportamiento
 * idéntico al actual hasta que un tenant cree sus propias reglas.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
