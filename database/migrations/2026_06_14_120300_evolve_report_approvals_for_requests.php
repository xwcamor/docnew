<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las aprobaciones pasan a colgar de la SOLICITUD (report_request), no de cada
 * informe: el firmante aprueba el lote UNA vez. Se agrega report_request_id +
 * relation (Aprobado/Revisado/…); report_instance_id queda nullable (ya no se
 * usa en el flujo batch, pero no se borra por compatibilidad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('report_request_id')->nullable()->index()->after('id');
            $table->string('relation', 20)->nullable()->after('title');
        });

        // El flujo batch ya no exige report_instance_id; las filas viejas lo
        // mantienen, las nuevas usan report_request_id.
        Schema::table('report_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('report_instance_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('report_approvals', function (Blueprint $table) {
            $table->dropColumn(['report_request_id', 'relation']);
        });
    }
};
