<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Híbrido por tenant: un dataset de diagnóstico (ieee_c57104_limits, fiquis_rules,
 * condition_labels, duval_zones, ratio_methods…) puede ser GLOBAL (tenant_id null,
 * el de fábrica) o PROPIO de un tenant (override). RuleData::get resuelve primero
 * el del tenant y cae al global. La unicidad pasa de (key) a (key, tenant_id) para
 * permitir un global + un override por tenant con la misma key.
 * Aditivo: filas existentes quedan globales (null) → comportamiento idéntico.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('diagnostic_datasets', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('key')->index();
        });
        Schema::table('diagnostic_datasets', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['key', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_datasets', function (Blueprint $table) {
            $table->dropUnique(['key', 'tenant_id']);
            $table->unique(['key']);
            $table->dropColumn('tenant_id');
        });
    }
};
