<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Híbrido por tenant: el SEMÁFORO (result_scales) de una prueba puede ser GLOBAL
 * (tenant_id null, de fábrica) o PROPIO de un tenant (sus bandas/colores/rating).
 * ResultScaleResolver prefiere las del tenant actual y cae a las globales.
 * Aditivo: filas existentes quedan globales (null) → comportamiento idéntico.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('result_scales', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('result_scales', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
