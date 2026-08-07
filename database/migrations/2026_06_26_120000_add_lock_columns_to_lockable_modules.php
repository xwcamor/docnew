<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lock por registro (trait Lockable): congela un registro para que el usuario no
 * pueda editarlo/eliminarlo/importarlo/edit-all/bulk hasta desbloquearlo.
 *
 *   - locked_at  : timestamp del bloqueo (null = no bloqueado).
 *   - locked_by  : usuario que bloqueó (fk nullable, sin constraint, estilo proyecto).
 *   - lock_scope : 'tenant' (lo saca el admin) | 'super' (solo el super lo saca).
 *
 * Se agrega a los dos módulos driver (customers, transformers). Los catálogos
 * (marcas/modelos/labs/conmutador) quedan para un follow-up con el mismo patrón.
 * Aditivo.
 */
return new class extends Migration {
    private array $tables = ['customers', 'transformers'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('locked_at')->nullable()->index();
                $table->unsignedBigInteger('locked_by')->nullable();
                $table->string('lock_scope', 10)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['locked_at', 'locked_by', 'lock_scope']);
            });
        }
    }
};
