<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locales', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('code', 10);    // es_PE, es_VE, pt_BR
            $table->string('name');         // Español (Perú), ...
            $table->foreignId('language_id')->constrained();

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
                "CREATE UNIQUE INDEX locales_name_unique_active " .
                "ON locales (unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX locales_code_unique_active " .
                "ON locales (LOWER(code)) " .
                "WHERE deleted_at IS NULL"
            );
            DB::statement('CREATE INDEX locales_active_alive_idx ON locales (is_active) WHERE deleted_at IS NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX locales_name_unique_active " .
                "ON locales (LOWER(name)) WHERE deleted_at IS NULL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX locales_code_unique_active " .
                "ON locales (LOWER(code)) WHERE deleted_at IS NULL"
            );
        } else {
            Schema::table('locales', function (Blueprint $table) {
                $table->unique('name', 'locales_name_unique_active');
                $table->unique('code', 'locales_code_unique_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('locales');
    }
};
