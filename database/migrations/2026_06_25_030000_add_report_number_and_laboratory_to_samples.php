<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de ensayo en TODAS las muestras: N° de informe del laboratorio
 * + laboratorio que lo emitió. Aditivo y nullable (las muestras existentes
 * quedan sin estos datos, no cambia el diagnóstico). laboratory_id sin FK
 * constraint (SQLite-friendly, como el resto del proyecto) — la integridad la
 * maneja la relación belongsTo del modelo.
 */
return new class extends Migration {
    private array $tables = ['chromatographicals', 'furanos', 'fiquis', 'fpots'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('report_number')->nullable()->after('sample_date');
                $table->unsignedBigInteger('laboratory_id')->nullable()->after('report_number')->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['report_number', 'laboratory_id']);
            });
        }
    }
};
