<?php

namespace App\Http\Controllers\FieldWork;

use App\Http\Controllers\Controller;
use App\Models\FormAttachment;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\WorkPlan;
use App\Services\FieldWork\FormSubmissionPdfService;
use App\Services\FieldWork\FormSubmissionService;
use App\Services\FieldWork\WorkPlanExportService;
use Illuminate\Http\RedirectResponse;
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

        $faltantes = $this->formatos->faltantesDetallados($entrega);

        // La automatizacion que pidio el dueño: ademas del catalogo fijo, los
        // textos ya escritos en entregas confirmadas de esta plantilla se
        // sugieren en los textos libres (actividad, peligro, riesgo, control,
        // herramienta). Se calculan una vez por apertura y viajan mezclados en
        // la config del campo; nada se persiste.
        $aprendidas = $this->formatos->sugerenciasAprendidas($form_template);

        return inertia('FieldWork/FormFill', [
            'submission' => $entrega->only(['slug', 'status', 'template_version']),
            // El plan del que cuelga el formato. Hace falta para poder SALIR:
            // la pantalla se abria y no habia manera de volverse sin confirmar
            // ni reabrir, y el slug de la entrega no sirve para reconstruir el
            // del plan. El codigo va para poder nombrarlo si algun dia hace
            // falta; hoy solo se usa el slug.
            'plan' => $work_plan->only(['slug', 'code']),
            // A donde lleva esa salida. La ficha del plan pide `work_plans.view`
            // y esta pantalla solo pide `form_submissions.view`: los perfiles
            // sembrados tienen los dos, pero un rol a medida puede no tenerlos,
            // y entonces el boton de volver seria un 403. Un boton que solo
            // puede fallar es peor que no tenerlo (docs/UI.md §6), asi que
            // cuando no se puede ver el plan se vuelve a la lista de formatos,
            // que pide justo el permiso con el que se llego hasta aqui.
            'canViewPlan' => (bool) $request->user()?->can('work_plans.view'),
            // Un formato confirmado se puede volver a abrir para corregirlo,
            // pero solo lo hace quien puede llenarlo —esta pantalla la abre
            // tambien el auditor, que solo mira— y solo mientras el plan siga
            // abierto. La regla la manda el servicio; esto es para no enseñar
            // un boton que va a decir que no.
            'canReopen' => $request->user()?->can('form_submissions.edit')
                && ! $work_plan->is_closed,
            // Lo que ya esta subido. Faltaba: se adjuntaba un archivo y la
            // pantalla no cambiaba en nada, asi que la unica forma de saber si
            // habia entrado era darle a confirmar y ver si se quejaba. Con
            // varios archivos por entrega eso deja de ser un detalle.
            'attachments' => $entrega->attachments()->orderBy('id')->get()->map(fn ($a) => [
                'id'       => $a->id,
                'name'     => $a->original_name,
                'mime'     => $a->mime_type,
                'size'     => (int) $a->byte_size,
                'field_id' => $a->form_field_id,
            ])->all(),
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
                        'config'      => $this->formatos->configConAprendidas(
                            $campo->field_type, $campo->config ?? [], $aprendidas,
                        ),
                    ])->values(),
                ])->values(),
            ],
            'answers' => $entrega->answers()->get(),
            // Lo que falta, dos veces y para dos lectores distintos: las
            // etiquetas van al aviso (se leen) y los codigos a la pantalla,
            // que con ellos marca en rojo cada campo pendiente. El adjunto
            // que falta no es un campo: sale en las etiquetas y no en los
            // codigos, porque no hay widget que marcar.
            'missing' => array_column($faltantes, 'label'),
            'missingCodes' => array_values(array_filter(array_column($faltantes, 'code'))),
            // Si ya hubo un intento de guardar. El marcado rojo de los campos
            // que faltan solo se enciende despues de intentar guardar: pintar
            // de rojo un formato recien abierto y vacio es reñir a quien
            // todavia no hizo nada. Con el guardado unico, guardar ES intentar
            // confirmar, asi que «hay algo guardado» equivale a «hubo intento».
            'attempted' => $entrega->answers()->exists() || $entrega->attachments()->exists(),
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

    /**
     * Guarda lo tecleado y, si con eso el formato queda completo, lo confirma.
     *
     * Es UN solo boton en pantalla —«Guardar cambios»— porque eran dos y el
     * dueño del producto lo dijo tal cual: nadie guarda para despues volver a
     * confirmar. El cliente ya no llama a `confirm`; manda las respuestas y el
     * servidor decide:
     *
     * - Completo → se confirma y se va a la ficha del plan. Quedarse mirando
     *   un formato ya cerrado no le sirve a nadie.
     * - Faltan campos → lo guardado SE QUEDA guardado (en obra se llena por
     *   partes), el formato sigue en borrador y se vuelve a la pantalla, que
     *   recibe la lista fresca de faltantes por sus props y la pinta como
     *   aviso. Guardar a medias es lo normal, no un error: por eso aqui no se
     *   llama a `confirmar()` a ciegas —que lanza `DomainException` y el
     *   handler global la pinta como flash rojo— sino que se pregunta primero
     *   que falta.
     */
    public function answer(Request $request, FormSubmission $form_submission)
    {
        $datos = $request->validate([
            // `present` y no `required`: una lista vacia es legitima. El caso
            // real es la HOJA X —el formato que es solo la foto del papel—
            // donde no hay nada que teclear y aun asi «Guardar cambios» tiene
            // que poder confirmar el formato una vez adjuntado el archivo.
            'answers'          => ['present', 'array'],
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

        if ($this->formatos->faltantes($form_submission) !== []) {
            return back()->with('success', __('field_work.saved_partial'));
        }

        $this->formatos->confirmar($form_submission);

        // El mismo criterio que la salida del pie (`canViewPlan` en `open()`):
        // a la ficha del plan solo se manda a quien puede abrirla; si no, a la
        // lista de formatos, que pide el permiso con el que se llego aqui.
        $plan = $form_submission->workPlan;

        return redirect()->to($request->user()?->can('work_plans.view')
            ? route('business_management.work_plans.show', $plan->slug)
            : route('field_work.forms.index', $plan->slug))
            ->with('success', __('field_work.saved_confirmed'));
    }

    /**
     * El caso "HOJA X": subir la foto del papel. Varias de una vez.
     *
     * En obra un papel son tres hojas y cuatro fotos, y de una en una eso son
     * siete viajes al servidor desde una tablet con mala señal. La pantalla
     * manda `files[]` con todo lo que se arrastro.
     *
     * `file` a secas se mantiene aunque la pantalla ya no lo use: esta ruta es
     * tambien la puerta de las integraciones, y romper un contrato publicado
     * para ahorrarse tres lineas no compensa.
     *
     * Los tipos se comprueban AQUI y no solo en el navegador: el `accept` del
     * arrastrar-y-soltar es una comodidad, no un candado — se salta con un
     * `curl`. La lista es la misma que ya habia: imagenes y PDF.
     */
    public function attach(Request $request, FormSubmission $form_submission)
    {
        $request->validate([
            'files'   => ['required_without:file', 'array', 'max:20'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'file'    => ['required_without:files', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $archivos = $request->file('files') ?: array_filter([$request->file('file')]);
        $campoId  = $request->integer('form_field_id') ?: null;

        // Lo que el campo declara en su configuracion: cuantos archivos admite
        // y de que tipo. Estaba solo en el editor de formatos, o sea que era
        // decorativo — se rellenaba «max_files: 2» y se podian subir veinte.
        if ($campoId && ($campo = FormField::find($campoId))) {
            if ($error = $this->loQueElCampoNoAdmite($campo, $form_submission, $archivos)) {
                return back()->with('error', $error);
            }
        }

        foreach ($archivos as $archivo) {
            $this->formatos->adjuntar(
                $form_submission,
                $archivo->get(),
                $archivo->getMimeType(),
                $campoId,
                $archivo->getClientOriginalName(),
            );
        }

        // Antes decia «Documento adjuntado.» escrito aqui dentro: texto de
        // interfaz a pelo en el controlador, que en ingles salia en castellano.
        return back()->with('success', trans_choice('field_work.attached_flash', count($archivos), [
            'count' => count($archivos),
        ]));
    }

    /**
     * Servir el archivo adjunto.
     *
     * Hace falta para poder VER lo que se subió: una firma que no se ve es un
     * lienzo en blanco que invita a firmar otra vez encima, y una foto que no
     * se puede mirar no se puede comprobar antes de confirmar el formato.
     *
     * El fichero vive en el disco `local`, o sea fuera de `public/`: no hay URL
     * directa y por eso pasa por aquí, que es donde se comprueba de quién es.
     * Mismo patrón que `SignatureController::evidence()`.
     *
     * 404 y no 403 cuando el adjunto es de otra entrega: con el id en la mano,
     * decir «no puedes» ya confirma que existe.
     */
    public function attachment(FormSubmission $form_submission, FormAttachment $attachment)
    {
        abort_if($attachment->form_submission_id !== $form_submission->id, 404);

        return \Storage::disk('local')->response($attachment->file_path);
    }

    /**
     * Lo que el campo NO admite de este lote, si es que no admite algo.
     *
     * `max_files` y `mimes` los escribe quien define el formato en el editor de
     * campos, y hasta ahora no los leia nadie: el navegador filtra por
     * comodidad y se salta con un `curl`. Un campo de foto de la portada que
     * dice «una sola» tiene que ser una sola de verdad.
     *
     * `mimes` viene como lista de extensiones («pdf», «jpg»), que es como la
     * escribe el editor. La lista general de tipos —imagenes y PDF— ya la
     * comprobo `validate()`: esto solo puede estrecharla, nunca ampliarla.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $archivos
     */
    protected function loQueElCampoNoAdmite(FormField $campo, FormSubmission $entrega, array $archivos): ?string
    {
        $config = $campo->config ?? [];

        $tope = (int) ($config['max_files'] ?? 0);
        if ($tope > 0) {
            $yaHay = $entrega->attachments()->where('form_field_id', $campo->id)->count();

            if ($yaHay + count($archivos) > $tope) {
                return __('field_work.attach_max_files', ['label' => $campo->label, 'max' => $tope]);
            }
        }

        $extensiones = array_filter(array_map(
            fn ($m) => strtolower(ltrim((string) $m, '.')),
            is_array($config['mimes'] ?? null) ? $config['mimes'] : [],
        ));

        if ($extensiones !== []) {
            foreach ($archivos as $archivo) {
                if (! in_array(strtolower($archivo->getClientOriginalExtension()), $extensiones, true)) {
                    return __('field_work.attach_field_mimes', [
                        'name'  => $archivo->getClientOriginalName(),
                        'mimes' => strtoupper(implode(', ', $extensiones)),
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * Quitar un adjunto: el que se subio por error.
     *
     * Sin esto, arrastrar cinco fotos y colar una equivocada no tenia arreglo
     * — y con la subida de una en una tampoco lo tenia, solo que costaba menos
     * equivocarse. El guardia de «no se escribe en lo confirmado» vive en el
     * servicio, que es quien sabe lo que es una entrega cerrada.
     */
    public function detach(FormSubmission $form_submission, FormAttachment $attachment): RedirectResponse
    {
        // El adjunto viaja por id y la entrega por slug: sin esto, el id de
        // otra entrega borraria un adjunto ajeno.
        abort_if($attachment->form_submission_id !== $form_submission->id, 404);

        $this->formatos->quitarAdjunto($form_submission, $attachment);

        return back()->with('success', __('field_work.detached_flash'));
    }

    /**
     * Cierra el formato. El servidor comprueba que no falte nada.
     *
     * La pantalla ya no llama aqui —«Guardar cambios» guarda y confirma en
     * `answer()`—, pero la ruta se queda: es la puerta para confirmar sin
     * pasar por la pantalla (integraciones, pruebas) y su candado —la
     * `DomainException` con los faltantes— sigue siendo la garantia de que un
     * formato incompleto no se cierra por mucho que se le pida.
     */
    public function confirm(FormSubmission $form_submission)
    {
        $this->formatos->confirmar($form_submission);

        return back()->with('success', __('field_work.confirmed_flash'));
    }

    /** Lo abre de nuevo para corregirlo. Queda en el historial quien lo hizo. */
    public function reopen(FormSubmission $form_submission)
    {
        $this->formatos->reabrir($form_submission);

        return back()->with('success', __('field_work.reopened'));
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
