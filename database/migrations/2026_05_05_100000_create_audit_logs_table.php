<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // WHO — null on user delete so logs are never lost.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // WHAT happened — created / updated / deleted / restored / login / logout / etc.
            $table->string('event', 40);

            // WHICH record — polymorphic pointer. auditable_type holds the model FQCN.
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // Friendly module label (regions, users, tenants…) for grouping/filtering UI.
            $table->string('module', 60)->nullable();

            // Snapshot of changes — null for create/delete events, populated on update.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // WHERE — request context
            $table->string('url', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Optional free-form note (e.g. deletion reason, custom annotation).
            $table->text('note')->nullable();

            // WHEN — only created_at; audit logs are append-only and never updated.
            $table->timestamp('created_at')->nullable();

            // Indexes for the common query patterns.
            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_idx');
            $table->index('user_id');
            $table->index('event');
            $table->index('module');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
