<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivote usuario ↔ clientes permitidos. La 3ª capa de acceso (sobre tenant):
 * dentro de un workspace, un usuario puede quedar restringido a ciertos
 * clientes. SIN filas para un usuario = ve todo su tenant (comportamiento
 * actual; nadie existente se ve afectado). CON filas = solo esos clientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user');
    }
};
