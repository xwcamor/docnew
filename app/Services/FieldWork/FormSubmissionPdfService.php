<?php

namespace App\Services\FieldWork;

use App\Models\EvidenceFile;
use App\Models\FormAttachment;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\ReportSigner;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Support\Tz;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * El PDF firmado de una entrega: el documento que la empresa conserva.
 *
 * Es el ultimo eslabon del flujo. Hasta aqui el formato se llena y se firma con
 * la cara, pero de eso no sale nada imprimible, y lo que se guarda a los dos
 * anios no es la fila de la base de datos sino el papel.
 *
 * Tres cosas que no son negociables y por eso viven en este servicio y no en la
 * vista:
 *
 *  1. Se pinta la version CONGELADA de la plantilla (`template_version`), no la
 *     vigente. Publicar una version nueva del formato no puede cambiar lo que
 *     alguien ya firmo.
 *  2. Las fotos de evidencia se leen del disco privado y se incrustan como
 *     data-uri. Nunca se genera una URL publica de una cara.
 *  3. Los tipos compuestos (matriz de riesgo, checklists, banco de preguntas)
 *     se pintan como tabla derivando las columnas del propio valor guardado. El
 *     motor de formatos permite definirlos desde la interfaz, asi que el PDF no
 *     puede tener las columnas cableadas.
 */
class FormSubmissionPdfService
{
    /** Tipos que se pintan como tabla; el resto son pares etiqueta/valor. */
    protected const COMPUESTOS = [
        'table', 'risk_matrix', 'person_checklist', 'tool_checklist', 'question_bank',
    ];

    /** Tipos cuyo valor no esta en `form_answers` sino en `form_attachments`. */
    protected const CON_ARCHIVO = ['photo', 'file'];

    /**
     * Genera el PDF listo para descargar.
     *
     * El idioma es el que ya resolvio la peticion: el documento sale en el
     * idioma de quien lo pide, no en el del servidor.
     */
    public function generar(FormSubmission $entrega, ?User $usuario = null): \Barryvdh\DomPDF\PDF
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'field_work.form_submissions.pdf.template',
            $this->datos($entrega, $usuario),
        )
            ->setPaper('a4', 'portrait')
            ->setOptions([
                // DomPDF solo conoce las core fonts sin instalar: Helvetica,
                // Times-Roman, Courier, Symbol, ZapfDingbats.
                'defaultFont'          => 'Helvetica',
                'isHtml5ParserEnabled' => true,
                // Todo lo que se incrusta va en data-uri: el generador no sale
                // a la red, ni siquiera a si mismo.
                'isRemoteEnabled'      => false,
                'dpi'                  => 110,
            ]);
    }

    /** Nombre del archivo: codigo de formato, codigo de plan y slug verificable. */
    public function nombreArchivo(FormSubmission $entrega): string
    {
        $partes = array_filter([
            $entrega->formTemplate?->code,
            $entrega->workPlan?->code,
            $entrega->slug,
        ]);

        return \Illuminate\Support\Str::slug(implode('-', $partes) ?: 'formato') . '.pdf';
    }

    /**
     * El modelo de vista completo. Se arma aqui —y no en la plantilla— para que
     * la vista solo tenga que pintar y para poder probarlo sin renderizar.
     */
    public function datos(FormSubmission $entrega, ?User $usuario = null): array
    {
        $entrega->loadMissing([
            'workPlan.company', 'workPlan.workType', 'workPlan.workLocation',
            'formTemplate', 'answers', 'attachments',
        ]);

        $plantilla = $this->plantillaCongelada($entrega);
        $plan = $entrega->workPlan;
        $tenant = $entrega->tenant_id ? \App\Models\Tenant::withTrashed()->find($entrega->tenant_id) : null;

        return [
            'membrete'  => $this->membrete($tenant),
            'plan'      => $this->cabeceraDelPlan($plan, $usuario),
            'formato'   => $this->cabeceraDelFormato($entrega, $plantilla, $usuario),
            'secciones' => $this->secciones($entrega, $plantilla, $usuario),
            'adjuntos'  => $this->adjuntos($entrega, $plantilla, $usuario),
            'firmas'    => $this->firmas($entrega, $usuario),
            'firmantes' => $this->firmantesFormales($tenant),
            'pie'       => [
                'identificador' => $entrega->slug,
                'generado'      => Tz::format(now(), $usuario),
            ],
        ];
    }

    // ── Membrete y cabeceras ────────────────────────────────────────────────

    /** Logo, nombre, direccion y disclaimer del workspace. */
    protected function membrete(?\App\Models\Tenant $tenant): array
    {
        return [
            'nombre'     => $tenant?->name ?: config('app.name'),
            'direccion'  => $tenant?->address,
            'disclaimer' => $tenant?->report_disclaimer,
            // El logo vive en el disco publico, pero se incrusta igual: el PDF
            // tiene que poder abrirse sin conexion y sin el servidor delante.
            'logo'       => $this->imagenDelDisco('public', $tenant?->logo),
        ];
    }

    protected function cabeceraDelPlan(?\App\Models\WorkPlan $plan, ?User $usuario): array
    {
        if (! $plan) {
            return [];
        }

        return [
            'codigo'      => $plan->code,
            'num_os'      => $plan->num_os,
            'empresa'     => $plan->company?->complete_name ?: $plan->company?->name,
            'empresa_doc' => $plan->company?->num_doc,
            'tipo'        => $plan->workType?->code,
            'ubicacion'   => $plan->workLocation?->name,
            'puesto'      => $plan->workstation_id
                ? \App\Models\Workstation::find($plan->workstation_id)?->name : null,
            'area'        => $plan->work_area_id
                ? \App\Models\WorkArea::find($plan->work_area_id)?->name : null,
            'desde'       => Tz::formatDate($plan->date_start, $usuario),
            'hasta'       => Tz::formatDate($plan->date_end, $usuario),
            'descripcion' => $plan->description,
        ];
    }

    protected function cabeceraDelFormato(FormSubmission $entrega, ?FormTemplate $plantilla, ?User $usuario): array
    {
        return [
            'codigo'        => $plantilla?->code ?? $entrega->formTemplate?->code,
            'tipo'          => $plantilla?->kind ?? FormTemplate::STRUCTURED,
            'version'       => (int) $entrega->template_version,
            'estado'        => $this->traducir(
                'form_submissions.pdf.statuses.' . $entrega->status,
                $entrega->status,
            ),
            'entregado_en'  => Tz::format($entrega->submitted_at, $usuario),
            'observaciones' => $entrega->observations,
        ];
    }

    /**
     * La plantilla con la que se lleno, no la vigente.
     *
     * Cada version es una fila propia (`FormTemplateBuilder::nuevaVersion`), asi
     * que casi siempre basta con la que apunta la entrega. Se comprueba igual:
     * si el numero no cuadra —datos migrados, por ejemplo— se busca la fila de
     * esa version por codigo, pais y workspace, que es la clave unica real.
     */
    protected function plantillaCongelada(FormSubmission $entrega): ?FormTemplate
    {
        $actual = $entrega->formTemplate;

        if (! $actual || (int) $actual->version === (int) $entrega->template_version) {
            return $actual;
        }

        $congelada = FormTemplate::withTrashed()
            ->where('code', $actual->code)
            ->where('country_id', $actual->country_id)
            ->where('version', (int) $entrega->template_version)
            ->when(
                $actual->tenant_id === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $actual->tenant_id),
            )
            ->first();

        // Sin la version congelada se pinta la que hay: un PDF con la estructura
        // aproximada es mejor que ningun documento, y la version impresa avisa.
        return $congelada ?? $actual;
    }

    // ── El formato tal como se lleno ────────────────────────────────────────

    /** @return array<int, array{titulo: string, campos: array}> */
    protected function secciones(FormSubmission $entrega, ?FormTemplate $plantilla, ?User $usuario): array
    {
        if (! $plantilla) {
            return [];
        }

        $respuestas = $entrega->answers->groupBy('form_field_id');
        $adjuntos = $entrega->attachments->groupBy('form_field_id');
        $salida = [];

        foreach ($plantilla->sections()->with('fields')->get() as $indice => $seccion) {
            $campos = [];

            foreach ($seccion->fields as $campo) {
                $campos[] = $this->campo(
                    $campo,
                    $respuestas->get($campo->id, collect()),
                    $adjuntos->get($campo->id, collect()),
                    $usuario,
                );
            }

            if ($campos === []) {
                continue;
            }

            $salida[] = [
                // Las secciones no tienen rotulo propio en la plantilla: se
                // numeran por su posicion, que es lo que ordena el formato.
                'titulo' => __('form_submissions.pdf.section', ['number' => $seccion->position ?: $indice + 1]),
                'campos' => $campos,
            ];
        }

        return $salida;
    }

    /** Un campo ya resuelto para pintarse: par etiqueta/valor, tabla o imagenes. */
    protected function campo(FormField $campo, Collection $respuestas, Collection $adjuntos, ?User $usuario): array
    {
        $base = [
            'codigo'    => $campo->code,
            'etiqueta'  => $this->etiqueta($campo),
            'tipo'      => $campo->field_type,
            'requerido' => (bool) $campo->is_required,
        ];

        if (in_array($campo->field_type, self::CON_ARCHIVO, true)) {
            return $base + [
                'render'   => 'imagenes',
                'imagenes' => $adjuntos
                    ->map(fn (FormAttachment $a) => $this->imagenDelDisco('local', $a->file_path))
                    ->filter()
                    ->values()
                    ->all(),
            ];
        }

        if (in_array($campo->field_type, self::COMPUESTOS, true)) {
            return $base + ['render' => 'tabla'] + $this->tabla($campo, $respuestas);
        }

        return $base + [
            'render' => 'par',
            'valor'  => $this->valorSimple($campo, $respuestas->sortBy('row_index')->first(), $usuario),
        ];
    }

    /**
     * Etiqueta imprimible del campo. La plantilla no tiene columna de etiqueta:
     * lleva un `code` tecnico y una configuracion libre, asi que se usa el
     * rotulo configurado si lo hay y, si no, el codigo hecho legible.
     */
    protected function etiqueta(FormField $campo): string
    {
        $config = $campo->config ?? [];
        $rotulo = $config['label'] ?? $config['title'] ?? null;

        if (filled($rotulo) && is_string($rotulo)) {
            return $rotulo;
        }

        return ucfirst(str_replace('_', ' ', $campo->code));
    }

    /** Valor de un campo simple, formateado segun su tipo. */
    protected function valorSimple(FormField $campo, ?\App\Models\FormAnswer $respuesta, ?User $usuario): ?string
    {
        if (! $respuesta) {
            return null;
        }

        return match ($campo->field_type) {
            'number'   => $respuesta->value_number === null
                ? null
                : rtrim(rtrim(number_format((float) $respuesta->value_number, 4, '.', ''), '0'), '.'),
            'date'     => Tz::formatDate($respuesta->value_datetime, $usuario),
            'time'     => Tz::formatTime($respuesta->value_datetime, $usuario),
            'checkbox' => $respuesta->value_boolean === null
                ? null
                : ($respuesta->value_boolean ? __('global.yes') : __('global.no')),
            'multiselect' => $this->aplanar($respuesta->value_json),
            default    => $respuesta->value_text !== null
                ? (string) $respuesta->value_text
                : $this->aplanar($respuesta->value_json),
        };
    }

    /**
     * Convierte las respuestas de un campo compuesto en cabeceras y filas.
     *
     * El valor guardado puede tener tres formas, porque el formato lo define
     * quien lo configura: una lista de filas, una fila suelta o una lista de
     * valores. Las tres se aceptan y las columnas salen de las claves que
     * realmente aparecen, en el orden en que aparecen.
     *
     * @return array{cabeceras: array<int, string>, filas: array<int, array<int, string>>}
     */
    protected function tabla(FormField $campo, Collection $respuestas): array
    {
        $claves = [];
        $filasCrudas = [];

        // `columns` de un campo de tipo tabla fija el orden de las columnas.
        foreach (($campo->config['columns'] ?? []) as $columna) {
            if (is_string($columna)) {
                $claves[$columna] = true;
            }
        }

        foreach ($respuestas->sortBy('row_index') as $respuesta) {
            $valor = $respuesta->value_json;

            if ($valor === null) {
                $texto = $respuesta->value_text;
                if (filled($texto)) {
                    $filasCrudas[] = ['_' => (string) $texto];
                    $claves['_'] = true;
                }
                continue;
            }

            foreach ($this->comoFilas($valor) as $fila) {
                $fila = $this->sinIdentificadores($fila);

                foreach (array_keys($fila) as $clave) {
                    $claves[$clave] = true;
                }
                $filasCrudas[] = $fila;
            }
        }

        $orden = array_keys($claves);

        return [
            'cabeceras' => array_map(
                fn ($c) => $c === '_' ? $this->etiqueta($campo) : ucfirst(str_replace('_', ' ', (string) $c)),
                $orden,
            ),
            'filas' => array_map(
                fn (array $fila) => array_map(fn ($c) => $this->aplanar($fila[$c] ?? null) ?? '', $orden),
                $filasCrudas,
            ),
        ];
    }

    /**
     * Quita de la fila los identificadores internos.
     *
     * La pantalla de llenado guarda el `person_slug` junto al nombre para poder
     * reconstruir la fila; en el papel esa columna no dice nada y encima delata
     * como esta hecha la aplicacion.
     */
    protected function sinIdentificadores(array $fila): array
    {
        return array_filter(
            $fila,
            fn ($clave) => ! is_string($clave) || ! str_ends_with($clave, '_slug'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** Normaliza cualquiera de las tres formas admitidas a lista de filas. */
    protected function comoFilas(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [['_' => $valor]];
        }

        // Lista: cada elemento es una fila (asociativa) o un valor suelto.
        if (array_is_list($valor)) {
            return array_map(
                fn ($fila) => is_array($fila) && ! array_is_list($fila) ? $fila : ['_' => $fila],
                $valor,
            );
        }

        return [$valor];
    }

    /** Cualquier valor a texto imprimible, sin JSON crudo en el documento. */
    protected function aplanar(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? __('global.yes') : __('global.no');
        }

        if (! is_array($valor)) {
            return (string) $valor;
        }

        // Lista de pares: es la forma que emiten los checklists ({item,
        // answer}) y el banco de preguntas ({question, answer}). Se imprime
        // como se lee en papel —"Casco: Conforme"— y no clave por clave.
        if (array_is_list($valor) && $this->esListaDePares($valor)) {
            return implode(' · ', array_map(function (array $par) {
                $lados = array_values($par);

                return $this->aplanar($lados[0]) . ': ' . ($this->aplanar($lados[1]) ?? '—');
            }, $valor));
        }

        $partes = [];

        foreach ($valor as $clave => $item) {
            $texto = $this->aplanar($item);

            if ($texto === null) {
                continue;
            }

            $partes[] = is_int($clave) ? $texto : ucfirst(str_replace('_', ' ', (string) $clave)) . ': ' . $texto;
        }

        return $partes === [] ? null : implode(', ', $partes);
    }

    /** Cada elemento es un objeto de dos claves: pregunta/respuesta, item/respuesta. */
    protected function esListaDePares(array $valor): bool
    {
        if ($valor === []) {
            return false;
        }

        foreach ($valor as $elemento) {
            if (! is_array($elemento) || array_is_list($elemento) || count($elemento) !== 2) {
                return false;
            }
        }

        return true;
    }

    // ── Adjuntos ────────────────────────────────────────────────────────────

    /**
     * En un formato de solo subida la foto del papel ES el documento, asi que
     * sale a pagina completa. Lo que no sea imagen —un PDF escaneado, por
     * ejemplo— no se puede incrustar: se deja constancia con su huella para que
     * el archivo siga siendo verificable.
     */
    protected function adjuntos(FormSubmission $entrega, ?FormTemplate $plantilla, ?User $usuario): array
    {
        $aPaginaCompleta = ($plantilla?->kind ?? null) !== FormTemplate::STRUCTURED;

        // Los adjuntos de un campo `photo`/`file` ya se pintan dentro de su
        // campo. El resto sale aqui, incluido el que apunta a un campo que no
        // es de archivo: un adjunto que no se imprime en ningun sitio seria un
        // documento perdido.
        $enSuCampo = $plantilla
            ? $plantilla->fields()->whereIn('field_type', self::CON_ARCHIVO)->pluck('form_fields.id')->all()
            : [];

        return $entrega->attachments
            ->filter(fn (FormAttachment $a) => ! in_array($a->form_field_id, $enSuCampo, true))
            ->values()
            ->map(fn (FormAttachment $a, int $i) => [
                'numero'          => $i + 1,
                'imagen'          => $this->imagenDelDisco('local', $a->file_path),
                'mime'            => $a->mime_type,
                'sha256'          => $a->sha256,
                'subido'          => Tz::format($a->uploaded_at, $usuario),
                'pagina_completa' => $aPaginaCompleta,
            ])
            ->all();
    }

    // ── Firmas ──────────────────────────────────────────────────────────────

    /**
     * Las firmas de la entrega y las del plan del que cuelga.
     *
     * Se juntan las tres cosas que se firman en un dia de obra: el formato
     * entregado, cada trabajador de la cuadrilla y cada aprobacion. Es el bloque
     * que da valor al documento, y por eso las que quedaron pendientes de
     * revision salen marcadas en vez de disimuladas.
     */
    protected function firmas(FormSubmission $entrega, ?User $usuario): array
    {
        $planId = $entrega->work_plan_id;

        $trabajadores = WorkPlanPerson::where('work_plan_id', $planId)->pluck('id')->all();
        $aprobaciones = WorkPlanApproval::where('work_plan_id', $planId)->pluck('id')->all();

        $eventos = SignatureEvent::query()
            ->with(['person', 'files'])
            ->where(function ($q) use ($entrega, $trabajadores, $aprobaciones) {
                $q->where(fn ($s) => $s
                    ->where('signable_type', $entrega->getMorphClass())
                    ->where('signable_id', $entrega->getKey()));

                if ($trabajadores !== []) {
                    $q->orWhere(fn ($s) => $s
                        ->where('signable_type', (new WorkPlanPerson)->getMorphClass())
                        ->whereIn('signable_id', $trabajadores));
                }

                if ($aprobaciones !== []) {
                    $q->orWhere(fn ($s) => $s
                        ->where('signable_type', (new WorkPlanApproval)->getMorphClass())
                        ->whereIn('signable_id', $aprobaciones));
                }
            })
            ->orderBy('signed_at')
            ->orderBy('id')
            ->get();

        return $eventos->map(fn (SignatureEvent $e) => [
            'nombre'     => $e->person?->full_name,
            'documento'  => $e->person?->num_doc,
            'rol'        => $this->traducir('form_submissions.pdf.roles.' . $e->role_signed, $e->role_signed),
            'hora'       => Tz::format($e->signed_at, $usuario),
            'metodo'     => $this->traducir('form_submissions.pdf.methods.' . $e->method, $e->method),
            'pendiente'  => (bool) $e->pending_review,
            'distancia'  => $e->match_distance,
            'umbral'     => $e->threshold_used,
            'motivo'     => $e->manual_override ? $e->override_reason : null,
            'foto'       => $this->fotoDeEvidencia($e),
        ])->all();
    }

    /** La cara que se capturo al firmar, leida del disco privado. */
    protected function fotoDeEvidencia(SignatureEvent $evento): ?string
    {
        $archivo = $evento->files->firstWhere('kind', EvidenceFile::FACE);

        return $archivo ? $this->imagenDelDisco('local', $archivo->file_path) : null;
    }

    /** Los slots de firma formal del workspace, con su relacion traducida. */
    protected function firmantesFormales(?\App\Models\Tenant $tenant): array
    {
        if (! $tenant) {
            return [];
        }

        return $tenant->reportSigners()->with('user')->get()
            ->map(fn (ReportSigner $f) => [
                'relacion' => $this->traducir(
                    'approvals.relation.' . ($f->relation ?: 'approved'),
                    $f->relation ?: 'approved',
                ),
                'nombre' => $f->displayName(),
                'cargo'  => $f->title,
                // Solo se estampa la firma de quien la autorizo expresamente.
                'firma'  => $f->user?->auto_sign_reports
                    ? $this->imagenDelDisco('local', $f->user->signature)
                    : null,
            ])
            ->all();
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    /**
     * Lee una imagen del disco y la devuelve como data-uri.
     *
     * Se lee del disco a proposito: las caras viven en el disco privado y no
     * pueden salir a una URL, ni siquiera autenticada, dentro de un PDF que
     * despues se manda por correo. Si el archivo no esta, se devuelve null y el
     * documento lo dice; nunca revienta la generacion por una foto que falta.
     */
    protected function imagenDelDisco(string $disco, ?string $ruta): ?string
    {
        if (blank($ruta)) {
            return null;
        }

        $sistema = Storage::disk($disco);

        if (! $sistema->exists($ruta)) {
            return null;
        }

        $binario = $sistema->get($ruta);

        if (blank($binario)) {
            return null;
        }

        $tipo = @getimagesizefromstring($binario);

        // Lo que no es imagen no se incrusta: DomPDF no sabe pintar un PDF
        // adjunto dentro de otro PDF.
        if ($tipo === false || empty($tipo['mime'])) {
            return null;
        }

        return 'data:' . $tipo['mime'] . ';base64,' . base64_encode($binario);
    }

    /** Traduce si la clave existe; si no, deja el valor crudo (nunca la clave). */
    protected function traducir(string $clave, ?string $porDefecto): ?string
    {
        $texto = __($clave);

        return $texto === $clave ? $porDefecto : $texto;
    }
}
