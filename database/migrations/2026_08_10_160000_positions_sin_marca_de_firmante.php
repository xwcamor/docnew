<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuera `positions.is_signature_approver`: no la lee nadie y ademas miente
 * sobre el modelo.
 *
 * La columna viene de la migracion de la v1 y se ha arrastrado hasta aqui sin
 * que nada dependa de ella. Se busco en todo el repo antes de tocarla: no la
 * consulta ningun selector, no la mira ninguna validacion, no sale en ningun
 * informe. Lo unico que hacia era pintarse: un interruptor en el formulario,
 * una etiqueta verde en el listado y otra en la ficha. Se marcaba, se guardaba,
 * y ahi se quedaba.
 *
 * Y esta en el sitio equivocado. Un cargo dice QUE HACE alguien en obra
 * —tecnico, capataz, electricista—, no si puede aprobar un plan de trabajo.
 * Quien aprueba lo dicen los roles de la persona, que es otra cosa y vive en
 * otra tabla. Mientras la marca estuvo ahi, la pantalla prometia una regla que
 * el servidor no aplicaba en ningun sitio: alguien podia desmarcar «puede
 * firmar aprobaciones» de un supervisor convencido de haberle quitado algo, y
 * no le quitaba nada.
 *
 * Un campo muerto que ademas miente sobre el modelo es peor que no tenerlo: el
 * dia que de verdad haga falta decidir quien aprueba, esta columna habria sido
 * el primer sitio donde mirar, y el equivocado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'is_signature_approver')) {
            return;
        }

        Schema::table('positions', fn (Blueprint $t) => $t->dropColumn('is_signature_approver'));
    }

    public function down(): void
    {
        if (Schema::hasColumn('positions', 'is_signature_approver')) {
            return;
        }

        // Vuelve como nacio —boolean, apagada por defecto— pero sin los valores
        // que tuviera: al borrarla se van con ella, y ninguna parte del sistema
        // los leia, asi que no hay nada que reconstruir.
        Schema::table('positions', function (Blueprint $t) {
            $t->boolean('is_signature_approver')->default(false);
        });
    }
};
