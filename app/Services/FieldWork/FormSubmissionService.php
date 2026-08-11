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
        $this->exigirQueSePuedaEscribir($entrega);

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
        $this->exigirQueSePuedaEscribir($entrega);

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
        // Idempotente, como `reabrir()`: confirmar lo que ya esta confirmado no
        // hace nada. Antes tiraba `confirmed_reopen_first`, y con el guardado
        // que confirma solo (`answer` en el controlador) eso convertia en error
        // una carrera inocente: dos toques seguidos al mismo boton, o un
        // `confirm` que llega despues de que el guardado ya cerrara el formato.
        if ($entrega->status === 'confirmed') {
            return $entrega;
        }

        $this->exigirQueSePuedaEscribir($entrega);

        $faltantes = $this->faltantes($entrega);

        if ($faltantes !== []) {
            // `DomainException` y no `InvalidArgumentException`: esto no es un
            // fallo de programacion, es el sistema diciendo que todavia no. La
            // diferencia se nota en pantalla — lo primero vuelve como un aviso
            // (ver `bootstrap/app.php`), lo segundo como un error 500 con la
            // traza de PHP delante de alguien que esta en la obra.
            //
            // Y el texto sale de `resources/lang`, en los dos idiomas, no
            // escrito en castellano aqui dentro.
            throw new \DomainException(__('field_work.missing_required', [
                'fields' => implode(', ', $faltantes),
            ]));
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
     * Vuelve a abrir un formato confirmado, para poder corregirlo.
     *
     * Confirmar dejaba la entrega cerrada para siempre y no habia vuelta atras:
     * un dato mal tecleado en el AST se quedaba mal tecleado. En obra eso no se
     * sostiene —quien llena el formato es el que esta en el frente de trabajo,
     * con guantes y con prisa— asi que corregir tiene que ser posible. Lo que no
     * puede es pasar sin que se sepa.
     *
     * Por eso es una accion propia y no un simple «editar»: cambia el estado a
     * borrador, y ese cambio queda en el historial con quien lo hizo y cuando
     * (el trait `Auditable` de `FormSubmission` escribe la fila con el usuario,
     * la IP y el antes/despues).
     *
     * El limite es el plan. Una vez cerrado, el plan entero es documento de
     * archivo: ya lo firmaron los que autorizan y el sistema no deja ni editarlo
     * (`WorkPlanController`, `cannot_edit_closed`). Reabrir un formato de dentro
     * seria cambiar por la puerta de atras algo que ya esta firmado.
     *
     * Es idempotente: reabrir lo que ya esta en borrador no hace nada.
     */
    public function reabrir(FormSubmission $entrega): FormSubmission
    {
        if ($entrega->status !== 'confirmed') {
            return $entrega;
        }

        $this->exigirQueElPlanSigaAbierto($entrega);

        $entrega->update([
            'status' => 'draft',
            // La fecha de entrega se va con el estado: mientras esta en
            // borrador no hay entrega, y dejar la de antes seria decir que se
            // cerro un dia en que no estaba cerrado. Al volver a confirmar se
            // pone la nueva, que es la que vale.
            'submitted_at' => null,
        ]);

        return $entrega->fresh();
    }

    /**
     * Si la entrega admite cambios ahora mismo.
     *
     * Estaba escrito solo en la pantalla —`soloLectura` en `FormFill.vue`— o
     * sea que el candado era de mentira: una peticion a mano a `answers` o a
     * `confirm` modificaba un formato ya cerrado sin que nada lo parara. Lo que
     * decide que es editable lo decide el servidor, siempre.
     */
    protected function exigirQueSePuedaEscribir(FormSubmission $entrega): void
    {
        $this->exigirQueElPlanSigaAbierto($entrega);

        if ($entrega->status === 'confirmed') {
            throw new \DomainException(__('field_work.confirmed_reopen_first'));
        }
    }

    protected function exigirQueElPlanSigaAbierto(FormSubmission $entrega): void
    {
        if ($entrega->workPlan?->is_closed) {
            throw new \DomainException(__('field_work.plan_closed'));
        }
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
        return array_column($this->faltantesDetallados($entrega), 'label');
    }

    /**
     * Lo mismo, pero con el CODIGO de cada campo ademas de la etiqueta.
     *
     * La etiqueta es para leerla; el codigo es para que la pantalla encuentre
     * el campo y lo marque en rojo tras un guardado que quedo a medias. La
     * etiqueta no sirve de ancla: depende del idioma y hasta puede repetirse
     * entre dos campos. El adjunto que falta no es un campo y va con codigo
     * nulo: se nombra en el aviso, pero no hay nada que marcar.
     *
     * @return array<int, array{code: ?string, label: string}>
     */
    public function faltantesDetallados(FormSubmission $entrega): array
    {
        $plantilla = $entrega->formTemplate;

        $adjunto = ['code' => null, 'label' => __('field_work.missing_attachment')];

        if ($plantilla->kind === FormTemplate::UPLOAD_ONLY) {
            return $entrega->attachments()->exists() ? [] : [$adjunto];
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
            ->map(fn ($campo) => ['code' => $campo->code, 'label' => $campo->label])
            ->values()
            ->all();

        if ($plantilla->kind === FormTemplate::HYBRID && ! $entrega->attachments()->exists()) {
            $faltantes[] = $adjunto;
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
            'risk_matrix' => $this->exigirPeligroEntero($valor, $tipo),
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

    /**
     * Un peligro evaluado esta entero, o no esta.
     *
     * En la v1 las cinco casillas de la fila —peligro, riesgo, control,
     * severidad y probabilidad— iban con `required: true`
     * (`_f1_document_danger_fields.html.erb`). Aqui solo se exigian severidad y
     * probabilidad, asi que se podia guardar y confirmar un AST con un peligro
     * puntuado «alto» y **sin decir de que peligro se trata ni que control se
     * puso**. Es la fila mas importante del documento: dice que puede salir mal
     * y que se hizo para evitarlo.
     *
     * Lo que NO se exige es que la fila exista: una actividad sin peligros es
     * una fila legitima —la v1 las guardaba asi y la migracion las conserva—.
     * Por eso la regla es condicional: en cuanto alguien empieza a evaluar
     * (pone severidad o probabilidad), la fila tiene que quedar completa.
     */
    protected function exigirPeligroEntero(mixed $valor, string $tipo): void
    {
        if (! is_array($valor)) {
            throw new \InvalidArgumentException("El campo '{$tipo}' espera una fila de la matriz.");
        }

        $evaluando = filled($valor['severidad'] ?? null) || filled($valor['probabilidad'] ?? null);

        if (! $evaluando) {
            return;
        }

        $faltan = array_values(array_filter(
            ['peligro', 'riesgo', 'control', 'severidad', 'probabilidad'],
            fn ($clave) => blank($valor[$clave] ?? null),
        ));

        if ($faltan !== []) {
            throw new \DomainException(__('field_work.risk_matrix.row_incomplete', [
                'fields' => implode(', ', array_map(
                    fn ($c) => __("field_work.risk_matrix.{$this->rotuloDeColumna($c)}"),
                    $faltan,
                )),
            ]));
        }
    }

    /** El nombre de la columna en `resources/lang`, que no siempre es la clave. */
    protected function rotuloDeColumna(string $clave): string
    {
        return match ($clave) {
            'peligro' => 'danger',
            'riesgo'  => 'risk',
            'control' => 'control',
            'severidad' => 'severity',
            'probabilidad' => 'probability',
            default => $clave,
        };
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
