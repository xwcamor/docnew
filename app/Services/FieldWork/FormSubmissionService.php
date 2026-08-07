<?php

namespace App\Services\FieldWork;

use App\Models\FormAttachment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\WorkPlan;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Llenado de formatos de un plan de trabajo.
 *
 * En el sistema anterior cada formato era una tabla, un modelo, un controlador
 * de 150-250 lineas, sus vistas y su PDF: entre 3 y 5 dias de trabajo por
 * formato, y aun asi no se podian definir campos desde la interfaz. Aqui hay un
 * solo servicio para todos, incluido el formato que es solo la foto del papel.
 */
class FormSubmissionService
{
    /**
     * Abre (o recupera) la entrega de un formato para un plan.
     *
     * Guarda la version de la plantilla: publicar una version nueva no puede
     * alterar lo que ya se lleno y se firmo.
     */
    public function abrir(WorkPlan $plan, FormTemplate $plantilla, ?int $usuarioId = null): FormSubmission
    {
        if ($plantilla->status !== 'published') {
            throw new \InvalidArgumentException('La plantilla no esta publicada.');
        }

        return FormSubmission::firstOrCreate(
            ['work_plan_id' => $plan->id, 'form_template_id' => $plantilla->id],
            [
                'slug'             => Str::random(22),
                'template_version' => $plantilla->version,
                'status'           => 'draft',
                'submitted_by'     => $usuarioId ?? auth()->id(),
                'tenant_id'        => $plan->tenant_id,
                'created_by'       => $usuarioId ?? auth()->id(),
            ],
        );
    }

    /**
     * Guarda las respuestas. Cada valor va a la columna que corresponde a su
     * tipo de campo, no todo a texto.
     *
     * @param  array  $respuestas  [['code' => 'actividad', 'value' => '...', 'row' => 0], ...]
     */
    public function responder(FormSubmission $entrega, array $respuestas): FormSubmission
    {
        $campos = $entrega->formTemplate
            ->fields()
            ->get()
            ->keyBy('code');

        return DB::transaction(function () use ($entrega, $respuestas, $campos) {
            foreach ($respuestas as $respuesta) {
                $campo = $campos[$respuesta['code']] ?? null;

                if (! $campo) {
                    throw new ModelNotFoundException("El formato no tiene el campo '{$respuesta['code']}'.");
                }

                $entrega->answers()->updateOrCreate(
                    [
                        'form_field_id' => $campo->id,
                        'row_index'     => $respuesta['row'] ?? 0,
                    ],
                    $this->columnaSegunTipo($campo->field_type, $respuesta['value'] ?? null),
                );
            }

            return $entrega->fresh('answers');
        });
    }

    /**
     * El caso "HOJA X": el formato existe en papel y solo se le toma una foto.
     * Deduplica por hash para no guardar dos veces el mismo archivo.
     */
    public function adjuntar(FormSubmission $entrega, string $contenido, string $mime, ?int $campoId = null): FormAttachment
    {
        $hash = hash('sha256', $contenido);
        $existente = FormAttachment::where('sha256', $hash)->first();

        $ruta = $existente?->file_path
            ?? sprintf('formatos/%s/%s', now()->format('Y/m'), Str::random(24) . $this->extension($mime));

        if (! $existente) {
            Storage::disk('local')->put($ruta, $contenido);
        }

        return $entrega->attachments()->create([
            'form_field_id' => $campoId,
            'file_path'     => $ruta,
            'sha256'        => $hash,
            'mime_type'     => $mime,
            'byte_size'     => strlen($contenido),
            'uploaded_by'   => auth()->id(),
            'uploaded_at'   => now(),
        ]);
    }

    /**
     * Cierra la entrega. El servidor comprueba que no falte nada: los campos
     * obligatorios respondidos y, si el formato es de solo subida, el archivo
     * adjunto. El cliente no decide esto.
     */
    public function confirmar(FormSubmission $entrega): FormSubmission
    {
        $faltantes = $this->faltantes($entrega);

        if ($faltantes !== []) {
            throw new \InvalidArgumentException(
                'Faltan campos obligatorios: ' . implode(', ', $faltantes)
            );
        }

        $entrega->update([
            'status'       => 'confirmed',
            'submitted_at' => now(),
            'submitted_by' => auth()->id() ?? $entrega->submitted_by,
        ]);

        return $entrega->fresh();
    }

    /** Que le falta a la entrega para poder cerrarse. */
    public function faltantes(FormSubmission $entrega): array
    {
        $plantilla = $entrega->formTemplate;

        if ($plantilla->kind === FormTemplate::UPLOAD_ONLY) {
            return $entrega->attachments()->exists() ? [] : ['archivo del formato'];
        }

        $respondidos = $entrega->answers()->pluck('form_field_id')->all();

        $faltantes = $plantilla->fields()
            ->where('is_required', true)
            ->get()
            ->reject(fn ($campo) => in_array($campo->id, $respondidos, true))
            ->pluck('code')
            ->all();

        if ($plantilla->kind === FormTemplate::HYBRID && ! $entrega->attachments()->exists()) {
            $faltantes[] = 'archivo del formato';
        }

        return $faltantes;
    }

    /** Cada tipo de campo se guarda en su columna, para poder consultarlo. */
    protected function columnaSegunTipo(string $tipo, mixed $valor): array
    {
        $vacio = [
            'value_text' => null, 'value_number' => null,
            'value_datetime' => null, 'value_boolean' => null, 'value_json' => null,
        ];

        return match ($tipo) {
            'number'                  => [...$vacio, 'value_number' => $valor],
            'date', 'time'            => [...$vacio, 'value_datetime' => $valor],
            'checkbox'                => [...$vacio, 'value_boolean' => (bool) $valor],
            'multiselect', 'table', 'risk_matrix',
            'person_checklist', 'tool_checklist', 'question_bank' => [...$vacio, 'value_json' => $valor],
            default                   => [...$vacio, 'value_text' => $valor],
        };
    }

    protected function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => '.jpg',
            'image/png'  => '.png',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            default => '.bin',
        };
    }
}
