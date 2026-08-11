<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reabrir un plan terminado, y que no se vuelva a cerrar solo.
 *
 * El cierre es automatico: `WorkPlanCompletionService` lo evalua en cada cambio
 * y en cuanto no falta nada, cierra. Eso esta bien y viene de la v1.
 *
 * El problema aparece al querer corregir algo de un plan ya terminado. Si
 * «reabrir» solo apagara `is_closed`, la siguiente evaluacion —el propio
 * guardado que viene detras— lo volveria a cerrar al instante, porque las
 * condiciones se siguen cumpliendo. Entras y te expulsa: reabrir seria un boton
 * que no sirve para nada.
 *
 * Por eso reabrir deja una marca. Mientras `reopened_at` este puesta, el cierre
 * automatico queda suspendido y el plan se queda en curso —diciendo quien lo
 * reabrio— hasta que alguien pulse «Dar por terminado», que la borra y vuelve a
 * evaluar.
 *
 * `reopened_by` no es adorno: un plan terminado es el documento que acaba
 * delante de un inspector, y que alguien lo haya vuelto a abrir despues es
 * exactamente lo que hay que poder explicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_plans', function (Blueprint $tabla) {
            $tabla->timestamp('reopened_at')->nullable()->after('is_closed');
            $tabla->unsignedBigInteger('reopened_by')->nullable()->after('reopened_at');
        });

        // La FK aparte: sqlite no sabe anadirla con ALTER y las pruebas corren
        // ahi. Sin ella la columna sigue siendo utilizable.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('work_plans', function (Blueprint $tabla) {
                $tabla->foreign('reopened_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('work_plans', function (Blueprint $tabla) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $tabla->dropForeign(['reopened_by']);
            }

            $tabla->dropColumn(['reopened_at', 'reopened_by']);
        });
    }
};
