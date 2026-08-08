<?php

namespace App\Services\FieldWork;

use App\Models\User;
use App\Models\WorkPlan;
use Illuminate\Support\Str;

/**
 * Todos los papeles de un plan en un ZIP.
 *
 * Es el `plan_exports_controller` del sistema anterior. Alli el plan se
 * descargaba entero cuando habia que mandarlo fuera —al cliente, a una
 * inspeccion— y bajarlo formato por formato no vale: son cuatro clics y cuatro
 * archivos sueltos que hay que volver a juntar.
 *
 * Dos cosas se hacen distinto que alla, y las dos a proposito:
 *
 *   1. **Van los cuatro formatos, no dos.** La v1 metia en el ZIP solo el AST y
 *      el PTF (`f1_pdf_url`, `f2_pdf_url`); el EPP y el IHM tenian su PDF pero
 *      se quedaban fuera. No hay ninguna razon escrita para eso y quien pide el
 *      expediente de una jornada los quiere los cuatro.
 *
 *   2. **Los PDF se generan aqui dentro.** La v1 hacia `system("curl ...")`
 *      contra su propia URL, pasandole la cookie de sesion del usuario: un
 *      proceso por PDF, la sesion viajando por la linea de comandos y un fallo
 *      silencioso —`return nil`— si curl no traia nada, con lo que el ZIP salia
 *      incompleto sin avisar. Aqui se llama al servicio que ya existe.
 *
 * Solo entran los formatos **confirmados**. Un borrador es un documento a
 * medias con firmas que todavia pueden cambiar, y no es lo que se manda fuera.
 */
class WorkPlanExportService
{
    public function __construct(private FormSubmissionPdfService $pdf) {}

    /**
     * Que entregas entrarian en el ZIP. Vacio significa que no hay nada que
     * descargar, y quien llama tiene que decirlo en vez de mandar un ZIP vacio.
     */
    public function exportables(WorkPlan $plan): \Illuminate\Support\Collection
    {
        return $plan->submissions()
            ->where('status', 'confirmed')
            ->with('formTemplate')
            ->get()
            ->sortBy(fn ($e) => $e->formTemplate?->code ?? '')
            ->values();
    }

    /**
     * Escribe el ZIP en un archivo temporal y devuelve su ruta.
     *
     * A disco y no en memoria: cuatro PDF con fotos de evidencia pueden ser
     * decenas de megas, y el ZIP se manda con `deleteFileAfterSend`, asi que el
     * temporal se va solo cuando termina la descarga.
     *
     * @throws \RuntimeException si no hay nada que exportar o el ZIP no se deja crear
     */
    public function zip(WorkPlan $plan, ?User $usuario = null): string
    {
        $entregas = $this->exportables($plan);

        if ($entregas->isEmpty()) {
            throw new \RuntimeException('El plan no tiene formatos confirmados que descargar.');
        }

        $ruta = tempnam(sys_get_temp_dir(), 'plan-') . '.zip';
        $zip = new \ZipArchive;

        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo ZIP.');
        }

        try {
            foreach ($entregas as $entrega) {
                // `output()` devuelve el PDF ya renderizado. Si uno reventara,
                // la excepcion sube: mejor no dar el ZIP que darlo sin un
                // documento y que nadie note el hueco, que es lo que pasaba con
                // el `return nil` de la v1.
                $zip->addFromString(
                    $this->pdf->nombreArchivo($entrega),
                    $this->pdf->generar($entrega, $usuario)->output(),
                );
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($ruta);

            throw $e;
        }

        $zip->close();

        return $ruta;
    }

    /** `OT-1234-formatos.zip`: el codigo del plan es como se le llama en obra. */
    public function nombreArchivo(WorkPlan $plan): string
    {
        return Str::slug(($plan->code ?: 'plan') . '-formatos') . '.zip';
    }
}
