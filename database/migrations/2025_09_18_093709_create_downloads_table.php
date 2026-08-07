<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();

            // Tipo de archivo generado.
            $table->enum('type', ['pdf', 'excel', 'word', 'csv']);

            $table->string('filename');
            $table->string('path');
            $table->string('disk')->default('local');

            $table->foreignId('user_id')->constrained();

            $table->enum('status', ['pending', 'processing', 'ready', 'expired', 'failed'])->default('pending');
            $table->text('error_message')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
