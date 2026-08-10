<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El representante de la cuadrilla sale del flujo de aprobaciones.
 *
 * Estaba como una fila mas de `work_plan_approvals`, con su regla de rol
 * `worker`, al lado del supervisor autorizante y el de seguridad. Y no es lo
 * mismo, por tres razones que estaban a la vista en el propio codigo:
 *
 *   · **Nunca recoge una firma.** `WorkPlanSetupService::assignApprover()` lo
 *     dice con todas las letras: «No se crea un evento nuevo. La evidencia es la
 *     que ya existe —su foto y su firma de trabajador de este mismo plan—, y
 *     duplicarla haria parecer que hubo dos comprobaciones donde hubo una». O
 *     sea: la fila es un puntero a alguien que ya firmo. Una aprobacion que
 *     nunca pide una firma no es una aprobacion.
 *
 *   · **Se podia borrar, y no debe.** El flujo es configurable —ese es su
 *     proposito— asi que alguien podia quitar la regla, volverla opcional o
 *     cambiarle el orden, y dejar un plan con cuadrilla y sin nadie que
 *     responda por ella.
 *
 *   · **Su selector es otro.** El autorizante sale del padron de personas; el
 *     representante sale de ESTA cuadrilla, hoy. Y no es un cargo ni un rol de
 *     la ficha: un electricista que va solo a la obra es el representante ese
 *     dia y uno mas al dia siguiente. Es del plan, no de la persona.
 *
 * Asi que pasa a ser una columna del plan. El flujo se queda con una sola idea
 * —que exige la empresa que contrata para autorizar el trabajo— y el
 * representante deja de depender de como este configurado.
 *
 * **No se pierde ninguna evidencia.** Las filas que se borran aqui no tenian
 * eventos de firma: la prueba —foto y firma de esa persona como trabajadora de
 * ese plan— vive en `work_plan_people` y no se toca. Se comprueba antes de
 * borrar; si alguna tuviera firma propia, la migracion se planta.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('crew_representative_person_id')->nullable()->after('company_id')
                ->comment('Quien responde por la cuadrilla. Sale de la propia cuadrilla, no del flujo.');
        });

        // Igual que en `tenants`: SQLite rehace la tabla para añadir una FK y
        // los indices parciales escritos a mano no sobreviven a la
        // reconstruccion. En Postgres si se pone.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('work_plans', function (Blueprint $table) {
                $table->foreign('crew_representative_person_id')->references('id')->on('people')->nullOnDelete();
            });
        }

        // Las aprobaciones de rol `worker` que ya existen, a la columna nueva.
        $deRepresentante = DB::table('work_plan_approvals as a')
            ->join('approval_rules as r', 'r.id', '=', 'a.approval_rule_id')
            ->where('r.approver_role', 'worker')
            ->select('a.id', 'a.work_plan_id', 'a.person_id')
            ->get();

        foreach ($deRepresentante as $fila) {
            if ($fila->person_id !== null) {
                DB::table('work_plans')->where('id', $fila->work_plan_id)
                    ->update(['crew_representative_person_id' => $fila->person_id]);
            }
        }

        // Antes de borrar, la comprobacion que sostiene todo lo de arriba: si
        // alguna llevara firma propia, el argumento se cae y hay que pararse.
        $conFirma = DB::table('signature_events')
            ->where('signable_type', \App\Models\WorkPlanApproval::class)
            ->whereIn('signable_id', $deRepresentante->pluck('id'))
            ->count();

        if ($conFirma > 0) {
            throw new RuntimeException(
                "Hay {$conFirma} aprobaciones de representante CON firma propia. "
                . 'Se daba por hecho que ninguna la tenia —la evidencia esta en la firma de '
                . 'trabajador— y no lo es. Revisar antes de seguir: borrarlas destruiria pruebas.'
            );
        }

        DB::table('work_plan_approvals')->whereIn('id', $deRepresentante->pluck('id'))->delete();

        // Y la regla desaparece del flujo, junto con el rol del catalogo: ya no
        // hay nada que pueda pedirlo.
        DB::table('approval_rules')->where('approver_role', 'worker')->delete();
        DB::table('approver_roles')->where('code', 'worker')->delete();
    }

    public function down(): void
    {
        Schema::table('work_plans', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['crew_representative_person_id']);
            }
            $table->dropColumn('crew_representative_person_id');
        });
    }
};
