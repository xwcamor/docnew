<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Árbol del conmutador en el transformador: además del tipo (DETC/OLTC, ya
 * existente en tap_changer_type_id), se registran marca + modelo (DETC y OLTC)
 * y tecnología (solo OLTC). FK nullable sin constraint (SQLite-friendly, estilo
 * proyecto) — integridad por las relaciones belongsTo del modelo. Aditivo.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->unsignedBigInteger('tap_changer_brand_id')->nullable()->after('tap_changer_type_id')->index();
            $table->unsignedBigInteger('tap_changer_model_id')->nullable()->after('tap_changer_brand_id')->index();
            $table->unsignedBigInteger('tap_changer_technology_id')->nullable()->after('tap_changer_model_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->dropColumn(['tap_changer_brand_id', 'tap_changer_model_id', 'tap_changer_technology_id']);
        });
    }
};
