<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de bloqueo para los modulos que ya usan el trait `Lockable`.
 *
 * El trait, las rutas `lock`/`unlock` y los botones existian en Company,
 * Person, WorkPlan, FormTemplate y Brand, pero la migracion que crea las
 * columnas solo tocaba `customers`. Bloquear cualquiera de los otros cinco
 * era un error de columna inexistente.
 *
 * En DOCUFIZ el bloqueo sirve para algo concreto: un plan ya ejecutado y
 * firmado no se edita, y una plantilla de formato en uso tampoco.
 */
return new class extends Migration
{
    private array $tablas = ['companies', 'people', 'work_plans', 'form_templates', 'brands'];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'locked_at')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) {
                $t->timestamp('locked_at')->nullable()->index();
                $t->unsignedBigInteger('locked_by')->nullable();
                $t->string('lock_scope', 10)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'locked_at')) {
                continue;
            }

            Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn(['locked_at', 'locked_by', 'lock_scope']));
        }
    }
};
