<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Los cuatro formatos del sistema anterior, como plantillas del motor.
 *
 * En la v1 —AST, PTF, EPP e IHM— cada uno era una familia de tablas
 * (`f1_documents` y sus hijas, `f2_documents`…), su modelo, su controlador, sus
 * ocho o diez vistas y dos plantillas de PDF. Aqui son filas.
 *
 * Existia ya un camino para crearlos: `php artisan docufiz:migrate-formats`,
 * que lee los catalogos de la MySQL vieja. El problema es que **solo funciona
 * con la base vieja delante**: en una instalacion limpia —un clon nuevo, CI, el
 * portatil de cualquiera— el comando se cae con «Connection refused»,
 * `setup:project --datos` devuelve fallo antes de crearlos y el motor se queda
 * con las seis tablas a cero. Que es exactamente como estaba.
 *
 * Por eso los catalogos viajan en el repositorio
 * (`database/seeders/data/formatos-v1.json`) y esto es un seeder y no un
 * comando: sembrar tiene que dejar el sistema utilizable sin depender de una
 * base que ya no existe. Los dos caminos conviven — el comando ve la plantilla
 * ya creada y la respeta, y con `--fresh` la rehace desde la base vieja, que
 * ademas trae lo que cada cliente haya ido añadiendo a sus catalogos.
 *
 * No pasa por `FormTemplateBuilder` a proposito. El constructor es la puerta de
 * la **pantalla**: exige borrador para tocar nada, resuelve `created_by` con
 * `auth()->id()` —aqui no hay sesion— y no sabe de las columnas de nombre. Un
 * seeder escribe filas. Lo que si se conserva es su contrato: la prueba
 * `FormTemplatesSeederTest` comprueba que cada campo sembrado trae la
 * configuracion que `FormTemplateBuilder::TIPOS` exige para su tipo, de modo
 * que saltarse el constructor no signifique saltarse sus reglas.
 */
class FormTemplatesSeeder extends Seeder
{
    /** Los catalogos reales de la v1, leidos una sola vez. */
    protected array $cat;

    public function run(): void
    {
        $pais = Country::where('iso_code', 'PE')->first() ?? Country::first();

        if (! $pais) {
            $this->command?->warn('  Formatos: no hay ningun pais todavia, se omiten.');

            return;
        }

        // Los cuatro nacen en el primer workspace, que es de donde salen los
        // planes. Si aun no hay ninguno quedan globales (`tenant_id` nulo), que
        // es como el sistema representa «de todos».
        $tenant = Tenant::first();

        $datos = json_decode(file_get_contents(database_path('seeders/data/formatos-v1.json')), true);
        $this->cat = $datos['catalogos'];
        $matriz = $datos['matriz'];

        $formatos = $this->formatos($matriz);
        $creados = 0;

        foreach ($formatos as $formato) {
            $creados += $this->sembrar($formato, $pais->id, $tenant?->id) ? 1 : 0;
        }

        $this->command?->line("  Formatos: {$creados} creado(s) de " . count($formatos) . '.');
    }

    /**
     * Siembra un formato entero. Devuelve si lo ha creado.
     *
     * Idempotente por partida doble. Si la plantilla ya existe **con campos**
     * no se toca la estructura: puede tener entregas firmadas colgando, y
     * ademas el cliente ha podido editarla desde la pantalla. Solo se rellenan
     * los nombres que falten, que es reparar sin pisar.
     *
     * Si existe pero vacia —el caso de las que creo `docufiz:migrate-formats`
     * antes de que se cayera a mitad— se termina de construir.
     */
    protected function sembrar(array $formato, int $paisId, ?int $tenantId): bool
    {
        $plantilla = FormTemplate::withTrashed()
            ->where('code', $formato['code'])
            ->where('country_id', $paisId)
            ->first();

        $nueva = $plantilla === null;

        if ($nueva) {
            $plantilla = FormTemplate::create([
                'slug'       => Str::random(22),
                'country_id' => $paisId,
                'tenant_id'  => $tenantId,
                'code'       => $formato['code'],
                'name'       => $formato['name_es'],
                'name_es'    => $formato['name_es'],
                'name_en'    => $formato['name_en'],
                'kind'       => FormTemplate::STRUCTURED,
                // Como se imprime, dicho y no deducido. Los cuatro son
                // cuadriculas anchas —actividades, trabajadores, herramientas—
                // y en vertical salen con las columnas en tiras de dos letras.
                'pdf_orientation' => $formato['orientacion'] ?? null,
                // Nacen publicados: son los formatos que la obra usa desde el
                // primer dia, no un borrador que alguien tenga que revisar.
                'status'       => 'published',
                'version'      => 1,
                'published_at' => now(),
                'requires_signature' => true,
                'is_active'  => true,
            ]);
        } else {
            // `array_filter` es lo que hace que esto repare sin pisar: lo que ya
            // tiene valor se queda, y la orientacion que el cliente haya elegido
            // desde la pantalla sobrevive a volver a sembrar.
            $plantilla->fill(array_filter([
                'name'    => $plantilla->name ?: $formato['name_es'],
                'name_es' => $plantilla->name_es ?: $formato['name_es'],
                'name_en' => $plantilla->name_en ?: $formato['name_en'],
                'pdf_orientation' => $plantilla->pdf_orientation ?: ($formato['orientacion'] ?? null),
            ]))->save();

            if ($plantilla->fields()->exists()) {
                $this->repararConfigs($formato['code']);

                return false;
            }
        }

        foreach ($formato['secciones'] as $posicion => $seccion) {
            $this->sembrarSeccion($plantilla, $posicion, $seccion);
        }

        // Tambien lo recien sembrado: los catalogos de arriba estan escritos en
        // la forma corta —que es la que se lee— y la reparacion es quien
        // declara los tonos. Sin esta llamada, una instalacion nueva y una
        // reparada quedarian con configs distintas.
        $this->repararConfigs($formato['code']);

        return $nueva;
    }

    /** Una seccion y sus campos. La posicion es la clave: no hay otra. */
    protected function sembrarSeccion(FormTemplate $plantilla, int $posicion, array $seccion): void
    {
        $fila = FormSection::firstOrNew([
            'form_template_id' => $plantilla->id,
            'position'         => $posicion,
        ]);

        $fila->fill(['name_es' => $seccion['name_es'], 'name_en' => $seccion['name_en']])->save();

        foreach ($seccion['campos'] as $orden => $campo) {
            $this->sembrarCampo($fila, $orden, $campo);
        }
    }

    protected function sembrarCampo(FormSection $seccion, int $orden, array $campo): void
    {
        // `config` es lo que el tipo necesita para pintarse y nada mas. Aqui se
        // duplicaba ademas la etiqueta en `config.label`, y no era por
        // descuido: la pantalla de llenado leia `campo.config?.label` porque no
        // conocia las columnas nuevas. Ya lee el accesor `label`, asi que el
        // duplicado se fue: dos copias del mismo texto acaban divergiendo en
        // cuanto alguien edita una desde el editor de campos.
        $config = $campo['config'] ?? [];

        FormField::updateOrCreate(
            ['form_section_id' => $seccion->id, 'code' => $campo['code']],
            [
                'label_es'    => $campo['label_es'],
                'label_en'    => $campo['label_en'],
                'field_type'  => $campo['field_type'],
                'is_required' => $campo['is_required'] ?? false,
                'position'    => $orden,
                'config'      => $config,
            ],
        );
    }

    // ── Los cuatro formatos ──────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function formatos(array $matriz): array
    {
        return [$this->ast($matriz), $this->ptf($matriz), $this->epp(), $this->ihm()];
    }

    /**
     * La configuracion de la matriz de riesgo, compartida por AST y PTF.
     *
     * El nivel **no** es severidad × probabilidad. La tabla `risks` de la v1 es
     * un ranking del 1 al 25 donde el 1 es lo peor (c1 = mas grave, p1 = mas
     * probable) y el 25 lo mas leve. Doce de las veinticinco celdas caen en otra
     * banda si se multiplica: c2×p4 vale 12 en la tabla y 8 en el producto, que
     * es la diferencia entre «medio» y «alto» en un documento de seguridad. Por
     * eso viaja la tabla entera y no una formula.
     *
     * Las bandas son las de `Risk#level_name`: 1-8 alto, 9-15 medio, 16-25 bajo.
     *
     * Ojo con `severidades` y `probabilidades`: el JSON congelado trae las
     * claves internas (c1..c5, p1..p5) y NO los nombres que la v1 enseñaba
     * —«Catastrófico», «Podría suceder»—, porque esos viven en la tabla
     * `translations` de la base vieja y este seeder es justamente el camino
     * que funciona sin base delante. Los nombres reales llegan despues:
     * `docufiz:migrate-data`, cuando prepara los formatos con la base viva,
     * refresca EN SITIO estos catalogos y añade los mapas `severity_labels` /
     * `probability_labels` sobre la plantilla ya sembrada (ver
     * refrescarCatalogosDeLaMatriz() en MigrateLegacyDataCommand). Mientras
     * tanto la pantalla cae a la clave interna, que es lo que siempre hizo.
     */
    protected function configMatriz(array $matriz): array
    {
        return [
            'activities'    => $this->cat['actividades'],
            'dangers'       => $this->cat['peligros'],
            // La columna «riesgo» es la consecuencia —«Agotamiento de recurso
            // natural»—, distinta del peligro que la provoca. Son cuatro
            // columnas encadenadas en la v1: actividad → peligro → riesgo →
            // control.
            'risks'         => $this->cat['riesgos'],
            'controls'      => $this->cat['controles'],
            'severities'    => $this->cat['severidades'],
            'probabilities' => $this->cat['probabilidades'],
            'matrix'        => $matriz['valores'],
            'levels'        => $matriz['niveles'],
        ];
    }

    /**
     * AST — Analisis de Seguridad en el Trabajo.
     *
     * El orden de las secciones es el de la pantalla vieja: permisos, objetivos
     * y, abajo del todo, la matriz de actividades y peligros, que es lo que
     * ocupa la hoja entera.
     */
    protected function ast(array $matriz): array
    {
        return [
            'code'    => 'AST',
            'name_es' => 'AST (Análisis de Seguridad en el Trabajo)',
            'name_en' => 'JSA (Job Safety Analysis)',
            // Apaisado: la matriz de riesgo son cinco columnas de texto
            // largo por actividad, y en vertical no entra ninguna.
            'orientacion' => 'landscape',
            'secciones' => [
                [
                    'name_es' => 'Permisos',
                    'name_en' => 'Permits',
                    'campos'  => [
                        // `f1_documents.adrights` y `f1_documents.eqtools`. Se
                        // escriben a mano y son los dos unicos campos libres del
                        // AST; `docufiz:migrate-formats` no los traia y lo
                        // migrado los mete a empujones dentro de las
                        // observaciones, con un «Permisos adicionales: » delante.
                        [
                            'code' => 'permisos', 'field_type' => 'text',
                            'label_es' => 'Permisos de trabajo', 'label_en' => 'Work permits',
                        ],
                        [
                            'code' => 'herramientas_adicionales', 'field_type' => 'text',
                            'label_es' => 'Herramientas adicionales', 'label_en' => 'Additional tools',
                        ],
                    ],
                ],
                [
                    'name_es' => 'Objetivos',
                    'name_en' => 'Objectives',
                    'campos'  => [
                        // Pese al nombre que le puso la v1, la lista es el cierre
                        // del trabajo: retirar bloqueos, limpiar el area, guardar
                        // herramientas.
                        [
                            'code' => 'objetivos', 'field_type' => 'multiselect',
                            'label_es' => 'Al terminar el trabajo', 'label_en' => 'On finishing the work',
                            'config' => ['options' => $this->cat['objetivos']],
                        ],
                        // La v1 pinta esta lista con `display:none` y encima tiene
                        // la llamada comentada, asi que por el formulario no entra
                        // nada desde hace tiempo. Se conserva porque
                        // `f1_document_equipments` si tiene filas de cuando se
                        // enseñaba, y la migracion de datos las escribe aqui: sin
                        // el campo se perderian en silencio.
                        [
                            'code' => 'equipos', 'field_type' => 'multiselect',
                            'label_es' => 'Equipos de protección', 'label_en' => 'Protective equipment',
                            'config' => ['options' => $this->cat['equipos']],
                        ],
                    ],
                ],
                [
                    'name_es' => 'Trabajos a realizar',
                    'name_en' => 'Work to be carried out',
                    'campos'  => [
                        // Lo unico obligatorio del AST. En la v1 son dos
                        // validaciones del modelo: al menos una actividad, y cada
                        // actividad con al menos un peligro.
                        [
                            'code' => 'matriz_de_riesgo', 'field_type' => 'risk_matrix', 'is_required' => true,
                            'label_es' => 'Matriz de riesgo', 'label_en' => 'Risk matrix',
                            'config' => $this->configMatriz($matriz),
                        ],
                        $this->observaciones(),
                    ],
                ],
            ],
        ];
    }

    /**
     * PTF — Pare y Tome 5: el cuestionario de antes de empezar.
     *
     * Nada es obligatorio aqui, y no es un olvido: `F2Document` trae la
     * validacion de actividades **comentada**, asi que la v1 dejaba guardar un
     * PTF sin una sola fila de riesgos.
     */
    protected function ptf(array $matriz): array
    {
        return [
            'code'    => 'PTF',
            'name_es' => 'Pare Tome 5',
            'name_en' => 'Stop and Take 5',
            // Apaisado por la matriz de riesgo del final, igual que el AST.
            'orientacion' => 'landscape',
            'secciones' => [
                [
                    'name_es' => 'Cuestionario',
                    'name_en' => 'Questionnaire',
                    'campos'  => [
                        [
                            'code' => 'preguntas', 'field_type' => 'question_bank', 'is_required' => true,
                            'label_es' => 'Preguntas', 'label_en' => 'Questions',
                            // Dos respuestas, no tres: el formulario de la v1
                            // pinta exactamente dos radios por pregunta y el PDF
                            // solo mira `answer == 1` y `answer == 0`. Las
                            // etiquetas son las que escribe `LegacyFormMapper`
                            // (1 → «Si», 0 → «No»); cambiarlas dejaria las
                            // entregas migradas sin marcar.
                            'config' => [
                                'questions' => $this->cat['preguntas_ptf'],
                                'answers'   => ['Si', 'No'],
                                // Los cinco bloques del papel («1. ¡DETENTE y
                                // piensa antes de actuar!»…) con sus iconos
                                // REALES de la v1 —`ptf_question_titles` y sus
                                // PNG— como data URI. Ver `gruposDelPtf()`.
                                'groups'    => $this->gruposDelPtf(),
                            ],
                        ],
                    ],
                ],
                [
                    'name_es' => 'Riesgos no contemplados',
                    'name_en' => 'Risks not covered',
                    'campos'  => [
                        [
                            'code' => 'matriz_de_riesgo', 'field_type' => 'risk_matrix',
                            'label_es' => 'Matriz de riesgo', 'label_en' => 'Risk matrix',
                            'config' => $this->configMatriz($matriz),
                        ],
                        $this->observaciones(),
                    ],
                ],
            ],
        ];
    }

    /** EPP — una fila por trabajador del plan, con sus items de proteccion. */
    protected function epp(): array
    {
        return [
            'code'    => 'EPP',
            'name_es' => 'Inspección de EPP (Equipos de Protección de Seguridad)',
            'name_en' => 'PPE Inspection (Personal Protective Equipment)',
            // Apaisado: una columna por equipo de proteccion y una fila por
            // trabajador. En vertical los rotulos salen en tiras de dos letras.
            'orientacion' => 'landscape',
            'secciones' => [
                [
                    'name_es' => 'Inspección',
                    'name_en' => 'Inspection',
                    'campos'  => [
                        [
                            'code' => 'epp_por_trabajador', 'field_type' => 'person_checklist', 'is_required' => true,
                            'label_es' => 'EPP por trabajador', 'label_en' => 'PPE per worker',
                            'config' => [
                                'items'   => $this->cat['epp'],
                                // Los equipos, repartidos por parte del cuerpo,
                                // que es como estaban en la v1
                                // (`epp_categories`) y como se revisa uno a si
                                // mismo: de la cabeza a los pies. Es el UNICO
                                // formato con grupos, y por eso `groups` es
                                // opcional en el tipo de campo.
                                //
                                // Es una VISTA sobre `items`, no otra lista: el
                                // catalogo sigue siendo `items` —es lo que se
                                // guarda en cada respuesta y lo que alinea las
                                // columnas del PDF— y esto solo dice bajo que
                                // rotulo va cada uno. Un equipo que alguien
                                // añada al catalogo y olvide meter en un grupo
                                // sale igual, al final y sin rotulo, en vez de
                                // desaparecer de la pantalla.
                                'groups'  => $this->gruposDeEpp(),
                                // 1 conforme, 2 no conforme, 0 no aplica en la v1.
                                // Se guarda la etiqueta y nunca el numero: si se
                                // guardara la posicion, reordenar la lista
                                // cambiaria el significado de lo ya firmado.
                                // De la buena a la mala, con «no aplica» en
                                // medio: es el orden en que se lee y evita el
                                // toque equivocado por prisa, que en un EPP
                                // significa dar por bueno un arnes roto.
                                'answers' => ['Conforme', 'No aplica', 'No conforme'],
                                // Las tres columnas del final del papel, que solo
                                // se llenan cuando algo sale no conforme.
                                'extra'   => ['correction_measure', 'deadline_date', 'correction_verification'],
                            ],
                        ],
                        $this->observaciones(),
                    ],
                ],
            ],
        ];
    }

    /**
     * Repara la config de los campos de un formato YA sembrado, sin pisar nada.
     *
     * El seeder no toca la estructura de una plantilla con campos —puede tener
     * entregas firmadas y ediciones del cliente— pero hay arreglos que SI
     * tienen que llegar a lo existente, porque el codigo nuevo los da por
     * hechos. Se aplican a TODAS las versiones del codigo: la config viaja
     * copiada en cada version, y reparar solo la vigente dejaria el fallo vivo
     * en los documentos que se leen por su version congelada.
     *
     * Dos reparaciones, las dos aditivas:
     *
     *  1. El `extra` del IHM gana `correction_verification` si no lo tiene. El
     *     PDF imprime ese hueco siempre que algo sale no conforme, y sin la
     *     clave en la config el formulario no ofrecia donde llenarlo (#57).
     *
     *  2. Toda respuesta de catalogo que sea una cadena suelta pasa a declarar
     *     su tono. NO cambia ningun comportamiento — el tono declarado es
     *     exactamente el que la deduccion ya daba para esa cadena — pero deja
     *     los formatos sembrados independientes de la heuristica del
     *     castellano, que existe solo por compatibilidad. El VALOR no se toca:
     *     es lo que casa con las 14 000 entregas.
     */
    protected function repararConfigs(string $codigo): void
    {
        $reglas = app(\App\Services\FieldWork\FormFindingsService::class);

        $campos = \App\Models\FormField::whereHas(
            'section.formTemplate',
            fn ($q) => $q->withTrashed()->where('code', $codigo),
        )->whereIn('field_type', ['person_checklist', 'tool_checklist', 'question_bank'])->get();

        foreach ($campos as $campo) {
            $config = $campo->config ?? [];
            $tocado = false;

            if ($campo->field_type === 'tool_checklist') {
                $extra = array_values(array_filter((array) ($config['extra'] ?? []), 'is_string'));

                if (! in_array('correction_verification', $extra, true)) {
                    $config['extra'] = [...$extra, 'correction_verification'];
                    $tocado = true;
                }
            }

            // El PTF que ya estaba sembrado gana sus bloques, si nadie le
            // configuro unos propios. Es la misma politica del `extra` del IHM:
            // aditiva y sin pisar — un cliente que ya armo sus grupos desde el
            // editor no ve cambiar nada.
            if ($campo->field_type === 'question_bank'
                && blank($config['groups'] ?? null)
                && $this->gruposDelPtf() !== []) {
                $config['groups'] = $this->gruposDelPtf();
                $tocado = true;
            }

            $respuestas = $config['answers'] ?? null;

            if (is_array($respuestas)) {
                $declaradas = array_map(
                    fn ($r) => is_string($r)
                        ? ['value' => $r, 'tone' => $reglas->tonoDeducido($r)]
                        : $r,
                    $respuestas,
                );

                if ($declaradas !== $respuestas) {
                    $config['answers'] = $declaradas;
                    $tocado = true;
                }
            }

            if ($tocado) {
                $campo->update(['config' => $config]);
            }
        }
    }

    /**
     * Los grupos del EPP, del catalogo que viaja en el repositorio.
     *
     * DOS COSAS QUE HAY QUE SABER DE ESTOS DATOS
     * ------------------------------------------
     * 1. En la v1 los grupos son `epp_categories`, una tabla con su pantalla de
     *    mantenimiento. Aqui no hay tabla nueva: es configuracion de un campo
     *    del motor, editable desde el editor de formatos como el resto.
     *
     * 2. El seed de la v1 tiene un ERROR en tres de los veinticinco equipos:
     *    «Mandil de cuero», «Escarpines de cuero» y «Traje anti arco electrico»
     *    estan en la categoria 4, que es «Ojos». Un mandil no es proteccion
     *    ocular; lo que paso es que alguien corrio el limite de la categoria
     *    tres al copiar la lista. Aqui van en «Cuerpo», que es donde tienen
     *    sentido. Se dice por escrito para que nadie lo «corrija» de vuelta
     *    comparando con la base vieja, y porque si el cliente prefiere lo otro
     *    es una linea del JSON.
     *
     * @return array<int, array{name: string, items: array<int, string>}>
     */
    /**
     * Los cinco bloques del Pare y Tome 5, con sus iconos reales.
     *
     * Vienen de `ptf_question_titles` de la v1 —titulo en tres idiomas y su
     * PNG (el semaforo, la cabeza, las herramientas, la lupa, el pulgar)— y el
     * reparto de preguntas es el de `ptf_questions.ptf_question_title_id`, tal
     * cual estaba alla. El icono viaja como data URI dentro del catalogo del
     * repositorio: sin almacen de archivos, se congela con cada version del
     * formato y DomPDF lo imprime sin salir a la red.
     */
    protected function gruposDelPtf(): array
    {
        return array_map(
            fn (array $bloque) => [
                'name'  => $bloque['name'],
                'items' => $bloque['items'],
                'image' => $bloque['image'] ?? null,
            ],
            $this->cat['ptf_bloques'] ?? [],
        );
    }

    protected function gruposDeEpp(): array
    {
        return array_map(
            fn (array $grupo) => ['name' => $grupo['nombre'], 'items' => $grupo['items']],
            $this->cat['epp_grupos'] ?? [],
        );
    }

    /** IHM — inspeccion de herramientas manuales y electricas portatiles. */
    protected function ihm(): array
    {
        return [
            'code'    => 'IHM',
            'name_es' => 'Inspección de Herramientas Manuales y Eléctricas Portátiles',
            'name_en' => 'Hand and Portable Power Tool Inspection',
            // Apaisado: veinte puntos de inspeccion por herramienta.
            'orientacion' => 'landscape',
            'secciones' => [
                [
                    'name_es' => 'Inspección',
                    'name_en' => 'Inspection',
                    'campos'  => [
                        [
                            'code' => 'inspeccion_de_herramientas', 'field_type' => 'tool_checklist', 'is_required' => true,
                            'label_es' => 'Inspección de herramientas', 'label_en' => 'Tool inspection',
                            'config' => [
                                // El nombre de la herramienta se escribe a mano:
                                // en la v1 es un texto con autocompletado contra
                                // `ihm_tools`, no un desplegable cerrado.
                                'tools'   => $this->cat['herramientas_ihm'],
                                'items'   => $this->cat['puntos_ihm'],
                                // Mismos codigos que el EPP —1 cumple, 2 no
                                // cumple, 0 no aplica— y por eso mismo orden: el
                                // positivo primero. `docufiz:migrate-formats` los
                                // listaba al reves solo en este formato.
                                'answers' => ['Cumple', 'No aplica', 'No cumple'],
                                // `f4_document_tools.correction_measure` y
                                // `responsible`, que no se estaban trayendo. La
                                // tercera columna del papel, `is_enabled`, no hace
                                // falta: la v1 la marcaba sola con JavaScript
                                // segun las respuestas, y aqui es el `conforme`
                                // que ya calcula el propio campo.
                                //
                                // `correction_verification` va porque el PDF la
                                // IMPRIME siempre que algo sale no conforme
                                // (`ToolChecklistPdf::VERIFICACION`: el hueco de
                                // la verificacion se enseña aunque nadie lo
                                // declare). Sin ella aqui, el papel decia
                                // «Verificacion de la correccion: sin registrar»
                                // y el formulario no ofrecia donde registrarla —
                                // un hueco imposible de llenar, que ademas es lo
                                // que atrapaba el plan (#57).
                                'extra'   => ['correction_measure', 'responsible', 'correction_verification'],
                            ],
                        ],
                        $this->observaciones(),
                    ],
                ],
            ],
        ];
    }

    /**
     * El cajon de observaciones, igual en los cuatro.
     *
     * Ojo con el nombre: en la v1 `observations` es un **entero**, la cuenta de
     * hallazgos que calculaba `set_completed`. Eso aqui es
     * `form_submissions.nonconformities`, que lleva `FormFindingsService`. Este
     * campo es otra cosa: texto libre, y es ademas donde la migracion de datos
     * deja los permisos y las herramientas del AST.
     */
    protected function observaciones(): array
    {
        return [
            'code' => 'observaciones', 'field_type' => 'textarea',
            'label_es' => 'Observaciones', 'label_en' => 'Remarks',
        ];
    }
}
