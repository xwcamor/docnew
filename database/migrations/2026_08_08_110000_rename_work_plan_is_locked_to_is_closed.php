<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `work_plans.is_locked` pasa a llamarse `is_closed`.
 *
 * Habia dos cosas distintas compartiendo nombre:
 *
 *   - la **columna**, que viene del sistema anterior y significa que el
 *     supervisor dio el plan por cerrado: 3 297 de 3 653 planes migrados;
 *   - el **candado administrativo** del trait `Lockable`, que un admin pone
 *     sobre un registro y se guarda en `locked_at`.
 *
 * Y el accesor del trait, `getIsLockedAttribute()`, tapaba la columna: devolvia
 * `locked_at !== null`, o sea `false` siempre. Resultado, esos 3 297 planes
 * cerrados se leian como abiertos y se podian seguir editando.
 *
 * Las dos cosas se quieren, asi que lo que sobra es el nombre repetido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('work_plans', 'is_locked') && ! Schema::hasColumn('work_plans', 'is_closed')) {
            Schema::table('work_plans', fn (Blueprint $t) => $t->renameColumn('is_locked', 'is_closed'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_plans', 'is_closed') && ! Schema::hasColumn('work_plans', 'is_locked')) {
            Schema::table('work_plans', fn (Blueprint $t) => $t->renameColumn('is_closed', 'is_locked'));
        }
    }
};
