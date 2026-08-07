<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * comments — comentarios de usuario polimórficos.
 *
 * Se adjuntan a CUALQUIER modelo (transformer, o una muestra de cromas/furanos/
 * fiquis/fpot) vía `commentable_type` + `commentable_id`. El `context` separa
 * hilos sobre el mismo modelo (p.ej. notas del diagnosticador por prueba:
 * 'diag_furanos', comentarios generales: 'general', comentario de muestra: 'sample').
 *
 * El `body` es texto del usuario: se guarda y muestra TAL CUAL (no se traduce);
 * se muestra con el autor y la fecha. Tenant-scoped + auditado + soft-delete.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable'); // commentable_type + commentable_id (+ índice)
            $table->string('context', 60)->nullable()->index();
            $table->foreignId('user_id')->constrained('users'); // autor
            $table->text('body');
            $table->string('lang', 5)->nullable(); // idioma del comentario

            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['commentable_type', 'commentable_id', 'context'], 'comments_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
