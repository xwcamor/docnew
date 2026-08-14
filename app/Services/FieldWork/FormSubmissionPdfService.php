<?php

namespace App\Services\FieldWork;

use App\Support\Catalogo;
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
use App\Services\FieldWork\SignatureService;
use App\Support\PrivateInfo;
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
    /** Tipos que se pintan como tabla generica; el resto son pares etiqueta/valor. */
    protected const COMPUESTOS = [
        'table', 'risk_matrix', 'person_checklist', 'tool_checklist', 'question_bank',
    ];

    /**
     * Los que tienen plantilla propia, y por que hizo falta.
     *
     * Los cuatro campos de obra caian en `tabla()`, que es un volcador
     * generico: saca las cabeceras de las CLAVES del JSON guardado. En el papel
     * eso salia como «Person id | Legacy plan worker id | Correction measure |
     * Deadline date | Correction verification | Items» —en ingles, porque son
     * los nombres de las columnas de la base— y la ultima columna era un
     * parrafo con los veinticinco items del EPP concatenados por comas. El
     * dueño del producto, viendolo: «que carajos es eso y por que esta en
     * ingles».
     *
     * No era un fallo de formato: es que estos cuatro **nunca se maquetaron**
     * para el papel. Cada uno tiene ahora su clase, que resuelve el dato con
     * las etiquetas del catalogo y sin identificadores internos, y su parcial,
     * que lo pinta como el formato correspondiente de la v1.
     *
     * `table` NO esta aqui a proposito: es el campo generico del motor, sus
     * columnas las define quien configura el formato y no hay forma de saber
     * como se pinta. Para ese, el volcador generico es lo correcto.
     */
    protected const CON_PARCIAL = [
        'risk_matrix'      => \App\Services\FieldWork\Pdf\RiskMatrixPdf::class,
        'person_checklist' => \App\Services\FieldWork\Pdf\PersonChecklistPdf::class,
        'tool_checklist'   => \App\Services\FieldWork\Pdf\ToolChecklistPdf::class,
        'question_bank'    => \App\Services\FieldWork\Pdf\QuestionBankPdf::class,
    ];

    /**
     * Tipos cuyo valor no esta en `form_answers` sino en `form_attachments`.
     *
     * La lista se mudo a `FormField` porque este servicio no era el unico que
     * la necesitaba: `faltantesDetallados()` la ignoraba, y por eso un campo de
     * foto obligatorio no dejaba confirmar el formato nunca. Referenciada y no
     * copiada para que las dos no puedan volver a decir cosas distintas — y
     * asi la firma, que se acaba de sumar, se pinta aqui sin tocar nada.
     */
    protected const CON_ARCHIVO = \App\Models\FormField::CON_ARCHIVO;

    /**
     * Genera el PDF listo para descargar, **en el idioma del pais del plan**.
     *
     * No en el de quien pulsa el boton, que es como estaba. Este PDF no es una
     * pantalla: es el documento de seguridad de un trabajo hecho en un sitio
     * concreto, y puede acabar delante de un inspector de ese pais. Un AST de
     * Lurin impreso por alguien que tiene la aplicacion en ingles salia en
     * ingles — el mismo trabajo, dos documentos distintos segun quien lo baje.
     *
     * El idioma sale de `countries.default_locale_id`: Peru → es_PE → español.
     * Se restaura al terminar aunque el render reviente, porque cambiar el
     * idioma de la peticion a medias dejaria la respuesta siguiente en el
     * idioma equivocado.
     */
    /**
     * Como se imprime este formato: lo dice el formato.
     *
     * Aqui se deducia —«si lleva matriz de riesgo, apaisado»— y eso servia para
     * el AST y era falso para todo lo demas: el EPP y la inspeccion de
     * herramientas son cuadriculas anchas sin ninguna matriz, y en vertical
     * salen con las columnas en tiras de dos letras.
     *
     * Ahora es un ajuste del documento (`pdf_orientation`), que es donde tiene
     * que estar: el motor deja definir formatos desde la pantalla y adivinar
     * como se imprime uno nuevo mirando que campos lleva es adivinar.
     *
     * En nulo se sigue deduciendo, que es lo que hacia antes: un formato viejo
     * o uno recien creado por alguien que no lo penso no cambia de aspecto solo
     * porque exista la columna.
     */
    protected function orientacion(FormSubmission $entrega): string
    {
        $plantilla = $entrega->formTemplate;

        $dicho = $plantilla?->pdf_orientation;

        if (in_array($dicho, ['portrait', 'landscape'], true)) {
            return $dicho;
        }

        $apaisado = $plantilla && $plantilla->sections()
            ->whereHas('fields', fn ($q) => $q->where('field_type', 'risk_matrix'))
            ->exists();

        return $apaisado ? 'landscape' : 'portrait';
    }

    public function generar(FormSubmission $entrega, ?User $usuario = null): \Barryvdh\DomPDF\PDF
    {
        $anterior = app()->getLocale();
        $delPais  = $this->idiomaDelPlan($entrega);

        if ($delPais) {
            app()->setLocale($delPais);
        }

        try {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView(
                'field_work.form_submissions.pdf.template',
                $this->datos($entrega, $usuario),
            )
                ->setPaper('a4', $this->orientacion($entrega))
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
        } finally {
            app()->setLocale($anterior);
        }
    }

    /**
     * El idioma del pais donde se hizo el trabajo, o null si no se puede saber.
     *
     * Devolver null y quedarse con el idioma de la peticion es mejor que
     * adivinar: un documento en el idioma de quien lo pide es raro, pero uno en
     * un idioma elegido al azar es peor.
     */
    protected function idiomaDelPlan(FormSubmission $entrega): ?string
    {
        $iso = $entrega->workPlan?->country?->defaultLocale?->language?->iso_code;

        if (! $iso) {
            return null;
        }

        // Sin archivos de idioma para ese ISO, el `__()` devolveria la clave
        // cruda («work_plans.code») en todo el documento. Mejor el de la peticion.
        return is_dir(lang_path($iso)) ? $iso : null;
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

    /**
     * Logo, nombre y descargo del workspace.
     *
     * Sin la direccion: estaba en la esquina y no la pide nadie. Un formato de
     * seguridad se archiva por el trabajo que documenta —el plan, la
     * contratista, el sitio— y todo eso ya va en la cabecera del plan, que es
     * ademas la direccion que importa. La del workspace solo gastaba la linea
     * donde ahora cabe el titulo.
     */
    protected function membrete(?\App\Models\Tenant $tenant): array
    {
        return [
            'nombre'     => $tenant?->name ?: config('app.name'),
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
            // La sigla del documento, del catalogo de la empresa. El PDF es lo
            // que se ensena en la puerta, y ahi «RUC 20…» rotulando el numero de
            // una contratista chilena es una etiqueta falsa: el suyo es el RUT.
            'empresa_doc_tipo' => $plan->company?->doc_type,
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
        $laQueVale = $plantilla ?? $entrega->formTemplate;

        return [
            'codigo'        => $laQueVale?->code,
            // Como se llama el documento, que es lo que va de titulo. La
            // cabecera ponia el codigo —«IHM», «EPP»— y eso es la sigla con la
            // que se archiva, no el nombre: en el papel de la v1 el titulo es
            // «Inspeccion de Herramientas Manuales y Electricas Portatiles» y
            // la sigla va pequeña al lado de la version. El accesor `label`
            // devuelve el nombre en el idioma en curso y cae al codigo solo si
            // no hay ninguno, que es mejor cabecera que una vacia.
            'nombre'        => $laQueVale?->label,
            'tipo'          => $plantilla?->kind ?? FormTemplate::STRUCTURED,
            'version'       => (int) $entrega->template_version,
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
                // El titulo de la seccion si lo tiene, y nada si no.
                //
                // Aqui se numeraban: «SECCION 1», «SECCION 2». El dueño del
                // producto: «no tiene sentido si no tienen titulos». Y es
                // cierto — esa numeracion no es del documento, es del orden
                // interno de la plantilla, y en el papel de la v1 no existe. Un
                // encabezado que solo dice el numero de si mismo gasta una
                // linea para no informar de nada.
                'titulo' => $seccion->label ?: null,
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

        // Los cuatro de obra se resuelven en su clase y se pintan en su
        // parcial. El modelo `FormField` entero y no la etiqueta ya resuelta:
        // lo que necesitan esta en su `config` —los items del catalogo, las
        // severidades, las bandas de riesgo, los mapas de etiquetas— y de ahi
        // no sale hoy nada del servicio.
        if ($parcial = self::CON_PARCIAL[$campo->field_type] ?? null) {
            return $base + [
                'render'  => 'campo',
                'parcial' => 'field_work.form_submissions.pdf.campos.' . $campo->field_type,
                'datos'   => $parcial::datos($campo, $respuestas),
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
        // El accesor del modelo, que es el que sabe de idiomas.
        //
        // Aqui habia una copia que solo miraba `config['label']` y `title`, y
        // se escribio antes de que el campo tuviera columnas `label_es` y
        // `label_en`. Resultado: el campo del PTF esta sembrado con «Preguntas»
        // y «Questions», y el PDF en ingles imprimia «Preguntas» — porque la
        // copia no leia esas columnas y caia a humanizar el `code`, que esta en
        // español. Un formato que se pide en ingles y sale medio en español.
        //
        // `config['title']` se conserva porque hay plantillas viejas que lo
        // llevan ahi y el accesor no lo mira.
        $rotulo = $campo->label;

        if (filled($rotulo) && $rotulo !== ucfirst(str_replace('_', ' ', (string) $campo->code))) {
            return $rotulo;
        }

        $titulo = $campo->config['title'] ?? null;

        return filled($titulo) && is_string($titulo) ? $titulo : $rotulo;
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
        //
        // Por VALOR, que es la clave con la que la pantalla guarda cada celda.
        // Una columna puede venir con rotulo traducido (`{value, label}`) y
        // entonces el rotulo es lo que se lee y el valor lo que casa con la
        // fila; leerlas como cadenas sueltas —que es lo que se hacia— dejaba
        // fuera del papel toda columna que se hubiera traducido.
        foreach (Catalogo::entradas($campo->config['columns'] ?? []) as $columna) {
            $claves[$columna['value']] = true;
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
     * Las firmas de la entrega y las del plan del que cuelga, POR GRUPO.
     *
     * Se firman tres cosas en un dia de obra —el formato entregado, cada
     * trabajador de la cuadrilla y cada aprobacion del flujo— y salian las tres
     * revueltas en una sola tabla ordenada por hora. Quien lee el documento
     * quiere saber otra cosa: quien estuvo en el trabajo, y quien lo autorizo.
     * Son dos preguntas distintas y ahora son dos tablas, que ademas es como
     * esta el papel de la v1 y como esta la ficha del plan en pantalla.
     *
     * Devuelve `['trabajadores' => …, 'aprobadores' => …, 'entrega' => …]`. Los
     * grupos vacios se quedan vacios y la plantilla no los pinta: un plan sin
     * flujo de aprobacion no tiene que enseñar una tabla en blanco.
     *
     * Lo que no cambia: las firmas pendientes de revision salen marcadas en vez
     * de disimuladas. Es lo que da valor al bloque.
     *
     * @return array{trabajadores: list<array>, aprobadores: list<array>, entrega: list<array>}
     */
    protected function firmas(FormSubmission $entrega, ?User $usuario): array
    {
        $planId = $entrega->work_plan_id;

        $trabajadores = WorkPlanPerson::where('work_plan_id', $planId)->pluck('id')->all();

        // Con el rotulo de cada aprobacion: ahi la palabra SI informa, al reves
        // que en la tabla de trabajadores, donde eran quince veces «Trabajador»
        // gastando ancho.
        //
        // Y el rotulo es el NOMBRE DE LA REGLA —«Supervisor Autorizante -
        // HITACHI»— no el rol generico. El rol dice que clase de persona firma;
        // el nombre dice por parte de quien, que es lo que se busca en un
        // documento que va a manos de la contratista principal. Salia
        // «Supervisor» a secas, y en un plan con dos supervisores de empresas
        // distintas eso no distingue nada. Es ademas el mismo texto que ya
        // enseña la ficha del plan (`rule_name` en WorkPlanController), asi que
        // el papel y la pantalla dejan de contar cosas distintas.
        $aprobaciones = WorkPlanApproval::with('approvalRule.role')
            ->where('work_plan_id', $planId)
            ->get()
            ->mapWithKeys(fn (WorkPlanApproval $a) => [
                $a->id => $a->approvalRule?->name ?: $a->approvalRule?->role?->label,
            ])
            ->all();

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
                        ->whereIn('signable_id', array_keys($aprobaciones)));
                }
            })
            ->orderBy('signed_at')
            ->orderBy('id')
            ->get();

        // Quien puede ver el documento entero y la firma trazada. Es el mismo
        // permiso que en pantalla (`people.view_private_info`, super y admin) y
        // se resuelve UNA vez: da igual quien pulse el boton, lo que decide es
        // quien lo pulsa, y son 231 personas posibles en un plan grande.
        $aCara = PrivateInfo::visibleFor($usuario);

        $deAprobacion = (new WorkPlanApproval)->getMorphClass();
        $deTrabajador = (new WorkPlanPerson)->getMorphClass();

        // Quien responde por la cuadrilla, para decirlo AL LADO DE SU FIRMA.
        // El dueño del producto lo pidio mirando el papel: en la tabla de
        // trabajadores no se distinguia al representante de los demas, y ese
        // nombre es el que responde por el equipo ante quien autoriza.
        $representante = $entrega->workPlan?->crew_representative_person_id;

        // Y CON LA EMPRESA DICHA: «Representante de la HITACHI», no «de los
        // trabajadores» — tambien palabras suyas. El nombre corto de la
        // contratista del plan; sin el, la forma generica.
        $nombreEmpresa = $entrega->workPlan?->company?->name;
        $rotuloRepresentante = filled($nombreEmpresa)
            ? __('work_plans.representative_of', ['company' => $nombreEmpresa])
            : __('work_plans.representative');

        $filas = $eventos->map(fn (SignatureEvent $e) => [
            'nombre'     => $e->person?->full_name,
            // De que grupo es esta firma y, si es una aprobacion, con que rol
            // se firmo. El rol sale de la REGLA y no del cargo de la persona:
            // lo que autoriza el trabajo es el papel que ocupa en el flujo.
            'grupo'      => match ($e->signable_type) {
                $deAprobacion => 'aprobadores',
                $deTrabajador => 'trabajadores',
                default       => 'entrega',
            },
            'rol'        => $e->signable_type === $deAprobacion
                ? ($aprobaciones[$e->signable_id] ?? null)
                : null,
            // La marca del representante, SOLO en la tabla de trabajadores: en
            // la de aprobadores el papel de cada firma ya lo dice su rol. Va
            // el ROTULO ya armado (o null), no un booleano: el texto lleva
            // dentro el nombre de la empresa y armarlo aqui deja a la
            // plantilla solo pintarlo.
            'representante' => $e->signable_type === $deTrabajador
                && $representante !== null
                && $e->person_id === $representante
                ? $rotuloRepresentante : null,
            // Enmascarado en el SERVICIO, no en la plantilla: un PDF se
            // descarga y se reenvia, y taparlo al pintar dejaria el numero
            // entero en cualquier sitio donde se pase el dato sin pintarlo.
            'documento'  => PrivateInfo::documento($e->person?->num_doc, $usuario),
            'hora'       => Tz::format($e->signed_at, $usuario),
            'metodo'     => $this->traducir('form_submissions.pdf.methods.' . $e->method, $e->method),
            'pendiente'  => (bool) $e->pending_review,
            // El porcentaje, no la distancia ni el umbral. Un informe que
            // acaba delante de un inspector no puede pedirle que sepa que
            // «0.15» es bueno y «0.55» no, ni que el umbral es la linea. La
            // pantalla ya lo dice asi desde hace unas semanas; el PDF se habia
            // quedado atras y contaba lo mismo de otra manera.
            'coincidencia' => $e->match_percent,
            'motivo'     => $e->manual_override ? $e->override_reason : null,
            // La FIRMA TRAZADA, no la foto de evidencia.
            //
            // La columna se llamaba «Evidencia» y traia la cara capturada al
            // firmar. Dos problemas: en un documento firmado lo que se espera
            // ver al lado de un nombre es su firma, que es lo que hay en el
            // papel de la v1; y la cara solo existe cuando el reconocimiento
            // FALLO, asi que en la mayoria de las filas la columna salia vacia
            // —«Sin foto» repetido— justo en las firmas que mejor salieron.
            //
            // Va con el mismo permiso que el documento: sin el, ni imagen ni
            // hueco reservado.
            'firma'      => $aCara ? $this->firmaTrazada($e) : null,
        ]);

        // Los tres grupos SIEMPRE, aunque esten vacios: la plantilla decide si
        // los pinta, y un array al que le falta una clave revienta al pintarlo.
        return [
            'trabajadores' => $filas->where('grupo', 'trabajadores')->values()->all(),
            'aprobadores'  => $filas->where('grupo', 'aprobadores')->values()->all(),
            'entrega'      => $filas->where('grupo', 'entrega')->values()->all(),
        ];
    }

    /** La cara que se capturo al firmar, leida del disco privado. */
    protected function fotoDeEvidencia(SignatureEvent $evento): ?string
    {
        $archivo = $evento->files->firstWhere('kind', EvidenceFile::FACE);

        return $archivo ? $this->imagenDelDisco('local', $archivo->file_path) : null;
    }

    /**
     * La firma trazada de quien firmo, que es lo que va en el papel.
     *
     * Se busca la vigente de la persona y no una copia por evento: la firma se
     * dibuja UNA vez y a partir de ahi se reutiliza —esa es la regla de la
     * pantalla de firmar—, asi que guardarla otra vez por cada plan seria la
     * misma imagen repetida miles de veces.
     *
     * Las que apuntan a `legacy/` no valen: son el marcador que dejo la
     * importacion a la espera de un fichero que en muchos casos no llego.
     */
    protected function firmaTrazada(SignatureEvent $evento): ?string
    {
        $persona = $evento->person;

        if (! $persona) {
            return null;
        }

        $firma = app(SignatureService::class)->firmaVigente($persona);

        return $firma ? $this->imagenDelDisco('local', $firma->file_path) : null;
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
