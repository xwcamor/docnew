<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Híbrido por tenant: un umbral de fisicoquímico (param × aceite × clase de
 * tensión) puede ser GLOBAL (tenant_id null) o PROPIO de un tenant (override de
 * esa celda). loadFromDb arma la base global y le superpone los del tenant.
 * Aditivo: filas existentes quedan globales → comportamiento idéntico.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('fiqui_thresholds', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
        });
        Schema::table('fiqui_thresholds', function (Blueprint $table) {
            $table->dropUnique('fiqui_thr_unique');
            $table->unique(['param_key', 'oil_code', 'voltage_class', 'tenant_id'], 'fiqui_thr_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fiqui_thresholds', function (Blueprint $table) {
            $table->dropUnique('fiqui_thr_unique');
            $table->unique(['param_key', 'oil_code', 'voltage_class'], 'fiqui_thr_unique');
            $table->dropColumn('tenant_id');
        });
    }
};
