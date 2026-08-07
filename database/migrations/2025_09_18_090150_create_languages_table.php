<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('name');         // Español, English, ...
            $table->string('iso_code', 10); // es, en, pt, de

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Unique parcial accent + case insensitive sobre name.
            DB::statement(
                "CREATE UNIQUE INDEX languages_name_unique_active " .
                "ON languages (unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );

            // Unique parcial case-insensitive sobre iso_code.
            DB::statement(
                "CREATE UNIQUE INDEX languages_iso_code_unique_active " .
                "ON languages (LOWER(iso_code)) " .
                "WHERE deleted_at IS NULL"
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX languages_name_unique_active " .
                "ON languages (LOWER(name)) WHERE deleted_at IS NULL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX languages_iso_code_unique_active " .
                "ON languages (LOWER(iso_code)) WHERE deleted_at IS NULL"
            );
        } else {
            Schema::table('languages', function (Blueprint $table) {
                $table->unique('name',     'languages_name_unique_active');
                $table->unique('iso_code', 'languages_iso_code_unique_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
