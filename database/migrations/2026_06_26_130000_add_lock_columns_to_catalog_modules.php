<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lock por registro (trait Lockable) para los catálogos de negocio per-tenant:
 * marcas, laboratorios y el árbol del conmutador (marcas/modelos/tecnologías).
 * Mismo patrón que customers/transformers. Aditivo.
 */
return new class extends Migration {
    private array $tables = [
        'brands', 'laboratories',
        'tap_changer_brands', 'tap_changer_models', 'tap_changer_technologies',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasColumn($name, 'locked_at')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->timestamp('locked_at')->nullable()->index();
                    $table->unsignedBigInteger('locked_by')->nullable();
                    $table->string('lock_scope', 10)->nullable();
                });
            }
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
