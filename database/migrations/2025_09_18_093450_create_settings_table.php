<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings — editor key-value tipado para super.
 *
 * Columnas:
 *   - key (snake_case dotted)     → "app.maintenance_mode"
 *   - type (string|int|bool|json) → render condicional en UI + cast en lectura
 *   - value (text nullable)       → siempre serializado a string
 *   - group (nullable, indexed)   → "app", "exports", "features"
 *   - description (text nullable) → texto explicativo en form
 *   - is_secret (bool)            → masking en index + revelar on-click en form
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('key', 100)->nullable();
            $table->string('name');
            $table->string('type', 20)->default('string');
            $table->text('value')->nullable();
            $table->string('group', 60)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX settings_key_unique_active " .
                "ON settings (LOWER(key)) WHERE deleted_at IS NULL AND key IS NOT NULL"
            );
            DB::statement('CREATE INDEX settings_group_idx ON settings ("group") WHERE deleted_at IS NULL');
            DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_type_check CHECK (type IN ('string', 'int', 'bool', 'json'))");
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX settings_key_unique_active " .
                "ON settings (LOWER(key)) WHERE deleted_at IS NULL AND key IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
