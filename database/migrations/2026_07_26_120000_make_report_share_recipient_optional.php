<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces sin destinatario: el usuario genera el enlace y lo manda por su
 * propio correo. Sin destinatario no hay a quién mandarle el OTP, así que ese
 * enlace abre con el token solo (ver PublicShareController::gate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->string('recipient_email', 190)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->string('recipient_email', 190)->nullable(false)->change();
        });
    }
};
