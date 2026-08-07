<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuelga cada informe (report_instance) de una solicitud (report_request).
 * Una solicitud agrupa N instancias (1 trafo o flota completa). Nullable para
 * no romper instancias previas; las nuevas siempre llevan request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('report_request_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('report_instances', function (Blueprint $table) {
            $table->dropColumn('report_request_id');
        });
    }
};
