<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            // Sanctum tokens belong to a User (HasApiTokens) — el system_user es
            // el dueño de los tokens API emitidos para este workspace. FK se agrega
            // al final de create_users_table (dependencia circular tenants↔users).
            $table->unsignedBigInteger('system_user_id')->nullable();

            $table->string('slug', 22)->unique();
            $table->string('name');
            $table->string('logo')->nullable();

            // Membrete de informes (autoservicio del workspace).
            $table->string('address', 255)->nullable();
            $table->text('report_disclaimer')->nullable();
            // Aprobador "externo" (texto libre, legacy). El flujo real de firmas
            // vive en report_signers. Sin FK a propósito (SQLite reconstruye la
            // tabla al agregar FKs y rompe índices parciales; la integridad la
            // garantiza la validación + tolerancia a aprobador borrado).
            $table->string('report_approver', 120)->nullable();
            $table->unsignedBigInteger('report_approver_user_id')->nullable()->index();

            $table->boolean('is_active')->default(true);

            // TZ del workspace (ej. "America/Lima"). Los usuarios sin TZ propia
            // heredan esta. Si null, se autocompleta del country del creador.
            $table->string('timezone', 64)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        $driver = DB::getDriverName();

        // Índices del patrón master:
        //   - unaccent + case-insensitive unique sobre name (parcial, solo activos)
        //   - btree compuesto sobre is_active filtrado por deleted_at
        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX tenants_name_unique_active " .
                "ON tenants (unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );

            DB::statement('CREATE INDEX tenants_active_alive_idx ON tenants (is_active) WHERE deleted_at IS NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX tenants_name_unique_active " .
                "ON tenants (LOWER(name)) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
