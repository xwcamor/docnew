<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX per-tenant: los catálogos laboratories / tap_changer_brands / _models /
 * _technologies se clonaron del molde TapChangerType (GLOBAL) y quedaron sin
 * tenant_id ni unicidad por tenant — se compartían entre workspaces. Esto los
 * alinea con Brands (per-tenant): agrega tenant_id nullable + rehace el índice
 * único de nombre para que sea (tenant_id, name) en vez de global.
 *
 * tenant_id sin FK constraint (SQLite-friendly en ALTER, como el resto). El
 * trait BelongsToTenant en el modelo lo auto-asigna al crear y filtra el scope.
 */
return new class extends Migration {
    private array $tables = ['laboratories', 'tap_changer_brands', 'tap_changer_models', 'tap_changer_technologies'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        foreach ($this->tables as $t) {
            if (!Schema::hasColumn($t, 'tenant_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
                });
            }
            // Rehacer el unique de nombre: global → per-tenant.
            DB::statement("DROP INDEX IF EXISTS {$t}_name_unique_active");
            if ($driver === 'pgsql') {
                DB::statement(
                    "CREATE UNIQUE INDEX {$t}_name_unique_active " .
                    "ON {$t} (tenant_id, unaccent_immutable(LOWER(name))) WHERE deleted_at IS NULL"
                );
            } elseif ($driver === 'sqlite') {
                DB::statement(
                    "CREATE UNIQUE INDEX {$t}_name_unique_active " .
                    "ON {$t} (tenant_id, LOWER(name)) WHERE deleted_at IS NULL"
                );
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        foreach ($this->tables as $t) {
            DB::statement("DROP INDEX IF EXISTS {$t}_name_unique_active");
            if ($driver === 'pgsql') {
                DB::statement(
                    "CREATE UNIQUE INDEX {$t}_name_unique_active " .
                    "ON {$t} (unaccent_immutable(LOWER(name))) WHERE deleted_at IS NULL"
                );
            } elseif ($driver === 'sqlite') {
                DB::statement(
                    "CREATE UNIQUE INDEX {$t}_name_unique_active ON {$t} (LOWER(name)) WHERE deleted_at IS NULL"
                );
            }
            if (Schema::hasColumn($t, 'tenant_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('tenant_id'));
            }
        }
    }
};
