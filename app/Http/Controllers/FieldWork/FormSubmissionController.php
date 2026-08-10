<?php

namespace App\Http\Controllers\FieldWork;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\WorkPlan;
use App\Services\FieldWork\FormSubmissionPdfService;
use App\Services\FieldWork\FormSubmissionService;
use App\Services\FieldWork\WorkPlanExportService;
use Illuminate\Http\Request;

class FormSubmissionController extends Controller
{
    public function __construct(private readonly FormSubmissionService $formatos)
    {
    }

    /**
     * Los formatos que exige el plan, con su estado.
     *
     * La lista sale de `expectedFormTemplates()` y no del tipo de trabajo a
     * secas: el supervisor pudo sumarle un formato a este plan o quitarle uno
     * que no aplica. La pantalla de obra tiene que mostrar lo mismo que la
     * ficha del plan, o el que llena y el que arma no hablan del mismo trabajo.
     */
    public function index(Request $request, WorkPlan $work_plan)
    {
        $entregas = $work_plan->submissions()->get()->keyBy('form_template_id');

        return inertia('FieldWork/Forms', [
            'plan' => $work_plan->only(['slug', 'code', 'description']),
            'templates' => $work_plan->expectedFormTemplates()->map(fn ($item) => [
                'slug'   => $item['template']->slug,
                'code'   => $item['template']->code,
                'kind'   => $item['template']->kind,
                'required' => $item['is_required'],
                'status' => $entregas[$item['template']->id]->status ?? 'pending',
                // Slug de la entrega, para poder pedir su PDF. Solo existe
                // cuando el formato ya se abrio alguna vez.
                'submission' => $entregas[$item['template']->id]->slug ?? null,
            ])->values(),
            // El PDF saca el documento del sistema, asi que lo ve quien puede
            // exportar: el supervisor y el auditor, no el usuario de campo.
            'canExport' => (bool) $request->user()?->can('form_submissions.export'),
        ]);
    }

    /** Abre el formato para llenarlo. Congela la version de la plantilla. */
    public function open(Request $request, WorkPlan $work_plan, FormTemplate $form_template)
    {
        $entrega = $this->formatos->abrir($work_plan, $form_template, $request->user()->id);

        return inertia('FieldWork/FormFill', [
            'submission' => $entrega->only(['slug', 'status', 'template_version']),
            // El formato, la seccion y el campo llevan su nombre en columnas
            // (`name_es`/`name_en`, `label_es`/`label_en`) y el accesor `label`
            // elige por el idioma en curso. Se arma a mano en vez de mandar el
            // modelo entero porque un accesor no viaja solo en el JSON, y sin
            // el la pantalla se quedaba en la cadena de respaldo: cabecera
            // «AST», tarjetas sin titulo y etiquetas humanizadas del codigo.
            'template'   => [
                'code'  => $form_template->code,
                'label' => $form_template->label,
                'kind'  => $form_template->kind,
                'sections' => $form_template->sections()->with('fields')->get()->map(fn ($seccion) => [
                    'id'     => $seccion->id,
                    'label'  => $seccion->label,
                    'fields' => $seccion->fields->map(fn ($campo) => [
                        'id'          => $campo->id,
                        'code'        => $campo->code,
                        'label'       => $campo->label,
                        'field_type'  => $campo->field_type,
                        'is_required' => (bool) $campo->is_required,
                        'config'      => $campo->config ?? [],
                    ])->values(),
                ])->values(),
            ],
            'answers' => $entrega->answers()->get(),
            'missing' => $this->formatos->faltantes($entrega),
            // Los trabajadores del plan: el checklist de EPP es una fila por
            // trabajador, y esa lista no vive en la plantilla sino en el plan.
            // Es el `sync_f3_document_workers` de la v1, resuelto al pintar en
            // vez de con filas duplicadas en la base.
            'people'  => $work_plan->people()->with('person')->get()
                ->filter(fn ($asignado) => $asignado->person !== null)
                ->map(fn ($asignado) => [
                    'slug' => $asignado->person->slug,
                    'name' => $asignado->person->list_name,
                    // Enmascarado salvo permiso, como en el resto. Esta pantalla
                    // se llena en una tablet que pasa de mano en mano: es el
                    // peor sitio para tener veinte documentos a la vista.
                    'doc'  => $asignado->person->safe_num_doc,
                ])->values(),
        ]);
    }

    public function answer(Request $request, FormSubmission $form_submission)
    {
        $datos = $request->validate([
            'answers'          => ['required', 'array'],
            'answers.*.code'   => ['required', 'string'],
            'answers.*.row'    => ['nullable', 'integer', 'min:0'],
            // `value` TIENE que estar declarada, aunque no se le exija nada.
            //
            // `validate()` no devuelve la peticion: devuelve SOLO las claves que
            // aparecen en las reglas. Sin esta linea, cada respuesta llegaba al
            // servicio con su codigo y su fila y **sin el valor**, el servicio lo
            // leia como null, lo tomaba por un hueco y lo descartaba. O sea: no
            // se podia guardar nada, en ningun formato, y el guardado contestaba
            // «Respuestas guardadas» tan tranquilo.
            //
            // No saltaba en las pruebas porque todas llamaban al servicio a pelo,
            // saltandose el controlador. La de abajo entra por HTTP.
            //
            // Sin reglas de forma a proposito: el valor es texto, numero,
            // booleano, lista o el objeto de una fila de matriz segun el tipo de
            // campo, y quien sabe cual toca es `validarValor()` en el servicio,
            // que lee la configuracion del campo.
            'answers.*.value'  => ['nullable'],
        ]);

        $this->formatos->responder($form_submission, $datos['answers']);

        return back()->with('success', __('Respuestas guardadas.'));
    }

    /** El caso "HOJA X": subir la foto del papel. */
    public function attach(Request $request, FormSubmission $form_submission)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $archivo = $request->file('file');

        $this->formatos->adjuntar(
            $form_submission,
            $archivo->get(),
            $archivo->getMimeType(),
            $request->integer('form_field_id') ?: null,
        );

        return back()->with('success', __('Documento adjuntado.'));
    }

    /** Cierra el formato. El servidor comprueba que no falte nada. */
    public function confirm(FormSubmission $form_submission)
    {
        $this->formatos->confirmar($form_submission);

        return back()->with('success', __('Formato confirmado.'));
    }

    /**
     * El PDF firmado: el documento que la empresa conserva.
     *
     * Se genera al vuelo y no se guarda en disco. Guardarlo obligaria a
     * invalidarlo cada vez que se resuelve una firma pendiente, y lo que vale
     * es siempre el estado actual de la entrega, no una copia de ayer.
     */
    public function pdf(Request $request, FormSubmission $form_submission, FormSubmissionPdfService $pdf)
    {
        return $pdf->generar($form_submission, $request->user())
            ->download($pdf->nombreArchivo($form_submission));
    }

    /**
     * El expediente entero del plan en un ZIP.
     *
     * Es el `plan_exports_controller` de la v1, que era como se mandaba una
     * jornada fuera —al cliente, a una inspeccion—. Bajarla formato por formato
     * son cuatro clics y cuatro archivos sueltos que hay que volver a juntar.
     *
     * El temporal se borra al terminar la descarga (`deleteFileAfterSend`).
     */
    public function zip(Request $request, WorkPlan $work_plan, WorkPlanExportService $exportador)
    {
        try {
            $ruta = $exportador->zip($work_plan, $request->user());
        } catch (\RuntimeException) {
            // Sin formatos confirmados no hay expediente. Se vuelve con el
            // motivo, en vez de mandar un ZIP vacio que parece un fallo de la
            // descarga.
            return back()->with('error', __('work_plans.export_zip_empty'));
        }

        return response()->download($ruta, $exportador->nombreArchivo($work_plan), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend();
    }
}
