<?php

namespace App\Services\FieldWork;

use App\Models\FormAnswer;
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
     * Una respuesta con valor nulo **borra** la fila en vez de guardarla vacia.
     * Importa en los campos de varias filas —la matriz de riesgo, los checklist—
     * donde quitar una fila se manda como un hueco: si se guardara vacia
     * quedaria una lapida, y `faltantes()` la contaria como respondida, con lo
     * que un campo obligatorio vaciado del todo dejaria cerrar el formato.
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

                $valor = $respuesta['value'] ?? null;
                $clave = ['form_field_id' => $campo->id, 'row_index' => $respuesta['row'] ?? 0];

                if ($this->esHueco($valor)) {
                    $entrega->answers()->where($clave)->delete();

                    continue;
                }

                $this->validarValor($campo->field_type, $valor, $campo->config ?? []);

                $entrega->answers()->updateOrCreate(
                    $clave,
                    $this->columnaSegunTipo($campo->field_type, $valor),
                );
            }

            $entrega = $entrega->fresh('answers');

            // Las no conformidades se recalculan solas en cada guardado, como el
            // `after_save :set_completed` de los cuatro formatos de la v1. Es un
            // numero derivado: nadie lo escribe a mano.
            app(FormFindingsService::class)->recalcular($entrega);

            return $entrega->fresh('answers');
        });
    }

    /**
     * Un hueco es una respuesta que no dice nada: nula, o una lista vacia.
     *
     * `false` y `0` NO son huecos: una casilla desmarcada y un cero son
     * respuestas de pleno derecho. Una cadena vacia tampoco se borra aqui —
     * `faltantes()` ya la trata como sin responder si el campo es obligatorio.
     */
    protected function esHueco(mixed $valor): bool
    {
        return $valor === null || (is_array($valor) && $valor === []);
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

        // Confirmar NO exige que salga limpio, igual que en la v1: alli
        // `lock_plan_if_all_conditions_met` sólo miraba `date_end` y las
        // aprobaciones, así que un plan con un EPP observado se cerraba. Un
        // arnés en mal estado hay que poder registrarlo y cerrarlo el mismo día,
        // con su medida de corrección al lado. Lo que sí queda es el número.
        app(FormFindingsService::class)->recalcular($entrega);

        // Confirmar el último formato puede ser lo que cierre el plan. El cierre
        // exige el plan completo —firmas, formatos y aprobaciones—, así que
        // cualquiera de las tres cosas puede ser la última en llegar y las tres
        // tienen que preguntar.
        if ($entrega->workPlan) {
            app(\App\Services\BusinessManagement\WorkPlanCompletionService::class)
                ->evaluar($entrega->workPlan);
        }

        return $entrega->fresh();
    }

    /**
     * Que le falta a la entrega para poder cerrarse, dicho como se lee.
     *
     * Devuelve la ETIQUETA del campo, no su codigo. La lista sale en un aviso
     * amarillo delante de quien esta rellenando el formato en obra, y
     * «matriz_de_riesgo» no es algo que nadie tenga que descifrar. Se decia el
     * codigo porque hasta ahora no habia otra cosa que decir: los campos no
     * tenian etiqueta guardada y la pantalla la sacaba de humanizar el codigo.
     */
    public function faltantes(FormSubmission $entrega): array
    {
        $plantilla = $entrega->formTemplate;

        if ($plantilla->kind === FormTemplate::UPLOAD_ONLY) {
            return $entrega->attachments()->exists() ? [] : [__('field_work.missing_attachment')];
        }

        // Solo cuentan las respuestas que dicen algo. Una fila con las cinco
        // columnas en nulo, o con una lista vacia, no es una respuesta: puede
        // venir de datos migrados o de una version anterior del guardado, y
        // dejaria cerrar un formato al que le falta un campo obligatorio.
        $respondidos = $entrega->answers()->get()
            ->filter(fn ($r) => $this->respuestaConContenido($r))
            ->pluck('form_field_id')
            ->all();

        $faltantes = $plantilla->fields()
            ->where('is_required', true)
            ->get()
            ->reject(fn ($campo) => in_array($campo->id, $respondidos, true))
            ->map(fn ($campo) => $campo->label)
            ->all();

        if ($plantilla->kind === FormTemplate::HYBRID && ! $entrega->attachments()->exists()) {
            $faltantes[] = __('field_work.missing_attachment');
        }

        return $faltantes;
    }

    /**
     * Si una fila de respuestas guarda algo o esta hueca.
     *
     * `false` cuenta: una casilla desmarcada es una respuesta. Una cadena
     * vacia y una lista vacia, no.
     */
    protected function respuestaConContenido(FormAnswer $respuesta): bool
    {
        if ($respuesta->value_boolean !== null) {
            return true;
        }

        if ($respuesta->value_number !== null || $respuesta->value_datetime !== null) {
            return true;
        }

        if (filled($respuesta->value_text)) {
            return true;
        }

        $json = $respuesta->value_json;

        return is_array($json) ? $json !== [] : filled($json);
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

    /**
     * Los tipos compuestos tienen forma: si no se valida aqui, el PDF y los
     * reportes reciben cualquier cosa. En el sistema viejo esta forma vivia en
     * columnas fijas de cada tabla de formato; aqui vive en el tipo de campo.
     */
    protected function validarValor(string $tipo, mixed $valor, array $config): void
    {
        if ($valor === null) {
            return;
        }

        match ($tipo) {
            'risk_matrix' => $this->exigirClaves($valor, ['severidad', 'probabilidad'], $tipo),
            'person_checklist', 'tool_checklist' => $this->exigirLista($valor, $tipo),
            'question_bank' => $this->exigirLista($valor, $tipo),
            'select', 'radio' => $this->exigirOpcion($valor, $config['options'] ?? [], $tipo),
            'number' => is_numeric($valor) ?: throw new \InvalidArgumentException("El campo '{$tipo}' espera un numero."),
            default => null,
        };
    }

    protected function exigirClaves(mixed $valor, array $claves, string $tipo): void
    {
        if (! is_array($valor) || array_diff($claves, array_keys($valor)) !== []) {
            throw new \InvalidArgumentException(
                "El campo '{$tipo}' espera " . implode(' y ', $claves) . '.'
            );
        }
    }

    protected function exigirLista(mixed $valor, string $tipo): void
    {
        if (! is_array($valor) || $valor === []) {
            throw new \InvalidArgumentException("El campo '{$tipo}' espera una lista de respuestas.");
        }
    }

    protected function exigirOpcion(mixed $valor, array $opciones, string $tipo): void
    {
        if ($opciones !== [] && ! in_array($valor, $opciones, true)) {
            throw new \InvalidArgumentException("El valor no es una opcion valida de '{$tipo}'.");
        }
    }
}
