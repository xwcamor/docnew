<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `legacy_id` en nacionalidades, para poder traerlas de la v1.
 *
 * El resto de catalogos de obra ya la tiene y esta no, porque nunca se migro:
 * `workers.nationality_id` es NOT NULL en el sistema anterior —los 391
 * trabajadores traen una— y aqui la tabla se quedo vacia y la columna de la
 * persona en nulo.
 *
 * Sin esta columna no hay forma de distinguir lo que trajo la migracion de lo
 * que se dio de alta a mano, que es lo que decide el candado (ver
 * `add_lock_columns_to_catalog_modules`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('nationalities', 'legacy_id')) {
            return;
        }

        Schema::table('nationalities', function (Blueprint $t) {
            $t->unsignedBigInteger('legacy_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('nationalities', 'legacy_id')) {
            return;
        }

        Schema::table('nationalities', fn (Blueprint $t) => $t->dropColumn('legacy_id'));
    }
};
