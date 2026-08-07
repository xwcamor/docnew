<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_saved_views — bandeja per-user de "vistas guardadas" (filtros + columnas
 * + sort + per_page) por módulo. Patrón SAP Fiori.
 *
 * Cada vista es un snapshot del estado de un listado que el usuario nombra
 * y puede aplicar después con un click. El campo `module` la asocia al módulo
 * (regions, users, tenants, etc.) — la misma tabla sirve para TODOS los
 * listados sin necesidad de tablas por módulo.
 *
 * Constraints clave:
 *   - is_default UNIQUE per (user_id, module)  → solo UNA vista por usuario+módulo
 *     puede ser la default que se aplica al entrar.
 *   - state es JSON arbitrario; el shape lo define cada módulo (qué filtros
 *     tiene, qué columnas, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 60);   // regions, users, tenants, ...
            $table->string('name', 120);    // p.ej. "Mis activos"
            $table->boolean('is_default')->default(false);
            $table->json('state');          // { filters, columns, sort, direction, perPage }
            $table->timestamps();

            // Búsqueda eficiente por usuario+módulo (la consulta más común).
            $table->index(['user_id', 'module']);
        });

        // Constraint partial: solo UNA vista is_default por (user_id, module).
        // Postgres soporta partial unique index nativo. Para otros drivers se
        // enforce a nivel app (en SavedViewController::store/update).
        if (\DB::getDriverName() === 'pgsql') {
            \DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX user_saved_views_default_per_module
                    ON user_saved_views (user_id, module)
                 WHERE is_default = true
            SQL);
        }
    }

    public function down(): void
    {
        if (\DB::getDriverName() === 'pgsql') {
            \DB::statement('DROP INDEX IF EXISTS user_saved_views_default_per_module');
        }
        Schema::dropIfExists('user_saved_views');
    }
};
