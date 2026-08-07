<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_favorites — favoritos polimórficos por usuario.
 *
 * Diseño:
 *   - Polimórfico (favoritable_type + favoritable_id) → un solo schema sirve
 *     a regiones, idiomas, tenants, users... cualquier modelo que queramos
 *     marcar con estrella.
 *   - Unique (user_id, favoritable_type, favoritable_id): un favorito por
 *     usuario por entidad — si toggle, lo borramos en lugar de actualizar.
 *   - Indexado por (user_id, favoritable_type) para listar rápido los
 *     favoritos de un usuario en un módulo.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('favoritable');  // crea favoritable_type + favoritable_id + index
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'favoritable_type', 'favoritable_id'], 'user_favorites_unique');
            $table->index(['user_id', 'favoritable_type'], 'user_favorites_user_module_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorites');
    }
};
