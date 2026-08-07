<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('region_id')->constrained();
            $table->string('name');          // Perú
            $table->char('iso_code', 2);     // PE
            $table->string('currency', 3);   // PEN
            $table->string('timezone');      // America/Lima
            $table->foreignId('default_locale_id')->constrained('locales');

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
                "CREATE UNIQUE INDEX countries_name_unique_active " .
                "ON countries (unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX countries_iso_code_unique_active " .
                "ON countries (LOWER(iso_code)) " .
                "WHERE deleted_at IS NULL"
            );
            DB::statement('CREATE INDEX countries_name_btree_idx ON countries (LOWER(name))');
            DB::statement('CREATE INDEX countries_active_alive_idx ON countries (is_active) WHERE deleted_at IS NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX countries_name_unique_active " .
                "ON countries (LOWER(name)) WHERE deleted_at IS NULL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX countries_iso_code_unique_active " .
                "ON countries (LOWER(iso_code)) WHERE deleted_at IS NULL"
            );
        } else {
            Schema::table('countries', function (Blueprint $table) {
                $table->unique('name',     'countries_name_unique_active');
                $table->unique('iso_code', 'countries_iso_code_unique_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
