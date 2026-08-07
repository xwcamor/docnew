<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_recent_views — historial polimórfico de "últimos vistos" por usuario.
 *
 * Diseño:
 *   - Polimórfico (viewable_type + viewable_id) → mismo schema sirve a todos
 *     los módulos. Igual que user_favorites.
 *   - Unique (user_id, viewable_type, viewable_id) — re-ver el mismo registro
 *     hace UPDATE de viewed_at en lugar de INSERT (evita duplicados).
 *   - El cleanup mantiene solo los últimos N por usuario+módulo (lo hace un
 *     trigger de aplicación en el controller, no DB-level, para mantenerlo
 *     simple). Como mucho ~10 filas por (user, module), tabla pequeña.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_recent_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('viewable');
            $table->timestamp('viewed_at')->useCurrent();

            $table->unique(['user_id', 'viewable_type', 'viewable_id'], 'user_recent_views_unique');
            $table->index(['user_id', 'viewable_type', 'viewed_at'], 'user_recent_views_user_module_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recent_views');
    }
};
