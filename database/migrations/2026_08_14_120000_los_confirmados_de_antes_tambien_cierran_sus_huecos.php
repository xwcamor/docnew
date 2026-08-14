<?php

use App\Models\FormSubmission;
use App\Services\FieldWork\FormSubmissionService;
use Illuminate\Database\Migrations\Migration;

/**
 * Los checklists confirmados ANTES de la regla del relleno tambien la reciben.
 *
 * QUE PASO
 * --------
 * Desde hace poco, confirmar un EPP o un IHM escribe «No aplica» en toda
 * casilla que quedo en blanco (`cerrarLoSinMarcarComoNoAplica`): dejar sin
 * marcar ES la manera de decir que ese equipo no tocaba, y asi lo hacia
 * tambien la v1, que creaba una fila con su guion por cada item sin respuesta.
 *
 * Pero la regla corre AL confirmar, asi que todo lo confirmado antes de que
 * existiera se quedo con sus huecos — y el PDF, que distingue con razon el
 * hueco («? Sin responder»: nadie miro) del «no aplica» (una decision), los
 * imprime como lo que son. El dueño del producto lo vio en un IHM confirmado:
 * un documento cerrado no puede decir que nadie miro una casilla cuando la
 * regla vigente dice que ese blanco significa «no aplica».
 *
 * Es una migracion de datos y no un comando, a proposito: tiene que correr una
 * sola vez en cada instalacion, sin que nadie se acuerde de lanzarla — y
 * `setup:project` ya migra.
 *
 * SOLO LO CONFIRMADO. Un borrador con huecos es un borrador a medias: sus
 * blancos siguen siendo «sin responder» hasta que alguien confirme.
 */
return new class extends Migration
{
    public function up(): void
    {
        $servicio = app(FormSubmissionService::class);

        FormSubmission::query()
            ->where('status', 'confirmed')
            ->whereHas('formTemplate.fields', fn ($q) => $q->whereIn(
                'field_type', ['person_checklist', 'tool_checklist'],
            ))
            // En trozos: hay 14 435 entregas migradas. El servicio ya evita
            // reescribir filas que no cambian (`$tocado` por referencia), asi
            // que las que la v1 dejo completas —que son casi todas— solo se leen.
            ->chunkById(200, function ($entregas) use ($servicio) {
                foreach ($entregas as $entrega) {
                    $servicio->cerrarLoSinMarcarComoNoAplica($entrega);
                }
            });
    }

    /**
     * Sin vuelta atras: no hay como distinguir el «No aplica» que escribio esta
     * migracion del que alguien marco con el dedo, y borrar los dos seria
     * reescribir documentos firmados.
     */
    public function down(): void
    {
    }
};
