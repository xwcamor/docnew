<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toggle POR WORKSPACE del flujo de aprobación de informes (etapa 2 de firmas).
 * false (default) = el informe se genera y auto-firma como hoy. true = pasa por
 * borrador→aprobación. Cada admin lo decide en "Mi workspace".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('require_report_approval')->default(false)->after('report_approver_user_id');
        });

        // El flag dejó de ser una setting global → quitar la fila huérfana para
        // que no aparezca confundiendo en Configuración. No-op si no existe.
        \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'reports.require_approval')->delete();
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('require_report_approval');
        });
    }
};
