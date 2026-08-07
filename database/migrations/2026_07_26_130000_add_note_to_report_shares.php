<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota libre por enlace. Nace para los enlaces SIN destinatario: los reparte el
 * usuario por su cuenta, así que el sistema no sabe a quién le llegaron. La nota
 * deja esa constancia ("se lo pasé a Juan por WhatsApp"). Vale para todos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->string('note', 250)->nullable()->after('recipient_email');
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
