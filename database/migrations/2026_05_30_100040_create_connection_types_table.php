<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * connection_types — catálogo global de tipos de conexión (grupo vectorial:
 * Dyn5, Dyn11, Yy0, etc.). Metadato descriptivo del transformador, NO eje de
 * diagnóstico. Sin módulo CRUD por ahora: se gestiona por seed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('connection_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('name')->index();
            $table->string('code')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('is_active', 'idx_connection_types_is_active');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX connection_types_name_unique_active " .
                "ON connection_types (unaccent_immutable(LOWER(name))) WHERE deleted_at IS NULL"
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX connection_types_name_unique_active " .
                "ON connection_types (LOWER(name)) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_types');
    }
};
