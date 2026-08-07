<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal de aviso de aprobaciones POR WORKSPACE: false (default) = solo in-app
 * (campana); true = además por correo. Respeta el setting global
 * notifications.email_enabled (si el correo está apagado a nivel plataforma,
 * no se manda aunque esté en true).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('notify_approval_by_email')->default(false)->after('require_report_approval');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('notify_approval_by_email');
        });
    }
};
