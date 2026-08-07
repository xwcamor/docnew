<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('name');
            $table->string('permission_key')->unique();  // "languages.index", etc.
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
                "CREATE UNIQUE INDEX system_modules_name_unique_active " .
                "ON system_modules (unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );
            DB::statement('CREATE INDEX system_modules_active_alive_idx ON system_modules (is_active) WHERE deleted_at IS NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX system_modules_name_unique_active " .
                "ON system_modules (LOWER(name)) WHERE deleted_at IS NULL"
            );
        } else {
            Schema::table('system_modules', function (Blueprint $table) {
                $table->unique('name', 'system_modules_name_unique_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_modules');
    }
};
