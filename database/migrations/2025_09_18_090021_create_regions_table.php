<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('name');  // Ej: América del Sur

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Performance indexes — listado read-heavy (90/10).
            $table->index('is_active',                       'idx_regions_is_active');
            $table->index('created_at',                      'idx_regions_created_at');
            $table->index('updated_at',                      'idx_regions_updated_at');
            $table->index(['is_active', 'created_at'],       'idx_regions_is_active_created_at');
            $table->index('created_by',                      'idx_regions_created_by');
            $table->index('deleted_at',                      'idx_regions_deleted_at');
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Unique parcial accent + case insensitive — sella la 3ra capa del
            // duplicate detection. Concurrent inserts "Río"/"Rio" colisionan aquí.
            DB::statement(
                "CREATE UNIQUE INDEX regions_name_unique_active " .
                "ON regions (unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );

            // varchar_pattern_ops para LIKE 'X%' eficiente (no para sort).
            DB::statement('CREATE INDEX idx_regions_name_pattern ON regions (name varchar_pattern_ops)');
            // btree estándar para ORDER BY name (collation default).
            DB::statement('CREATE INDEX idx_regions_name ON regions (name)');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX regions_name_unique_active " .
                "ON regions (LOWER(name)) WHERE deleted_at IS NULL"
            );
            DB::statement('CREATE INDEX idx_regions_name ON regions (name)');
        } else {
            Schema::table('regions', function (Blueprint $table) {
                $table->unique('name', 'regions_name_unique_active');
                $table->index('name', 'idx_regions_name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
