<?php

namespace App\Http\Controllers\FieldWork;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\WorkPlan;
use App\Services\FieldWork\FormSubmissionService;
use Illuminate\Http\Request;

class FormSubmissionController extends Controller
{
    public function __construct(private readonly FormSubmissionService $formatos)
    {
    }

    /** Los formatos que exige el tipo de trabajo del plan, con su estado. */
    public function index(WorkPlan $work_plan)
    {
        $exigidos = $work_plan->workType->formTemplates()->published()->get();
        $entregas = $work_plan->submissions()->get()->keyBy('form_template_id');

        return inertia('FieldWork/Forms', [
            'plan' => $work_plan->only(['slug', 'code', 'description']),
            'templates' => $exigidos->map(fn ($t) => [
                'slug'   => $t->slug,
                'code'   => $t->code,
                'kind'   => $t->kind,
                'required' => (bool) $t->pivot->is_required,
                'status' => $entregas[$t->id]->status ?? 'pending',
            ]),
        ]);
    }

    /** Abre el formato para llenarlo. Congela la version de la plantilla. */
    public function open(Request $request, WorkPlan $work_plan, FormTemplate $form_template)
    {
        $entrega = $this->formatos->abrir($work_plan, $form_template, $request->user()->id);

        return inertia('FieldWork/FormFill', [
            'submission' => $entrega->only(['slug', 'status', 'template_version']),
            'template'   => [
                'code' => $form_template->code,
                'kind' => $form_template->kind,
                'sections' => $form_template->sections()->with('fields')->get(),
            ],
            'answers' => $entrega->answers()->get(),
            'missing' => $this->formatos->faltantes($entrega),
        ]);
    }

    public function answer(Request $request, FormSubmission $form_submission)
    {
        $datos = $request->validate([
            'answers'          => ['required', 'array'],
            'answers.*.code'   => ['required', 'string'],
            'answers.*.row'    => ['nullable', 'integer', 'min:0'],
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
}
