<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\FormTemplate;
use App\Models\Tenant;
use App\Models\WorkType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los formatos ADICIONALES del cliente: cinco permisos de trabajo (PTW) y tres
 * inspecciones, transcritos de sus Excel y PDF originales.
 *
 * Son los papeles que la obra usa ademas de los cuatro de la v1 (AST, PTF,
 * EPP, IHM): el permiso de trabajos en altura, el de espacios confinados, los
 * dos electricos, el plan de izaje, y las inspecciones de arnes, del kit de
 * revelado y de los elementos de izaje. El dueño del producto entrego los
 * archivos fuente y pidio incorporarlos «como adicionales» y añadirlos al
 * catalogo de tipos de trabajo.
 *
 * DECISIONES DE MAPEO, y por que:
 *
 *  - **Los textos van VERBATIM**, erratas incluidas («impactio», «PERTIGA
 *    DIELCTRICA», «Comunicacion»): el formato migrado tiene que decir lo que
 *    decia el papel que la gente conoce, no una version corregida que ya no
 *    casa con el estandar impreso que cuelga en la obra.
 *  - **Las firmas del papel no son campos.** Personal participante, emisor,
 *    receptor, supervisores: todo eso lo cubre el flujo del plan (cuadrilla y
 *    aprobaciones firman con reconocimiento), igual que se decidio con los
 *    cuatro formatos de la v1. Duplicarlo dentro del formato seria pedir dos
 *    veces la misma firma.
 *  - **Los calculos del papel son campos numericos, no formulas**: el motor no
 *    calcula (tampoco lo hacia el papel — se llenaba a mano) y la constante o
 *    la formula se dice en el rotulo del campo, que es donde el papel la decia.
 *  - **Las respuestas declaran su tono** ({value, tone}) desde el primer dia:
 *    ninguno de estos formatos depende de la heuristica del castellano.
 *  - **Las fotos de los grupos del Kit** viajan como data URI reducidos a
 *    ~160px, el mismo mecanismo que los iconos del PTF: el PDF solo incrusta
 *    `data:image/` y el editor permite cambiarlas despues.
 *
 * Hereda de FormTemplatesSeeder para reusar `sembrar()` —la idempotencia por
 * partida doble: plantilla con campos no se toca, vacia se termina de
 * construir— y `repararConfigs()`.
 */
class FormatosAdicionalesSeeder extends FormTemplatesSeeder
{
    /** Los catalogos verbatim de los archivos del cliente. */
    protected array $add;

    public function run(): void
    {
        $pais = Country::where('iso_code', 'PE')->first() ?? Country::first();

        if (! $pais) {
            $this->command?->warn('  Formatos adicionales: no hay ningun pais todavia, se omiten.');

            return;
        }

        $tenant = Tenant::first();

        $this->add = json_decode(
            file_get_contents(database_path('seeders/data/formatos-adicionales.json')),
            true,
        );

        $formatos = [
            $this->ptwAltura(),
            $this->ptwEspaciosConfinados(),
            $this->ptwElectricalSafety(),
            $this->ptwPruebasElectricas(),
            $this->ptwIzaje(),
            $this->inspeccionArnes(),
            $this->inspeccionKit(),
            $this->inspeccionIzaje(),
        ];

        $creados = 0;

        foreach ($formatos as $formato) {
            $creados += $this->sembrar($formato, $pais->id, $tenant?->id) ? 1 : 0;
        }

        $this->tiposDeTrabajo($pais->id, $tenant?->id);

        $this->command?->line("  Formatos adicionales: {$creados} creado(s) de " . count($formatos) . '.');
    }

    /**
     * Las reparaciones del padre NO aplican aqui, y anularlas es a proposito.
     *
     * `repararConfigs()` existe para curar los formatos de la v1 ya sembrados:
     * mete `correction_verification` en todo tool_checklist (la regla del IHM),
     * planta los bloques del PTF y declara tonos donde faltan. Estos formatos
     * nacen completos —tonos declarados, grupos propios— y sus cuadriculas
     * llevan la columna que su papel lleva («Condición Identificada»), no la
     * del IHM: dejar correr la reparacion del padre les añadiria un hueco de
     * verificacion que su papel no tiene.
     */
    protected function repararConfigs(string $codigo): void
    {
        // Nada que reparar: ver el docblock.
    }

    // ── Catalogos de respuesta, con su tono declarado ───────────────────────

    /** SI / NO / N/A — el trio de los permisos de trabajo. */
    protected function siNoNa(): array
    {
        return [
            ['value' => 'SI', 'tone' => 'ok'],
            ['value' => 'N/A', 'tone' => 'na'],
            ['value' => 'NO', 'tone' => 'bad'],
        ];
    }

    /** Conforme / No aplica / No conforme — el trio de las inspecciones. */
    protected function conformeNa(): array
    {
        return [
            ['value' => 'Conforme', 'tone' => 'ok'],
            ['value' => 'No aplica', 'tone' => 'na'],
            ['value' => 'No conforme', 'tone' => 'bad'],
        ];
    }

    // ── Los cinco permisos de trabajo ───────────────────────────────────────

    protected function ptwAltura(): array
    {
        $c = $this->add['ptw_alt'];

        return [
            'code'    => 'PTW-ALT',
            'name_es' => 'PTW Trabajos en altura',
            'name_en' => 'PTW Working at height',
            'secciones' => [
                $this->datosDelPermiso(),
                [
                    'name_es' => 'Lista de verificación',
                    'name_en' => 'Checklist',
                    'campos'  => [[
                        'code' => 'verificacion', 'field_type' => 'question_bank', 'is_required' => true,
                        'label_es' => 'Lista de verificación', 'label_en' => 'Checklist',
                        'config' => ['questions' => $c['preguntas'], 'answers' => $this->siNoNa()],
                    ]],
                ],
                $this->eppRequerido($c['epp']),
                [
                    'name_es' => 'Evaluación distancia total de caída',
                    'name_en' => 'Total fall distance assessment',
                    'campos'  => [
                        [
                            'code' => 'trabajos_mayor_54', 'field_type' => 'radio',
                            // El texto del gatillo, tal cual lo dice el papel.
                            'label_es' => 'Realizará trabajos mayor a 5.4 m (si su respuesta es SI, realice la siguiente evaluación; si es NO, tenga en cuenta las consideraciones especiales)',
                            'label_en' => 'Will work above 5.4 m be performed (if YES, complete the assessment below; if NO, apply the special considerations)',
                            'config' => ['options' => ['SI', 'NO']],
                        ],
                        $this->numero('distancia_linea_anclaje', '(a) Distancia de línea de anclaje (m)', '(a) Lanyard length (m)'),
                        $this->numero('distancia_desaceleracion', '(b) Distancia de desaceleración — absorbedor de impacto (m). Referencia: frenado de tambor retráctil 0.10, desaceleración 1.10', '(b) Deceleration distance — shock absorber (m). Reference: retractable drum braking 0.10, deceleration 1.10'),
                        $this->numero('distancia_anillo_pies', '(d) Distancia anillo de espalda a los pies (m)', '(d) Back D-ring to feet distance (m)'),
                        $this->numero('distancia_total_caida', '(A) Distancia Total de Caída = a + b + 0.3 (estiramiento del arnés) + d + 0.3 (factor de seguridad) (m)', '(A) Total fall distance = a + b + 0.3 (harness stretch) + d + 0.3 (safety factor) (m)'),
                        $this->numero('distancia_anclaje_piso', '(B) Distancia total desde el punto de anclaje hasta el nivel del piso (m)', '(B) Total distance from anchor point to ground level (m)'),
                        [
                            'code' => 'altura_adecuada', 'field_type' => 'radio',
                            'label_es' => 'Si (B) > (A), la altura para realizar trabajos es adecuada',
                            'label_en' => 'If (B) > (A), the working height is adequate',
                            'config' => ['options' => ['Si', 'No']],
                        ],
                        $this->numero('nueva_distancia_total', '(C) La nueva Distancia Total de Caída, re-evaluando la altura del punto de anclaje o con línea de anclaje regulable (m)', '(C) New total fall distance, after re-assessing the anchor point height or with an adjustable lanyard (m)'),
                        [
                            'code' => 'altura_adecuada_reevaluada', 'field_type' => 'radio',
                            'label_es' => 'Si (B) > (C), la altura para realizar trabajos es adecuada',
                            'label_en' => 'If (B) > (C), the working height is adequate',
                            'config' => ['options' => ['Si', 'No']],
                        ],
                    ],
                ],
                $this->cierreConObservaciones(),
            ],
        ];
    }

    protected function ptwEspaciosConfinados(): array
    {
        $c = $this->add['ptw_con'];

        return [
            'code'    => 'PTW-CON',
            'name_es' => 'PTW Espacios confinados',
            'name_en' => 'PTW Confined spaces',
            'secciones' => [
                $this->datosDelPermiso(),
                [
                    'name_es' => 'Lista de verificación',
                    'name_en' => 'Checklist',
                    'campos'  => [[
                        'code' => 'verificacion', 'field_type' => 'question_bank', 'is_required' => true,
                        'label_es' => 'Lista de verificación', 'label_en' => 'Checklist',
                        // El papel solo imprime SI y N/A; el NO se ofrece igual
                        // porque un requisito incumplido tiene que poder decirse
                        // (el propio papel dicta que entonces «esta autorización
                        // NO PROCEDE»).
                        'config' => ['questions' => $c['preguntas'], 'answers' => $this->siNoNa()],
                    ]],
                ],
                $this->eppRequerido($c['epp']),
                [
                    'name_es' => 'Monitoreo de aire',
                    'name_en' => 'Air monitoring',
                    'campos'  => [
                        [
                            'code' => 'monitoreo_de_aire', 'field_type' => 'table',
                            'label_es' => 'Monitoreo de aire (una fila por medición; el papel admite el valor inicial y hasta 5 re-mediciones con su hora)',
                            'label_en' => 'Air monitoring (one row per reading; the paper allows the initial value plus up to 5 re-tests with their time)',
                            'config' => ['columns' => [
                                ['value' => 'prueba', 'label' => ['es' => 'Prueba (O2 / CO / H2S / LEL)', 'en' => 'Test (O2 / CO / H2S / LEL)']],
                                ['value' => 'condicion_aceptable', 'label' => ['es' => 'Condiciones aceptables (VLP): O2 19.5 - 22.5 % · CO <25 ppm · H2S <10 ppm · LEL <10 %', 'en' => 'Acceptable conditions (TLV): O2 19.5 - 22.5 % · CO <25 ppm · H2S <10 ppm · LEL <10 %']],
                                ['value' => 'valor', 'label' => ['es' => 'Valor medido', 'en' => 'Measured value']],
                                ['value' => 'hora', 'label' => ['es' => 'Hora', 'en' => 'Time']],
                            ]],
                        ],
                        [
                            'code' => 'instrumentos_medicion', 'field_type' => 'text',
                            'label_es' => 'Instrumentos de medición, indicar marca, número de serie y fecha de la última calibración',
                            'label_en' => 'Measuring instruments: brand, serial number and last calibration date',
                        ],
                    ],
                ],
                $this->cierreConObservaciones(),
            ],
        ];
    }

    protected function ptwElectricalSafety(): array
    {
        $c = $this->add['ptw_ele'];

        return [
            'code'    => 'PTW-ELE',
            'name_es' => 'PTW Electrical Safety (Seguridad eléctrica)',
            'name_en' => 'PTW Electrical Safety',
            'secciones' => [
                [
                    'name_es' => 'Autorización para trabajar',
                    'name_en' => 'Authorisation to work',
                    'campos'  => [
                        [
                            'code' => 'tension', 'field_type' => 'radio',
                            'label_es' => 'Tensión', 'label_en' => 'Voltage',
                            'config' => ['options' => ['ALTA TENSIÓN', 'BAJA TENSIÓN']],
                        ],
                        ['code' => 'numero_caja_bloqueo', 'field_type' => 'text', 'label_es' => 'N° caja bloqueo', 'label_en' => 'Lock box no.'],
                        ['code' => 'ubicacion', 'field_type' => 'text', 'is_required' => true, 'label_es' => 'Ubicación', 'label_en' => 'Location'],
                        ['code' => 'permiso_emitido_por', 'field_type' => 'text', 'label_es' => 'Permiso emitido por (y su empresa)', 'label_en' => 'Permit issued by (and their company)'],
                        ['code' => 'emitido_a', 'field_type' => 'text', 'label_es' => 'Emitido a (y su empresa)', 'label_en' => 'Issued to (and their company)'],
                        ['code' => 'equipos_a_intervenir', 'field_type' => 'textarea', 'is_required' => true, 'label_es' => 'Para trabajar en los siguientes equipos', 'label_en' => 'To work on the following equipment'],
                        ['code' => 'tag_equipos', 'field_type' => 'textarea', 'label_es' => 'Identificación / TAG de los equipos a intervenir', 'label_en' => 'Identification / TAG of the equipment'],
                        ['code' => 'resumen_actividades', 'field_type' => 'textarea', 'is_required' => true, 'label_es' => 'Resumen de las actividades a realizar', 'label_en' => 'Summary of the activities'],
                    ],
                ],
                [
                    'name_es' => 'Declaración',
                    'name_en' => 'Declaration',
                    'campos'  => [
                        ['code' => 'equipo_energizado_cercano', 'field_type' => 'text', 'label_es' => 'Equipo energizado cercano', 'label_en' => 'Nearby energised equipment'],
                        $this->numero('numero_bloqueos', 'Número de bloqueos (fuentes de energía a bloquear con candados)', 'Number of locks (energy sources locked out)', 0),
                        [
                            'code' => 'declaracion', 'field_type' => 'question_bank', 'is_required' => true,
                            'label_es' => 'Verificación (cualquier respuesta NO debe llevarse a un nivel superior de autoridad para aclaración)',
                            'label_en' => 'Verification (any NO answer must be escalated to a higher level of authority for clarification)',
                            'config' => ['questions' => $c['declaracion'], 'answers' => [
                                ['value' => 'Si', 'tone' => 'ok'],
                                ['value' => 'No', 'tone' => 'bad'],
                            ]],
                        ],
                        ['code' => 'puntos_de_bloqueo', 'field_type' => 'textarea', 'label_es' => 'Lista de puntos de bloqueo donde se aisla el sistema', 'label_en' => 'List of lockout points isolating the system'],
                        ['code' => 'puntos_puesta_tierra', 'field_type' => 'textarea', 'label_es' => 'Lista de puntos donde se instala la puesta a tierra', 'label_en' => 'List of points where earthing is installed'],
                        ['code' => 'detalles_precauciones', 'field_type' => 'textarea', 'label_es' => 'Proporcionar detalles y enumerar las precauciones', 'label_en' => 'Provide details and list the precautions'],
                    ],
                ],
                [
                    'name_es' => 'Entrega de la instalación al emisor',
                    'name_en' => 'Handover of the installation to the issuer',
                    'campos'  => [
                        [
                            'code' => 'entrega', 'field_type' => 'question_bank',
                            'label_es' => 'Lista de verificación de entrega (¡a partir de este punto, la instalación debe considerarse como energizada!)',
                            'label_en' => 'Handover checklist (from this point on, the installation must be considered energised!)',
                            'config' => ['questions' => $c['entrega'], 'answers' => [
                                ['value' => 'Si', 'tone' => 'ok'],
                                ['value' => 'No', 'tone' => 'bad'],
                            ]],
                        ],
                        [
                            'code' => 'estado_del_trabajo', 'field_type' => 'radio',
                            'label_es' => 'Para aclarar, elija una de las siguientes opciones', 'label_en' => 'To clarify, choose one of the following',
                            'config' => ['options' => [
                                'El trabajo está completo, el equipo está listo para su puesta en servicio',
                                'El trabajo está incompleto, el equipo no está listo para su puesta en servicio',
                            ]],
                        ],
                    ],
                ],
                [
                    'name_es' => 'Verificación previa a la re-energización',
                    'name_en' => 'Pre re-energisation checklist',
                    'campos'  => [[
                        'code' => 'reenergizacion', 'field_type' => 'question_bank',
                        'label_es' => 'Antes de la re-energización asegúrese de que no permanezcan personas en la zona de riesgo',
                        'label_en' => 'Before re-energisation make sure nobody remains in the risk zone',
                        'config' => ['questions' => $c['reenergizacion'], 'answers' => [
                            ['value' => 'Si', 'tone' => 'ok'],
                            ['value' => 'No', 'tone' => 'bad'],
                        ]],
                    ]],
                ],
                $this->cierreConObservaciones(),
            ],
        ];
    }

    protected function ptwPruebasElectricas(): array
    {
        $equipos = implode(', ', $this->add['ptw_pru']['equipos']);

        return [
            'code'    => 'PTW-PRU',
            'name_es' => 'PTW Pruebas eléctricas',
            'name_en' => 'PTW Electrical testing',
            'secciones' => [
                [
                    'name_es' => 'Autorización para trabajar',
                    'name_en' => 'Authorisation to work',
                    'campos'  => [
                        ['code' => 'lugar_de_la_prueba', 'field_type' => 'text', 'is_required' => true, 'label_es' => 'Lugar de la prueba', 'label_en' => 'Test location'],
                        ['code' => 'descripcion_tarea', 'field_type' => 'textarea', 'is_required' => true, 'label_es' => 'Descripción de la tarea', 'label_en' => 'Task description'],
                        [
                            'code' => 'equipos_de_prueba', 'field_type' => 'table',
                            'label_es' => "Equipos de prueba ({$equipos})",
                            'label_en' => "Test equipment ({$equipos})",
                            'config' => ['columns' => [
                                ['value' => 'equipo', 'label' => ['es' => 'Nombre del equipo de pruebas', 'en' => 'Test equipment name']],
                                ['value' => 'corriente_carga', 'label' => ['es' => 'Máx. valor de corriente de carga', 'en' => 'Max. charging current']],
                                ['value' => 'tension_prueba', 'label' => ['es' => 'Tensión de prueba', 'en' => 'Test voltage']],
                            ]],
                        ],
                        ['code' => 'suministro_primario', 'field_type' => 'textarea', 'label_es' => 'Detalles del suministro primario (¿protegido con 30mA RCD? ¿fuente? ¿tensión de operación? ¿3-fases o fase única?)', 'label_en' => 'Primary supply details (30mA RCD protected? source? operating voltage? 3-phase or single phase?)'],
                        ['code' => 'suministro_auxiliar', 'field_type' => 'textarea', 'label_es' => 'Suministro auxiliar (¿sistemas de protección? ¿back-up para fallos de suministro?)', 'label_en' => 'Auxiliary supply (protection systems? backup for supply failures?)'],
                        ['code' => 'suministro_auxiliar_otros', 'field_type' => 'textarea', 'label_es' => 'Suministro auxiliar no eléctrico (hidráulicos, neumáticos, cinéticos, etc.)', 'label_en' => 'Non-electrical auxiliary supply (hydraulic, pneumatic, kinetic, etc.)'],
                        ['code' => 'riesgo_electrico_residual', 'field_type' => 'textarea', 'label_es' => 'Riesgo eléctrico residual (¿carga capacitiva, inductancia, puntos de contacto potenciales?)', 'label_en' => 'Residual electrical risk (capacitive load, inductance, potential contact points?)'],
                        ['code' => 'precauciones_especiales', 'field_type' => 'textarea', 'label_es' => 'Precauciones especiales', 'label_en' => 'Special precautions'],
                    ],
                ],
                $this->cierreConObservaciones(),
            ],
        ];
    }

    protected function ptwIzaje(): array
    {
        $c = $this->add['ptw_iza'];

        return [
            'code'    => 'PTW-IZA',
            'name_es' => 'PTW Plan de izaje de cargas',
            'name_en' => 'PTW Load lifting plan',
            'secciones' => [
                [
                    'name_es' => 'Descripción de la actividad',
                    'name_en' => 'Activity description',
                    'campos'  => [
                        ['code' => 'proyecto_servicio', 'field_type' => 'text', 'label_es' => 'Proyecto / Servicio', 'label_en' => 'Project / Service'],
                        ['code' => 'lugar_de_izaje', 'field_type' => 'text', 'is_required' => true, 'label_es' => 'Lugar de izaje', 'label_en' => 'Lifting location'],
                        ['code' => 'cliente', 'field_type' => 'text', 'label_es' => 'Cliente', 'label_en' => 'Client'],
                        ['code' => 'actividad', 'field_type' => 'textarea', 'is_required' => true, 'label_es' => 'Actividad', 'label_en' => 'Activity'],
                        ['code' => 'descripcion_carga', 'field_type' => 'textarea', 'label_es' => 'Descripción de la carga', 'label_en' => 'Load description'],
                        ['code' => 'descripcion_equipo', 'field_type' => 'text', 'label_es' => 'Descripción del equipo', 'label_en' => 'Equipment description'],
                        ['code' => 'tipo_de_grua', 'field_type' => 'text', 'label_es' => 'Tipo de grúa', 'label_en' => 'Crane type'],
                        ['code' => 'marca', 'field_type' => 'text', 'label_es' => 'Marca', 'label_en' => 'Brand'],
                        ['code' => 'modelo', 'field_type' => 'text', 'label_es' => 'Modelo', 'label_en' => 'Model'],
                        ['code' => 'capacidad', 'field_type' => 'text', 'label_es' => 'Capacidad', 'label_en' => 'Capacity'],
                    ],
                ],
                [
                    'name_es' => 'Descripción de la carga y peso',
                    'name_en' => 'Load and weight description',
                    'campos'  => [
                        $this->numero('peso_gancho_auxiliar', 'Peso del gancho auxiliar (Kg)', 'Auxiliary hook weight (Kg)'),
                        $this->numero('peso_gancho_principal', 'Peso del gancho principal (Kg)', 'Main hook weight (Kg)'),
                        $this->numero('peso_cable_acero', 'Peso del cable de acero (Kg)', 'Steel cable weight (Kg)'),
                        $this->numero('peso_accesorios_izaje', 'Peso de accesorios de izaje (Kg)', 'Lifting gear weight (Kg)'),
                        $this->numero('peso_polipasto', 'Peso de polipasto (Kg)', 'Hoist weight (Kg)'),
                        $this->numero('peso_pluma_instalada', 'Peso de pluma instalada (Kg)', 'Installed jib weight (Kg)'),
                        $this->numero('peso_otros', 'Peso otros (Kg)', 'Other weight (Kg)'),
                        $this->numero('peso_grua_total', 'Peso grúa total (1) = suma de los anteriores (Kg)', 'Total crane weight (1) = sum of the above (Kg)'),
                        $this->numero('peso_carga', 'Peso de la carga (2) (Kg)', 'Load weight (2) (Kg)'),
                        $this->numero('peso_total', 'Peso Total (3) = (1) + (2) (Kg)', 'Total weight (3) = (1) + (2) (Kg)'),
                    ],
                ],
                [
                    'name_es' => 'Descripción de la maniobra',
                    'name_en' => 'Manoeuvre description',
                    'campos'  => [
                        $this->numero('radio_maniobra', 'Radio (m)', 'Radius (m)'),
                        $this->numero('longitud_pluma', 'Longitud de la pluma (m)', 'Jib length (m)'),
                        $this->numero('altura', 'Altura (m)', 'Height (m)'),
                        $this->numero('angulo_pluma', 'Ángulo de la pluma (grados)', 'Jib angle (degrees)'),
                        $this->numero('capacidad_segun_tabla', 'Capacidad de la grúa según tabla de cargas y maniobra a realizar (4) (Kg)', 'Crane capacity per load chart and manoeuvre (4) (Kg)'),
                        $this->numero('porcentaje_utilizacion', 'Cálculo (5) = Peso Total (3) ÷ Capacidad grúa (4) × 100 %. NO SE PODRA REALIZAR MANIOBRA ALGUNA, SI EL PORCENTAJE DE ESTE CÁLCULO ES SUPERIOR A 66%', 'Calculation (5) = Total weight (3) ÷ Crane capacity (4) × 100 %. NO LIFT MAY BE PERFORMED IF THIS PERCENTAGE EXCEEDS 66%'),
                    ],
                ],
                [
                    'name_es' => 'Medidas de control generales',
                    'name_en' => 'General control measures',
                    'campos'  => [
                        [
                            'code' => 'medidas_de_control', 'field_type' => 'question_bank', 'is_required' => true,
                            'label_es' => 'Medidas de control generales', 'label_en' => 'General control measures',
                            'config' => ['questions' => $c['preguntas'], 'answers' => $this->siNoNa()],
                        ],
                        [
                            'code' => 'otras_medidas', 'field_type' => 'textarea',
                            'label_es' => 'Otras medidas a considerar. ' . $c['criterios_critico'],
                            'label_en' => 'Other measures to consider (critical lift criteria as per the standard)',
                        ],
                    ],
                ],
                [
                    'name_es' => 'Diagrama de maniobra',
                    'name_en' => 'Lift diagram',
                    'campos'  => [
                        [
                            // El papel manda dibujarlo al reverso de la hoja; en
                            // digital es una foto del croquis.
                            'code' => 'diagrama_maniobra', 'field_type' => 'photo',
                            'label_es' => 'Diagrama de maniobra (foto del croquis)', 'label_en' => 'Lift diagram (photo of the sketch)',
                            'config' => ['max_files' => 3],
                        ],
                        $this->observaciones(),
                    ],
                ],
            ],
        ];
    }

    // ── Las tres inspecciones ───────────────────────────────────────────────

    protected function inspeccionArnes(): array
    {
        $c = $this->add['arnes'];

        return [
            'code'    => 'IAR',
            'name_es' => 'Inspección de arnés, línea de anclaje y línea retráctil',
            'name_en' => 'Harness, lanyard and retractable line inspection',
            'orientacion' => 'landscape',
            'secciones' => [
                [
                    'name_es' => 'Datos de la inspección',
                    'name_en' => 'Inspection data',
                    'campos'  => [
                        ['code' => 'actividad', 'field_type' => 'text', 'label_es' => 'Actividad', 'label_en' => 'Activity'],
                        ['code' => 'proyecto_servicio', 'field_type' => 'text', 'label_es' => 'Proyecto / Servicio', 'label_en' => 'Project / Service'],
                        ['code' => 'lugar', 'field_type' => 'text', 'label_es' => 'Lugar', 'label_en' => 'Place'],
                        ['code' => 'area', 'field_type' => 'text', 'label_es' => 'Área', 'label_en' => 'Area'],
                    ],
                ],
                // Tres cuadriculas, una por tipo de equipo, como en el papel.
                // Cada fila es UN equipo, identificado por su numero de serie
                // (que es lo que se escribe en la columna «herramienta»).
                $this->cuadricula('arnes', 'Arnés de seguridad', 'Safety harness', $c['arnes']),
                $this->cuadricula('linea_anclaje', 'Línea de anclaje', 'Anchor lanyard', $c['linea_anclaje']),
                $this->cuadricula('linea_retractil', 'Línea retráctil', 'Retractable line', $c['linea_retractil']),
                $this->cierreConObservaciones(),
            ],
        ];
    }

    protected function inspeccionKit(): array
    {
        $kit = $this->add['kit'];
        $imagenes = $kit['imagenes'];

        // La lista plana de preguntas y los grupos que la reparten, con la foto
        // de cada equipo. Un mismo punto («Estado de las costuras») aparece en
        // varios equipos y la identidad de una pregunta es su VALOR, asi que
        // los repetidos se desambiguan añadiendoles su grupo — regla general,
        // no una lista a mano: un item nuevo repetido queda cubierto solo.
        $vistos = [];
        $grupos = [];
        $preguntas = [];

        foreach ($kit['grupos'] as $grupo) {
            $items = [];

            foreach ($grupo['items'] as $item) {
                $valor = isset($vistos[$item]) ? "{$item} — {$grupo['name']['es']}" : $item;
                $vistos[$item] = true;
                $items[] = $valor;
                $preguntas[] = $valor;
            }

            $grupos[] = [
                'name'  => $grupo['name'],
                'items' => $items,
                'image' => $imagenes[$grupo['imagen']] ?? null,
            ];
        }

        return [
            'code'    => 'IKR',
            'name_es' => 'Inspección de Kit de Revelado',
            'name_en' => 'Voltage test kit inspection',
            'secciones' => [
                [
                    'name_es' => 'Datos de la inspección',
                    'name_en' => 'Inspection data',
                    'campos'  => [
                        ['code' => 'actividad', 'field_type' => 'text', 'label_es' => 'Actividad', 'label_en' => 'Activity'],
                        ['code' => 'proyecto_servicio', 'field_type' => 'text', 'label_es' => 'Proyecto / Servicio', 'label_en' => 'Project / Service'],
                        ['code' => 'lugar', 'field_type' => 'text', 'label_es' => 'Lugar', 'label_en' => 'Place'],
                        ['code' => 'supervisor_picw', 'field_type' => 'text', 'label_es' => 'Supervisor PICW', 'label_en' => 'PICW supervisor'],
                        ['code' => 'identificacion_equipos', 'field_type' => 'text', 'label_es' => 'Identificación (serie / código de los equipos inspeccionados)', 'label_en' => 'Identification (serial / code of the inspected equipment)'],
                    ],
                ],
                [
                    'name_es' => 'Lista de verificación',
                    'name_en' => 'Checklist',
                    'campos'  => [[
                        'code' => 'verificacion', 'field_type' => 'question_bank', 'is_required' => true,
                        'label_es' => 'Lista de verificación (en caso de responder No aplica, sustentar en observaciones)',
                        'label_en' => 'Checklist (any Not applicable answer must be justified in the observations)',
                        'config' => [
                            'questions' => $preguntas,
                            'answers'   => $this->conformeNa(),
                            'groups'    => $grupos,
                        ],
                    ]],
                ],
                $this->cierreConObservaciones(),
            ],
        ];
    }

    protected function inspeccionIzaje(): array
    {
        $c = $this->add['izaje_elementos'];

        // B y D como VALORES cortos —caben en la celda de la cuadricula y en el
        // PDF— con el rotulo entero del papel como etiqueta. Sin «No aplica»:
        // el papel no lo tiene, y aqui el hueco ya distingue lo no mirado.
        $respuestas = [
            ['value' => 'B', 'tone' => 'ok', 'label' => [
                'es' => 'B. Buen estado (Se procede con los trabajos)',
                'en' => 'B. Good condition (work may proceed)',
            ]],
            ['value' => 'D', 'tone' => 'bad', 'label' => [
                'es' => 'D. Defectuoso (No se procede con la actividad, cambio inmediato del accesorio)',
                'en' => 'D. Defective (activity stops, replace the accessory immediately)',
            ]],
        ];

        return [
            'code'    => 'IEI',
            'name_es' => 'Inspección de elementos para izaje de cargas',
            'name_en' => 'Lifting gear inspection',
            'orientacion' => 'landscape',
            'secciones' => [
                [
                    'name_es' => 'Datos de la inspección',
                    'name_en' => 'Inspection data',
                    'campos'  => [
                        [
                            'code' => 'tipo_de_inspeccion', 'field_type' => 'radio',
                            'label_es' => 'Tipo de Inspección', 'label_en' => 'Inspection type',
                            'config' => ['options' => ['PLANEADA', 'NO PLANEADA']],
                        ],
                        ['code' => 'proyecto_servicio', 'field_type' => 'text', 'label_es' => 'Proyecto / Servicio', 'label_en' => 'Project / Service'],
                        ['code' => 'lugar_de_inspeccion', 'field_type' => 'text', 'label_es' => 'Lugar de Inspección', 'label_en' => 'Inspection place'],
                    ],
                ],
                $this->cuadricula('eslingas', 'Eslingas', 'Slings', $c['eslingas'], $respuestas,
                    'Eslingas (cada fila un elemento: escribe su código y medidas)', 'Slings (one row per element: write its code and measurements)'),
                $this->cuadricula('grilletes', 'Grilletes', 'Shackles', $c['grilletes'], $respuestas,
                    'Grilletes (cada fila un elemento: escribe su código y medidas)', 'Shackles (one row per element: write its code and measurements)'),
                $this->cuadricula('ganchos', 'Ganchos', 'Hooks', $c['ganchos'], $respuestas,
                    'Ganchos (cada fila un elemento: escribe su código)', 'Hooks (one row per element: write its code)'),
                $this->cierreConObservaciones(),
            ],
        ];
    }

    // ── Piezas repetidas ────────────────────────────────────────────────────

    /** La cabecera comun de los permisos PETAR: quien, donde y cuando. */
    protected function datosDelPermiso(): array
    {
        return [
            'name_es' => 'Datos del permiso',
            'name_en' => 'Permit data',
            'campos'  => [
                ['code' => 'empresa_ejecutora', 'field_type' => 'text', 'is_required' => true, 'label_es' => 'Empresa ejecutora', 'label_en' => 'Executing company'],
                ['code' => 'area', 'field_type' => 'text', 'is_required' => true, 'label_es' => 'Área', 'label_en' => 'Area'],
                ['code' => 'trabajo_a_realizar', 'field_type' => 'textarea', 'is_required' => true, 'label_es' => 'Trabajo a realizar', 'label_en' => 'Work to be carried out'],
                ['code' => 'supervisor_de_trabajo', 'field_type' => 'text', 'is_required' => true, 'label_es' => 'Supervisor de trabajo', 'label_en' => 'Work supervisor'],
                ['code' => 'lugar', 'field_type' => 'text', 'is_required' => true, 'label_es' => 'Lugar', 'label_en' => 'Place'],
                ['code' => 'hora_inicio', 'field_type' => 'time', 'label_es' => 'Hora inicio', 'label_en' => 'Start time'],
                ['code' => 'hora_final', 'field_type' => 'time', 'label_es' => 'Hora final', 'label_en' => 'End time'],
            ],
        ];
    }

    /** La seccion de EPP de los permisos: la lista marcable y su detalle. */
    protected function eppRequerido(array $opciones): array
    {
        return [
            'name_es' => 'Equipo de protección requerido',
            'name_en' => 'Required protective equipment',
            'campos'  => [
                [
                    'code' => 'epp_requerido', 'field_type' => 'multiselect',
                    'label_es' => 'Equipo de protección requerido (EPP Básico: casco de seguridad, lentes con protección y zapatos dieléctricos con punta de baquelita)',
                    'label_en' => 'Required protective equipment (basic PPE: safety helmet, protective glasses and dielectric footwear)',
                    'config' => ['options' => $opciones],
                ],
                [
                    'code' => 'epp_detalle', 'field_type' => 'text',
                    'label_es' => 'Detalle del EPP (clase de guante, cartucho, otros)', 'label_en' => 'PPE detail (glove class, cartridge, others)',
                ],
            ],
        ];
    }

    /** Un campo numerico con la config completa que exige el editor. */
    protected function numero(string $code, string $es, string $en, int $decimales = 2): array
    {
        return [
            'code' => $code, 'field_type' => 'number',
            'label_es' => $es, 'label_en' => $en,
            'config' => ['min' => 0, 'max' => null, 'decimals' => $decimales],
        ];
    }

    /**
     * Una cuadricula de inspeccion (tool_checklist): una fila por equipo,
     * identificado en la columna de la herramienta, con sus puntos verbatim.
     */
    protected function cuadricula(
        string $code,
        string $es,
        string $en,
        array $puntos,
        ?array $respuestas = null,
        ?string $labelEs = null,
        ?string $labelEn = null,
    ): array {
        return [
            'name_es' => $es,
            'name_en' => $en,
            'campos'  => [[
                'code' => $code, 'field_type' => 'tool_checklist',
                'label_es' => $labelEs ?? "{$es} (cada fila un equipo: identifícalo por su número de serie)",
                'label_en' => $labelEn ?? "{$en} (one row per item: identify it by its serial number)",
                'config' => [
                    // El catalogo de herramientas va vacio a proposito: el
                    // numero de serie es libre, y las sugerencias las aprende
                    // el sistema de las entregas confirmadas.
                    'tools'   => [],
                    'items'   => $puntos,
                    'answers' => $respuestas ?? $this->conformeNa(),
                    // La condicion hallada, que en el papel es la columna del
                    // final y solo se llena cuando algo salio mal.
                    'extra'   => ['identified_condition'],
                ],
            ]],
        ];
    }

    /** La seccion de cierre con el campo de observaciones de siempre. */
    protected function cierreConObservaciones(): array
    {
        return [
            'name_es' => 'Observaciones',
            'name_en' => 'Observations',
            'campos'  => [$this->observaciones()],
        ];
    }

    // ── Tipos de trabajo ────────────────────────────────────────────────────

    /**
     * Los tipos de trabajo de estos formatos, con su matriz de documentos.
     *
     * Cuatro tipos nuevos —altura, electrico, confinado, izaje— cada uno con
     * los cuatro formatos base de la obra (AST, PTF, EPP, IHM: todo plan los
     * lleva) mas sus permisos e inspecciones propios, todos obligatorios: el
     * permiso es lo que autoriza esa clase de maniobra y la inspeccion es su
     * chequeo pre-uso. Desde la pantalla de Tipos de trabajo se puede
     * desmarcar lo que un workspace no quiera exigir.
     *
     * Idempotente: el tipo se busca por codigo y el pivote con
     * `insertOrIgnore`, asi que re-sembrar no duplica ni pisa lo que el
     * cliente haya cambiado desde la pantalla.
     */
    protected function tiposDeTrabajo(int $paisId, ?int $tenantId): void
    {
        $tipos = [
            ['code' => 'ALTURA', 'name' => 'Trabajos en altura', 'formatos' => ['PTW-ALT', 'IAR']],
            ['code' => 'ELECTRICO', 'name' => 'Seguridad eléctrica', 'formatos' => ['PTW-ELE', 'PTW-PRU', 'IKR']],
            ['code' => 'CONFINADO', 'name' => 'Espacios confinados', 'formatos' => ['PTW-CON']],
            ['code' => 'IZAJE', 'name' => 'Izaje de cargas', 'formatos' => ['PTW-IZA', 'IEI']],
        ];

        $base = ['AST', 'PTF', 'EPP', 'IHM'];

        // `name` llega con su propia migracion; si esta corrida va por delante
        // de ella, el tipo nace solo con el codigo y el re-sembrado lo cura.
        $conNombre = Schema::hasColumn('work_types', 'name');

        foreach ($tipos as $tipo) {
            $fila = WorkType::withTrashed()
                ->where('country_id', $paisId)
                ->where('code', $tipo['code'])
                ->when(
                    $tenantId === null,
                    fn ($q) => $q->whereNull('tenant_id'),
                    fn ($q) => $q->where('tenant_id', $tenantId),
                )
                ->first();

            if (! $fila) {
                $fila = new WorkType();
                $fila->forceFill(array_filter([
                    'slug'       => \Illuminate\Support\Str::random(22),
                    'country_id' => $paisId,
                    'tenant_id'  => $tenantId,
                    'code'       => $tipo['code'],
                    'name'       => $conNombre ? $tipo['name'] : null,
                    'is_active'  => true,
                ], fn ($v) => $v !== null));
                $fila->save();
            } elseif ($conNombre && blank($fila->name)) {
                $fila->forceFill(['name' => $tipo['name']])->save();
            }

            // La matriz: primero los cuatro de base y despues los propios, en
            // ese orden — el id del pivote es el orden de los documentos en el
            // plan.
            foreach ([...$base, ...$tipo['formatos']] as $codigo) {
                $plantilla = FormTemplate::query()
                    ->where('country_id', $paisId)
                    ->where('code', $codigo)
                    ->where('status', 'published')
                    ->orderByDesc('version')
                    ->first();

                if (! $plantilla) {
                    continue;
                }

                DB::table('work_type_form_templates')->insertOrIgnore([
                    'work_type_id'     => $fila->id,
                    'form_template_id' => $plantilla->id,
                    'is_required'      => true,
                ]);
            }
        }
    }
}
