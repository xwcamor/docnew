<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de apariencia por usuario (cross-device, en BD):
 *   - ui_scheme:    esquema de color (sap=default, slate, emerald, indigo, contrast).
 *   - nav_position: posición del menú (top=default, side, bottom).
 * Se comparten vía HandleInertiaRequests y las aplica AppLayout al cargar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ui_scheme', 20)->default('sap')->after('timezone');
            $table->string('nav_position', 10)->default('top')->after('ui_scheme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ui_scheme', 'nav_position']);
        });
    }
};
