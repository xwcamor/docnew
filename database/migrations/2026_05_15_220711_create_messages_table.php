<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── messages ──────────────────────────────────────────────────────────
        // Canal de comunicacion super -> users. El super crea anuncios, avisos
        // o debates dirigidos a una audiencia: global, un tenant, o un user.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('subject', 200);
            $table->text('body'); // HTML del rich editor (TipTap)
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // audience_type: global | tenant | user
            $table->string('audience_type', 20);
            // audience_id null si global; tenant_id si tenant; user_id si user.
            $table->unsignedBigInteger('audience_id')->nullable();
            $table->boolean('allow_replies')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['audience_type', 'audience_id'], 'idx_messages_audience');
            $table->index('is_active', 'idx_messages_is_active');
            $table->index('published_at', 'idx_messages_published_at');
            $table->index('expires_at', 'idx_messages_expires_at');
            $table->index('created_by', 'idx_messages_created_by');
        });

        // ── message_recipients ────────────────────────────────────────────────
        // Resolucion materializada de la audiencia. Al publicar, el service
        // genera una fila por cada usuario destinatario. Asi el inbox del user
        // es una query directa sin recalcular audiencias en cada request.
        Schema::create('message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id'], 'uniq_recipients_message_user');
            $table->index(['user_id', 'read_at'], 'idx_recipients_user_read');
        });

        // ── message_replies ───────────────────────────────────────────────────
        // Respuestas planas (sin threading anidado). Solo se aceptan si el
        // mensaje padre tiene allow_replies = true.
        Schema::create('message_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('message_id', 'idx_replies_message_id');
            $table->index('user_id', 'idx_replies_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_replies');
        Schema::dropIfExists('message_recipients');
        Schema::dropIfExists('messages');
    }
};
