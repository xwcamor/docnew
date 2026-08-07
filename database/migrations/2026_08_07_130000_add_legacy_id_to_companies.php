<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de la migracion: cada empresa recuerda de que fila del sistema
 * anterior salio, para poder auditar y para que el comando sea idempotente.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('complete_name');
            $table->index('legacy_id', 'idx_companies_legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('idx_companies_legacy_id');
            $table->dropColumn('legacy_id');
        });
    }
};
