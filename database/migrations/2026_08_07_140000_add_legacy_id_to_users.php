<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los usuarios de la aplicacion tambien vienen del sistema anterior.
 *
 * La tabla `users` de la v1 se excluyo del volcado porque llevaba contrasenas,
 * asi que los usuarios se reconstruyen desde `user_details`. El `legacy_id` es
 * lo que permite despues resolver `plans.user_id` contra el usuario correcto.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('is_active');
            $table->index('legacy_id', 'idx_users_legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_legacy_id');
            $table->dropColumn('legacy_id');
        });
    }
};
