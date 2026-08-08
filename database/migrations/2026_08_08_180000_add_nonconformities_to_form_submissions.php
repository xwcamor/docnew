<?php

use App\Models\FormSubmission;
use App\Services\FieldWork\FormFindingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuantas no conformidades tiene un formato entregado.
 *
 * Es la columna `observations` del sistema anterior, que alli era un ENTERO: el
 * numero de casillas que salieron mal. Los cuatro formatos lo recalculaban solos
 * en cada guardado (`set_completed` / `recalculate_observations_and_confirmation`)
 * y era lo que el supervisor veia de un vistazo en la ficha del plan: «EPP: 3».
 *
 * Aqui no se puede reutilizar el nombre porque `form_submissions.observations`
 * ya existe y es OTRA cosa —texto libre, donde la migracion escribe los permisos
 * y herramientas adicionales del AST que la plantilla nueva no reproduce—. De
 * ahi el nombre nuevo, que ademas dice lo que es.
 *
 * Se recalcula, no se escribe a mano: ver FormFindingsService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('form_submissions', 'nonconformities')) {
            return;
        }

        Schema::table('form_submissions', function (Blueprint $t) {
            $t->unsignedInteger('nonconformities')->default(0);
        });

        // Los formatos que ya existen no van a volver a guardarse, asi que su
        // cuenta se queda en el 0 de la columna: un EPP de la v1 con tres
        // arneses en mal estado saldria limpio. Se calculan aqui, una vez.
        //
        // Por lotes y con las respuestas cargadas de golpe: son 3 712 planes con
        // 48 522 respuestas y de uno en uno serian miles de consultas.
        FormSubmission::with(['answers', 'formTemplate.fields'])
            ->chunkById(200, function ($entregas) {
                $cuenta = app(FormFindingsService::class);

                foreach ($entregas as $entrega) {
                    $cuenta->recalcular($entrega);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('form_submissions', 'nonconformities')) {
            return;
        }

        Schema::table('form_submissions', fn (Blueprint $t) => $t->dropColumn('nonconformities'));
    }
};
