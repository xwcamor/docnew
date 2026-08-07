<?php

namespace App\Support\Transformers;

use App\Models\Chromatographical;
use App\Models\Comment;
use App\Models\Fiqui;
use App\Models\ResultScale;
use App\Models\Setting;
use App\Models\Test;
use App\Models\Transformer;
use App\Services\Diagnostics\ChromatographyEngine;
use App\Services\Diagnostics\DuvalService;
use App\Services\Diagnostics\FiquisDiagnosisService;
use App\Services\Diagnostics\FpotDiagnosisService;
use App\Services\Diagnostics\FuranoDiagnosisService;
use App\Services\Diagnostics\IeeeDgaStatusService;
use App\Services\Diagnostics\KeyGasService;
use App\Services\Diagnostics\RatioMethodsService;

/**
 * Arma el payload de pruebas/diagnóstico de la vista de detalle del transformador
 * (las pestañas Cromatografía, Furanos, Fisicoquímico, Factor de Potencia, Duval y
 * Bitácora). Antes vivía dentro de TransformerController::show() como ~8 métodos
 * privados; se extrajo para que el controlador no crezca con cada prueba nueva y
 * para tener un único lugar donde registrar una prueba en el detalle.
 *
 * Solo lectura: no diagnostica ni persiste por su cuenta, delega a los servicios
 * de diagnóstico (que leen las reglas editables en datos). Es un presenter; cada
 * `build()` recibe el transformador ya cargado con sus relaciones.
 */
class TransformerShowPayload
{
    /**
     * Fecha de una muestra para mostrar: sin la hora cuando es medianoche.
     *
     * La inmensa mayoría de los ensayos no tiene hora (las migradas del sistema
     * viejo llegaban a las 05:00 = medianoche de Perú en UTC, ya normalizadas a
     * 00:00). Mostrar "00:00" en todas era ruido. Las pocas que SÍ tienen hora
     * cargada la conservan: ahí el dato es real.
     */
    public static function fechaMuestra($fecha): ?string
    {
        if (!$fecha) {
            return null;
        }

        return $fecha->format('H:i') === '00:00'
            ? $fecha->format('Y-m-d')
            : $fecha->format('Y-m-d H:i');
    }

    public function __construct(
        private ChromatographyEngine $cromasEngine,
        private DuvalService $duval,
        private KeyGasService $keyGas,
        private FuranoDiagnosisService $furanos,
        private FiquisDiagnosisService $fiquis,
        private FpotDiagnosisService $fpot,
        private RatioMethodsService $ratios,
        private IeeeDgaStatusService $dga,
    ) {
    }

    /**
     * Payload completo de las pruebas para `Transformers/Show`. Las claves se
     * mezclan con las del controlador (transformer/activity/diagnostics).
     */
    public function build(Transformer $transformer): array
    {
        $oilCode = $transformer->oilType?->code;
        $voltage = $transformer->voltage_kv === null ? null : (float) $transformer->voltage_kv;

        return [
            'cromas'        => $this->cromasList($transformer),
            'cromasLimits'  => $this->cromasLimits($transformer),
            'cromasLimitsIeee' => $this->cromasLimitsIeee($transformer),
            'cromasComments' => $this->comments($transformer, 'diag_cromas'),
            // IEEE C57.104-2019 DGA Status 1/2/3 (las 4 tablas) — por transformador.
            'cromasDgaStatus' => $this->cromasDgaStatus($transformer),
            // Umbral de severidad (0..1) desde el cual se tinta la celda de un gas
            // que cruza su límite. Vive en datos (Setting, editable por super), no
            // en código: subirlo/bajarlo cambia qué bandas alertan sin tocar nada.
            // Default 0.6 → tinta de "Malo" hacia arriba (no "Regular" ni mejores).
            'cellAlertSev'  => Setting::getInt('diagnostics.cell_alert_sev', 0) / 100,
            'furanos'       => $this->furanosList($transformer),
            'furanosLimits' => $this->furanosLimits(),
            // Notas del diagnosticador para furanos (comentarios de usuario).
            'furanosComments' => $this->comments($transformer, 'diag_furanos'),
            'furanosTrend'  => $this->furanosTrend($transformer),
            'furanosCoRatio' => $this->furanosCoRatio($transformer),
            'furanosMechanism' => $this->furanosMechanism($transformer),
            'fiquis'        => $this->fiquisList($transformer),
            'fiquisLimits'  => $this->fiquis->limitsFor($oilCode, $voltage),
            // Columnas (con su norma ASTM, unidad y modo) según el aceite — el front
            // arma la grilla desde aquí, así agregar un campo es solo dato.
            'fiquisColumns' => $this->fiquis->columnsFor($oilCode, $voltage),
            'fiquisComments' => $this->comments($transformer, 'diag_fiquis'),
            'fpots'         => $this->fpotsList($transformer),
            'fpotScale'     => $this->fpotScale(),
            'fpotComments'  => $this->comments($transformer, 'diag_fpot'),
            // Polígonos de zona de Duval (estáticos) para que el front dibuje el fondo.
            'duvalGeometry' => $this->duval->geometry($oilCode),
            // Límites por gas de ambas normas (popup comparativo), según aceite+trafo.
            'cromasNorms'   => $this->cromasNorms($transformer),
            'events'        => $this->eventsList($transformer),
            // Veredicto sintetizado por prueba (sobre la última muestra) para los
            // mini-resúmenes de cada pestaña y el Resumen. Resuelve la confusión
            // de "qué método manda": combina con criterio de diagnosticador.
            'diagnosisSummary' => $this->diagnosisSummary($transformer, $oilCode, $voltage),
        ];
    }

    /**
     * Síntesis del diagnóstico por prueba (última muestra). Devuelve DATOS
     * estructurados; el front compone el texto (i18n). Criterio de experto:
     *
     *  - Cromas: el ESTADO/índice lo manda el DGAF (IEC, calibrado a poblaciones
     *    reales). Duval solo es válido si hay FALLA ACTIVA (algún gas de falla
     *    sobre su típico → score > 1); con gases normales su zona es ruido y se
     *    marca "sin falla activa". IEEE se reporta como segunda opinión (estricta).
     */
    private function diagnosisSummary(Transformer $t, ?string $oilCode, ?float $voltage): array
    {
        return [
            'cromas'  => $this->cromasVerdict($t),
            'furanos' => $this->furanosVerdict($t),
            'fiquis'  => $this->fiquisVerdict($t, $oilCode, $voltage),
            'fpot'    => $this->fpotVerdict($t),
        ];
    }

    /** Etiquetas de gas para el detalle de "qué se pasó". */
    private const GAS_LABELS = [
        'h2' => 'H₂', 'o2' => 'O₂', 'n2' => 'N₂', 'ch4' => 'CH₄', 'co' => 'CO',
        'co2' => 'CO₂', 'c2h4' => 'C₂H₄', 'c2h6' => 'C₂H₆', 'c2h2' => 'C₂H₂',
    ];

    /** Umbral de una prueba fiquis a partir de sus bandas (según dirección). */
    private function fiquisLimitFromBands(array $bands): ?float
    {
        if (empty($bands)) {
            return null;
        }
        $first = $bands[0];
        $last  = $bands[count($bands) - 1];
        if ((float) ($first['sev'] ?? 1) === 0.0 && ($first['to'] ?? null) !== null) {
            return (float) $first['to'];           // menor = mejor → tope permitido
        }
        if ((float) ($last['sev'] ?? 1) === 0.0) {
            return (float) ($last['from'] ?? 0);   // mayor = mejor → mínimo permitido
        }
        return null;
    }

    /**
     * Escala de acción ÚNICA (0..3) que comparten las 4 pruebas y el resumen del
     * Índice de Salud: 0=rutina · 1=seguimiento · 2=investigar · 3=crítico. Se
     * deriva del rating de salud (0..4) para que TODAS las pruebas hablen el mismo
     * idioma. `$fault` es un PISO de severidad para señales que el rating ponderado
     * puede enmascarar (caso cromas: el DGAF promedia y puede esconder un gas sobre
     * su típico) → con falla nunca baja de "investigar".
     */
    public const ACTION_KEYS = ['routine', 'watch', 'investigate', 'critical'];

    private function actionLevel(?float $rating, bool $fault = false): int
    {
        // Rangos >= (no comparación estricta): el rating puede ser float (furanos/
        // fpot/fiqui lo devuelven como (float)), y así queda 1:1 con los cutoffs de
        // furanos (3/2/1). 0=rutina · 1=seguimiento · 2=investigar · 3=crítico.
        $base = $rating === null ? 0 : match (true) {
            $rating >= 3 => 0,   // Muy Bueno / Bueno
            $rating >= 2 => 1,   // Medio
            $rating >= 1 => 2,   // Malo
            default      => 3,   // Muy Malo
        };
        return $fault ? max($base, 2) : $base;
    }

    private function cromasVerdict(Transformer $t): array
    {
        $s = $t->latestChromatographical();
        if (!$s) {
            return ['has_data' => false];
        }
        $r = $this->cromasEngine->evaluate($s);
        // Aceite SIN cuadro de reglas DGAF: el motor devuelve 'Sin reglas'. NO
        // fabricar un veredicto "sin falla / estado normal" (era la contradicción
        // con el panel IEEE, que sí evalúa la muestra). Se muestra el mensaje
        // explícito "sin reglas para este aceite" y la lectura la da el IEEE
        // C57.104-2019 (panel DGA Status), que es lo que ahora también alimenta el
        // Índice de Salud como respaldo (HealthIndexService::cromasComponent).
        if ($r->condition === 'Sin reglas') {
            return ['has_data' => true, 'condition' => null, 'reason' => 'no_rules'];
        }
        if ($r->condition === null) {
            // Razón explícita (evita el "—" mudo): sin aceite, aceite+trafo sin
            // reglas, o muestra sin gases de falla medidos.
            $reason = !$t->oilType?->code
                ? 'no_oil'
                : (empty($this->cromasLimits($t)) ? 'no_rules' : 'no_gases');
            return ['has_data' => true, 'condition' => null, 'reason' => $reason];
        }

        // Falla activa = algún gas de falla por encima de su típico (score > 1).
        $activeFault = false;
        foreach ($r->detail as $d) {
            if (($d['score'] ?? 0) > 1) { $activeFault = true; break; }
        }

        $duval = $activeFault ? $this->duval->evaluate($s) : null;
        $ratios = $this->ratios->evaluate($s);   // Rogers + Doernenburg

        // Detalle de "qué se pasó": gases sobre su valor típico (límite score 1).
        $over = [];
        if ($activeFault) {
            $lim = $this->cromasLimits($t);
            foreach ($r->detail as $gas => $d) {
                if (($d['score'] ?? 0) > 1) {
                    $typ = collect($lim[$gas] ?? [])->firstWhere('score', 1)['to'] ?? null;
                    $over[] = [
                        'field' => self::GAS_LABELS[$gas] ?? mb_strtoupper($gas),
                        'value' => $d['value'], 'limit' => $typ, 'unit' => 'ppm',
                    ];
                }
            }
        }

        // Gases "acercándose" al típico: celda ámbar (0 < sev < 1) PERO que el motor
        // todavía considera dentro del típico (score <= 1). Si un gas ya pasó su
        // típico (score > 1) está en $over y NO es "acercándose" — sería contradictorio
        // listarlo en ambos. Replica el coloreo de celda del front (cellAlertBg/bandOf
        // sobre cromasLimits + filtro cell_alert_sev) para que el diagnóstico reconozca
        // las celdas ámbar que el semáforo del motor aún no marca como falla.
        $approaching = [];
        $alertFloor = Setting::getInt('diagnostics.cell_alert_sev', 0) / 100;
        $bands = $this->cromasLimits($t);
        foreach ($r->detail as $gas => $d) {
            if (($d['score'] ?? 0) > 1) { continue; }  // ya superó su típico → está en $over
            $val = $d['value'] ?? null;
            if ($val === null) { continue; }
            $val = (float) $val;
            $band = null;
            foreach ($bands[$gas] ?? [] as $b) {
                if ($val >= $b['from'] && ($b['to'] === null || $val < $b['to'])) { $band = $b; break; }
            }
            $sev = $band['sev'] ?? 0;
            if ($sev > 0 && $sev < 1 && $sev >= $alertFloor) {
                $approaching[] = self::GAS_LABELS[$gas] ?? mb_strtoupper($gas);
            }
        }

        // CO₂/CO: chequeo de involucramiento del PAPEL (celulosa). IEC 60599:
        //   < 3  → posible degradación térmica del papel
        //   3-10 → normal · > 10 → posible falla de baja temperatura / oxidación.
        $co2co = null; $co2coLevel = null;
        if ($s->co !== null && (float) $s->co > 0 && $s->co2 !== null) {
            $co2co = round((float) $s->co2 / (float) $s->co, 1);
            $co2coLevel = $co2co < 3 ? 'low' : ($co2co > 10 ? 'high' : 'normal');
        }

        // Zona del triángulo de falla: T1 (mineral) o T3 (no mineral: soya, silicona,
        // ésteres…). T3 usa los MISMOS códigos de zona que T1 (PD/D1/D2/T1/T2/T3/DT),
        // así que la traducción cromas.duval.* sirve igual. Mirar solo T1 dejaba el
        // Duval en blanco para aceites no minerales aunque hubiera falla activa.
        $triZone = $duval['triangles']['T1']['zone'] ?? $duval['triangles']['T3']['zone'] ?? null;

        return [
            'has_data'       => true,
            'condition'      => $r->condition,                 // DGAF (manda)
            'color'          => $r->color,
            'rating'         => $r->hiRating,
            'action_level'   => $this->actionLevel($r->hiRating, $activeFault),
            'score'          => $r->score !== null ? round($r->score, 2) : null,
            'active_fault'   => $activeFault,
            'approaching'    => $approaching,  // gases en ámbar (cerca del típico, sin pasarlo)
            'fault_zone'     => $triZone,      // T1 (mineral) o T3 (no mineral)
            'duval_tri_zone' => $triZone,      // alias para el PDF/share
            'duval_pent_zone'=> $duval['pentagon']['zones']['P1'] ?? null,
            'co2co'          => $co2co,
            'co2co_level'    => $co2coLevel,
            'exceeds'        => $activeFault,   // algún gas sobre su típico
            'over'           => $over,
            // Métodos clásicos (solo emiten veredicto con falla activa, IEC 60599).
            'rogers'         => ($ratios['rogers']['complete'] ?? false) ? ($ratios['rogers']['fault'] ?? null) : null,
            'doernenburg'    => ($ratios['doernenburg']['complete'] ?? false) ? ($ratios['doernenburg']['fault'] ?? null) : null,
        ];
    }

    private function furanosVerdict(Transformer $t): array
    {
        $s = $t->latestFurano();
        if (!$s || $s->fal === null) {
            return ['has_data' => false];
        }
        $fal = (float) $s->fal;
        $r = $this->furanos->evaluate($fal);

        // Sitúa el valor en la escala de furanos (banda donde cae) y calcula el
        // umbral de "papel sano": el tope de la peor banda que aún tiene rating >= 3
        // (Bueno o mejor). Las bandas de result_scales están en ppm → ×1000 a ppb.
        $band = null;
        $safeTo = null;
        $test = Test::where('code', 'furanos')->first();
        if ($test) {
            foreach (ResultScale::where('test_id', $test->id)->orderBy('sort_order')->get() as $sc) {
                $fromPpb = ($sc->score_from === null ? 0.0 : (float) $sc->score_from) * 1000;
                $toPpb   = $sc->score_to === null ? null : (float) $sc->score_to * 1000;
                if ($fal >= $fromPpb && ($toPpb === null || $fal < $toPpb)) {
                    $band = ['from' => $fromPpb, 'to' => $toPpb];
                }
                if ($sc->hi_rating !== null && (float) $sc->hi_rating >= 3 && $toPpb !== null) {
                    $safeTo = $safeTo === null ? $toPpb : max($safeTo, $toPpb);
                }
            }
        }

        return [
            'has_data'     => true,
            'fal'          => $fal,
            'ppm'          => round($fal / 1000, 5),
            'condition'    => $r['condition'] ?? null,
            'color'        => $r['color'] ?? null,
            'rating'       => $r['rating'] ?? null,
            'action_level' => $this->actionLevel($r['rating'] ?? null),
            'dp'           => $r['dp'] ?? null,
            'life_percent' => $r['life_percent'] ?? null,
            'band'         => $band,
            'safe_to'      => $safeTo,
            'exceeds'      => $safeTo !== null && $fal > $safeTo,
            'over'         => ($safeTo !== null && $fal > $safeTo)
                ? [['field' => '2FAL', 'value' => round($fal), 'limit' => round($safeTo), 'unit' => 'ppb']]
                : [],
        ];
    }

    private function fiquisVerdict(Transformer $t, ?string $oilCode, ?float $voltage): array
    {
        $s = $t->latestFiqui();
        if (!$s) {
            return ['has_data' => false, 'reason' => 'no_sample'];
        }
        // Razón explícita cuando NO se puede diagnosticar (evita el "—" silencioso):
        //   no_oil    → el transformador no tiene tipo de aceite asignado
        //   no_table  → el aceite no tiene tabla fisicoquímica (IEEE C57.106)
        //   no_params → la última muestra no tiene parámetros medidos
        if (!$oilCode) {
            return ['has_data' => true, 'condition' => null, 'reason' => 'no_oil'];
        }
        // Se pasan también los métodos alternos (D877, factor a 100 °C): cuando
        // el informe no trae el principal, sustituyen en el promedio.
        $values = [];
        foreach (\App\Models\Fiqui::FIELDS as $p) {
            $values[$p] = $s->{$p} === null ? null : (float) $s->{$p};
        }
        $r = $this->fiquis->evaluate($oilCode, $voltage, $values);
        if (!$r) {
            $hasTable = !empty($this->fiquis->limitsFor($oilCode, $voltage));
            return ['has_data' => true, 'condition' => null, 'reason' => $hasTable ? 'no_params' : 'no_table'];
        }
        // Parámetros fuera de rango (score 1 mejor … 4 peor): los que están en 3-4
        // se nombran en la narrativa. value+score por parámetro para el front.
        $params = [];
        foreach (($r['components'] ?? []) as $key => $c) {
            $params[] = ['key' => $key, 'value' => $c['value'], 'score' => $c['score']];
        }

        // Detalle de "qué se pasó": parámetros con score 3-4 + su límite.
        $bands = $this->fiquis->limitsFor($oilCode, $voltage);
        $over = [];
        $overKeys = [];
        foreach ($params as $p) {
            if ((int) ($p['score'] ?? 0) >= 3) {
                $overKeys[$p['key']] = true;
                $over[] = [
                    'field' => __('fiquis.' . $p['key']),
                    'value' => $p['value'],
                    'limit' => $this->fiquisLimitFromBands($bands[$p['key']] ?? []),
                    'unit'  => __('fiquis.' . $p['key'] . '_unit'),
                ];
            }
        }

        // Parámetros "acercándose" al límite: celda ámbar (0 < sev < 1) que NO está
        // fuera de rango (score < 3, no en $over). Mismo criterio que el coloreo de
        // celda del front (cellAlertBg/bandOf sobre fiquisLimits + cell_alert_sev),
        // para que el diagnóstico reconozca las celdas ámbar sin contradecir $over.
        $approaching = [];
        $alertFloor = Setting::getInt('diagnostics.cell_alert_sev', 0) / 100;
        foreach ($params as $p) {
            if (isset($overKeys[$p['key']]) || $p['value'] === null) { continue; }
            $val = (float) $p['value'];
            $band = null;
            foreach ($bands[$p['key']] ?? [] as $b) {
                if ($val >= $b['from'] && ($b['to'] === null || $val < $b['to'])) { $band = $b; break; }
            }
            $sev = $band['sev'] ?? 0;
            if ($sev > 0 && $sev < 1 && $sev >= $alertFloor) {
                $approaching[] = __('fiquis.' . $p['key']);
            }
        }

        return [
            'has_data'  => true,
            'condition' => $r['condition'] ?? null,
            'color'     => $r['color'] ?? null,
            'rating'    => $r['rating'] ?? null,
            'action_level' => $this->actionLevel($r['rating'] ?? null),
            'score'     => $r['score'] ?? null,
            'class'     => $r['class'] ?? null,
            'params'    => $params,
            // Algún parámetro fuera de rango (score 3-4 = se pasó del límite).
            'exceeds'   => collect($params)->contains(fn ($p) => (int) ($p['score'] ?? 0) >= 3),
            'over'      => $over,
            'approaching' => $approaching,
        ];
    }

    private function fpotVerdict(Transformer $t): array
    {
        $s = $t->latestFpot();
        if (!$s || $s->value === null) {
            return ['has_data' => false];
        }
        $r = $this->fpot->evaluate((float) $s->value);
        $value  = (float) $s->value;
        $rating = $r['rating'] ?? null;
        $exceeds = $rating !== null && (int) $rating < 3;

        // Límite "bueno" del fpot: el valor donde el rating baja de 3 (result_scales).
        $limit = null;
        $test = Test::where('code', 'fpot')->first();
        if ($test) {
            foreach (ResultScale::where('test_id', $test->id)->orderBy('sort_order')->get() as $sc) {
                if ($sc->hi_rating !== null && (int) $sc->hi_rating < 3 && $sc->score_from !== null) {
                    $limit = (float) $sc->score_from;
                    break;
                }
            }
        }

        // "Cerca del límite": NO se pasó (rating >= 3) pero está en la última banda
        // aceptable (rating == 3, el escalón justo antes de caer). Es dato (rating de
        // result_scales), no un porcentaje inventado de proximidad.
        $near = !$exceeds && $rating !== null && (int) $rating === 3;

        return [
            'has_data'  => true,
            'condition' => $r['condition'] ?? null,
            'color'     => $r['color'] ?? null,
            'rating'    => $rating,
            'action_level' => $this->actionLevel($rating),
            'value'     => $value,
            'date'      => optional($s->sample_date)->format('Y-m-d'),
            'exceeds'   => $exceeds,
            'limit'     => $limit,
            'near'      => $near,
            'over'      => $exceeds
                ? [['field' => __('fpot.value'), 'value' => $value, 'limit' => $limit, 'unit' => '%']]
                : [],
        ];
    }

    /**
     * Lista de ensayos de cromatografía, cada uno diagnosticado por el motor
     * (score + condición + color) más Duval, Gas Clave e IEEE C57.104.
     */
    private function cromasList(Transformer $transformer): array
    {
        $samples = $transformer->chromatographicals()->with('laboratory:id,name')->get();
        $counts = $this->sampleCommentCounts(Chromatographical::class, $samples->pluck('id')->all());
        return $samples->map(function ($s) use ($transformer, $counts) {
            $s->setRelation('transformer', $transformer);
            $r = $this->cromasEngine->evaluate($s);
            // Falla activa = algún gas de falla sobre su típico (score > 1). Sin
            // ella, las relaciones de Duval son ruido (gases normales) → el front
            // muestra "sin falla activa" en vez de una zona engañosa.
            $activeFault = false;
            foreach ($r->detail as $d) {
                if (($d['score'] ?? 0) > 1) { $activeFault = true; break; }
            }
            $row = [
                'id'          => $s->id,
                'sample_date' => self::fechaMuestra($s->sample_date),
                'report_number' => $s->report_number,
                'laboratory_id' => $s->laboratory_id,
                'laboratory_name' => $s->laboratory?->name,
                'score'       => $r->score !== null ? round($r->score, 2) : null,
                'condition'   => $r->condition,
                'color'       => $r->color,
                'active_fault' => $activeFault,
                // Diagnóstico Duval completo: 3 triángulos (con visibilidad) + 3 pentágonos.
                'duval'       => $this->duval->evaluate($s),
                // Método del Gas Clave (IEEE C57.104).
                'keyGas'      => $this->keyGas->evaluate($s),
                // Relaciones de gases: Rogers + Doernenburg (complementarios).
                'ratios'      => $this->ratios->evaluate($s),
            ];
            foreach (Chromatographical::GASES as $g) {
                $row[$g] = $s->{$g} === null ? null : (float) $s->{$g};
            }
            $row['comments_count'] = $counts[$s->id] ?? 0;
            return $row;
        })->all();
    }

    /**
     * IEEE C57.104-2019 — DGA Status 1/2/3 a nivel transformador (usa todo el
     * historial de cromas: última muestra para Tablas 1/2, delta para Tabla 3 y
     * la tasa multi-punto para Tabla 4). Devuelve el estado + el detalle por
     * tabla (qué límite aplicó, qué gas se pasó) + las 4 tablas completas y la
     * fuente, para el cuadro que se colorea en la UI al hacer click.
     */
    private function cromasDgaStatus(Transformer $transformer): ?array
    {
        $samples = $transformer->chromatographicals()->orderBy('sample_date')->get();
        if ($samples->isEmpty()) {
            return null;
        }
        $age = $transformer->manufacture_year
            ? max(0, now()->year - (int) $transformer->manufacture_year)
            : null;

        $r = $this->dga->evaluate($samples, $age, $transformer->dga_rate_sample_ids);
        if ($r === null) {
            return null;
        }

        $r['age_years'] = $age;
        // Muestras de cromas (id + fecha + gases) para la tablita de selección
        // manual de Tabla 4. Se tildan las que el motor usó (rate_sample_ids).
        $r['samples'] = $samples->map(fn ($s) => [
            'id'   => $s->id,
            'date' => optional($s->sample_date)->format('Y-m-d'),
            'h2' => $s->h2, 'ch4' => $s->ch4, 'co' => $s->co, 'co2' => $s->co2,
            'c2h4' => $s->c2h4, 'c2h6' => $s->c2h6, 'c2h2' => $s->c2h2,
        ])->values()->all();
        $r['label'] = __('transformers.ieee_dga.status' . $r['status'] . '_label');
        $r['text']  = __('transformers.ieee_dga.status' . $r['status'] . '_text');
        $r['source'] = __('transformers.ieee_dga.source');

        // Las 4 tablas completas (todos los gases/columnas) para mostrarlas y
        // resaltar la celda que aplicó. Son los mismos datos que usa el motor.
        $t123 = \App\Support\Diagnostics\RuleData::get('ieee_c57104_tables123');
        $t4   = \App\Support\Diagnostics\RuleData::get('ieee_c57104_table4');
        $r['tables'] = [
            'table1' => $t123['table1'] ?? [],
            'table2' => $t123['table2'] ?? [],
            'table3' => $t123['table3'] ?? [],
            'table4' => $t4['limits'] ?? [],
        ];

        return $r;
    }

    /**
     * Lista de ensayos de furanos, cada uno con condición/color (por 2-FAL) y DP
     * (Chendong).
     */
    private function furanosList(Transformer $transformer): array
    {
        $samples = $transformer->furanos()->with('laboratory:id,name')->get();
        $counts = $this->sampleCommentCounts(\App\Models\Furano::class, $samples->pluck('id')->all());
        return $samples->map(function ($s) use ($counts) {
            $r = $this->furanos->evaluate($s->fal === null ? null : (float) $s->fal);
            return [
                'id'          => $s->id,
                'sample_date' => self::fechaMuestra($s->sample_date),
                'report_number' => $s->report_number,
                'laboratory_id' => $s->laboratory_id,
                'laboratory_name' => $s->laboratory?->name,
                'fal'  => $s->fal === null ? null : (float) $s->fal,
                'hme'  => $s->hme === null ? null : (float) $s->hme,
                'ace'  => $s->ace === null ? null : (float) $s->ace,
                'mfu'  => $s->mfu === null ? null : (float) $s->mfu,
                'fua'  => $s->fua === null ? null : (float) $s->fua,
                'dp'        => $r['dp'],
                'condition' => $r['condition'],
                'color'     => $r['color'],
                'life_percent' => $r['life_percent'],
                'comments_count' => $counts[$s->id] ?? 0,
            ];
        })->all();
    }

    /**
     * Resumen de furanos en el tiempo: tasa de generación de 2-FAL (ppb/año)
     * entre las dos muestras más recientes, dirección, y una recomendación de
     * acción según la última muestra. En furanos la VELOCIDAD importa tanto como
     * el valor absoluto.
     *
     * @return array{rate:?float,direction:?string,samples:int,recommendation:string,rising:bool}|null
     */
    private function furanosTrend(Transformer $transformer): ?array
    {
        // sortBy explícito: la relación furanos() puede venir ordenada desc.
        $samples = $transformer->furanos()
            ->whereNotNull('fal')->whereNotNull('sample_date')
            ->get(['fal', 'sample_date'])
            ->sortBy(fn ($s) => $s->sample_date->timestamp)->values();

        if ($samples->isEmpty()) {
            return null;
        }

        // Tasa/dirección (necesita ≥2 muestras con fechas distintas).
        $rate = null;
        $direction = null;
        if ($samples->count() >= 2) {
            $prev = $samples[$samples->count() - 2];
            $last = $samples[$samples->count() - 1];
            $days = $prev->sample_date->diffInDays($last->sample_date);
            if ($days > 0) {
                $rate = round(((float) $last->fal - (float) $prev->fal) / ($days / 365.25), 1);
                // ppb/año: por debajo se considera estable. Config editable (no norma).
                $eps = (float) config('diagnostics.furanos.rate_rising_eps', 1.0);
                $direction = $rate > $eps ? 'up' : ($rate < -$eps ? 'down' : 'flat');
            }
        }

        // Recomendación según el rating de la última muestra (4..0).
        $latestRating = $this->furanos->evaluate((float) $samples->last()->fal)['rating'];

        return [
            'rate'           => $rate,
            'direction'      => $direction,
            'samples'        => $samples->count(),
            'recommendation' => $this->recommendationKey($latestRating),
            'rising'         => $direction === 'up',
        ];
    }

    /**
     * Relación CO₂/CO de la última cromatografía, como chequeo cruzado del
     * involucramiento del PAPEL en furanos (IEC 60599 / IEEE C57.104):
     *   < 3  → posible degradación térmica de la celulosa (papel)
     *   3-10 → rango normal
     *   > 10 → posible falla de baja temperatura / oxidación del papel
     * Solo lectura: no acopla los módulos, solo informa en la pestaña de furanos.
     *
     * @return array{ratio:float,level:string}|null
     */
    private function furanosCoRatio(Transformer $transformer): ?array
    {
        $s = $transformer->chromatographicals()
            ->whereNotNull('co')->whereNotNull('co2')->where('co', '>', 0)
            ->orderByDesc('sample_date')->first(['co', 'co2']);

        if (!$s) {
            return null;
        }

        $ratio = (float) $s->co2 / (float) $s->co;
        $level = $ratio < 3 ? 'low' : ($ratio > 10 ? 'high' : 'normal');

        return ['ratio' => round($ratio, 1), 'level' => $level];
    }

    /**
     * Furano secundario dominante de la última muestra → mecanismo de degradación
     * INDICATIVO (A. De Pablo, CIGRE Electra 175 (1997); menos establecido que el
     * 2-FAL — asociación cualitativa, sin umbrales normados):
     *   5HMF → oxidación · 2FOL → humedad/hidrólisis · 5MEF → sobrecalentamiento ·
     *   2ACF → patrón anómalo. El 2-FAL (degradación general) se excluye a propósito.
     *
     * @return array{compound:string,mechanism:string}|null
     */
    private function furanosMechanism(Transformer $transformer): ?array
    {
        $s = $transformer->furanos()
            ->whereNotNull('sample_date')->orderByDesc('sample_date')
            ->first(['hme', 'ace', 'mfu', 'fua']);

        if (!$s) {
            return null;
        }

        $map  = ['hme' => 'oxidation', 'fua' => 'moisture', 'mfu' => 'thermal', 'ace' => 'abnormal'];
        $vals = ['hme' => (float) $s->hme, 'ace' => (float) $s->ace, 'mfu' => (float) $s->mfu, 'fua' => (float) $s->fua];
        arsort($vals);
        $top = array_key_first($vals);

        if (($vals[$top] ?? 0) <= 0) {
            return null;
        }

        return ['compound' => $top, 'mechanism' => $map[$top]];
    }

    /**
     * Comentarios de usuario de un hilo (transformer + context) para el front.
     * Texto verbatim + autor + fecha. Tenant-scoped por el trait del modelo.
     *
     * @return array<int,array{id:int,body:string,author:string,user_id:int,created_at:?string}>
     */
    private function comments(Transformer $transformer, string $context): array
    {
        return Comment::where('commentable_type', Transformer::class)
            ->where('commentable_id', $transformer->id)
            ->where('context', $context)
            ->with(['user:id,name,country_id', 'user.country:id,iso_code'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'body'       => $c->body,
                'context'    => $c->context,
                'user_id'    => $c->user_id,
                'author'     => $c->user?->name ?? '—',
                'lang'       => $c->lang ? strtoupper($c->lang) : null,
                'country'    => $c->user?->country?->iso_code,
                'created_at' => optional($c->created_at)->format('Y-m-d H:i'),
            ])->all();
    }

    /**
     * Conteo de comentarios POR MUESTRA (context 'sample') de un modelo. Una sola
     * query agrupada (sin N+1). Alimenta el badge del botón de comentarios de cada
     * fila de la grilla.
     *
     * @return array<int,int>  [sample_id => count]
     */
    private function sampleCommentCounts(string $modelClass, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        return Comment::where('commentable_type', $modelClass)
            ->where('context', 'sample')
            ->whereIn('commentable_id', $ids)
            ->selectRaw('commentable_id, count(*) as c')
            ->groupBy('commentable_id')
            ->pluck('c', 'commentable_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Clave de recomendación de acción según el rating (4 mejor … 0 peor).
     * Cortes en config/diagnostics.php (guía operativa editable, NO norma).
     */
    private function recommendationKey(?float $rating): string
    {
        if ($rating === null) {
            return 'routine';
        }
        $cuts = (array) config('diagnostics.furanos.recommendation_cutoffs', []);
        return match (true) {
            $rating >= ($cuts['routine']  ?? 3.0) => 'routine',
            $rating >= ($cuts['monitor']  ?? 2.0) => 'monitor',
            $rating >= ($cuts['increase'] ?? 1.0) => 'increase',
            default                               => 'critical',
        };
    }

    /**
     * Límites de cromatografía por gas para este transformador (según su aceite +
     * tipo de trafo), leídos de cromas_rules.json — las MISMAS bandas que usa el
     * motor para puntuar. Alimentan las líneas/franjas de límite en las tendencias.
     *
     * @return array<string,array<int,array{from:float,to:?float,score:int}>>
     */
    /**
     * Límites por gas de las DOS normas, para el popup comparativo:
     *   - IEC 60599: valor típico (tope de la banda "normal" = score 1), por
     *     aceite + trafo (sale de cromas_rules.json).
     *   - IEEE C57.104-2019: límite de la Tabla 1 (percentil 90), por columna
     *     O2/N2 + edad del trafo (ieee_c57104_tables123.json).
     * O2/N2 no tienen límite de falla (informativos) → null. El front pinta cada
     * gas verde/rojo comparando el valor medido contra el límite.
     *
     * @return array{oil:?string,trafo:?string,gases:array<string,array{iec:?float,ieee:?float}>}
     */
    private function cromasNorms(Transformer $transformer): array
    {
        $iecBands = $this->cromasLimits($transformer);
        $t123 = \App\Support\Diagnostics\RuleData::get('ieee_c57104_tables123');
        [$col, $bucket] = $this->ieee2019Bucket($transformer);

        $gases = ['h2', 'o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2'];
        $out = [];
        foreach ($gases as $g) {
            // IEC = tope de la banda score 1 (valor típico).
            $iec = null;
            foreach ($iecBands[$g] ?? [] as $b) {
                if ($b['score'] === 1) { $iec = $b['to']; break; }
            }
            // IEEE 2019 = límite de Tabla 1 (percentil 90) para la columna/edad.
            $ieeeLimit = isset($t123['table1'][$col][$g][$bucket])
                ? (float) $t123['table1'][$col][$g][$bucket]
                : null;

            $out[$g] = ['iec' => $iec, 'ieee' => $ieeeLimit];
        }

        return [
            'oil'   => $transformer->oilType?->display_name,
            'trafo' => $transformer->transformerType?->name,
            'gases' => $out,
        ];
    }

    /**
     * Bandas de límite por gas según IEEE C57.104-2019 (Tablas 1 y 2), con la
     * MISMA forma que cromasLimits ([{from,to,sev}]) para que GasTrends y el
     * coloreo de celdas las pinten igual. Tabla 1 (percentil 90) = borde
     * verde/ámbar; Tabla 2 (percentil 95) = borde ámbar/rojo. La columna
     * (sellado vs respiración libre) sale del ratio O2/N2 de la última muestra y
     * la edad del trafo. O2/N2 no tienen límite (no se pintan). Sin aceite.
     *
     * @return array<string,array<int,array{from:float,to:?float,sev:float}>>
     */
    private function cromasLimitsIeee(Transformer $transformer): array
    {
        $t123 = \App\Support\Diagnostics\RuleData::get('ieee_c57104_tables123');
        [$col, $bucket] = $this->ieee2019Bucket($transformer);

        $out = [];
        foreach (($t123['gases'] ?? []) as $g) {
            $t1 = $t123['table1'][$col][$g][$bucket] ?? null;
            $t2 = $t123['table2'][$col][$g][$bucket] ?? null;
            if ($t1 === null) {
                continue;
            }
            $t1 = (float) $t1;
            $bands = [['from' => 0.0, 'to' => $t1, 'sev' => 0.0]];
            if ($t2 !== null && (float) $t2 > $t1) {
                $bands[] = ['from' => $t1, 'to' => (float) $t2, 'sev' => 0.5];
                $bands[] = ['from' => (float) $t2, 'to' => null, 'sev' => 1.0];
            } else {
                $bands[] = ['from' => $t1, 'to' => null, 'sev' => 1.0];
            }
            $out[$g] = $bands;
        }
        return $out;
    }

    /**
     * Columna (le02/gt02 por ratio O2/N2) + bucket de edad (unknown/le9/9_30/gt30)
     * para indexar las tablas IEEE C57.104-2019. Misma lógica que IeeeDgaStatusService.
     *
     * @return array{0:string,1:string}
     */
    private function ieee2019Bucket(Transformer $transformer): array
    {
        $last = $transformer->latestChromatographical();
        $o2 = (float) ($last->o2 ?? 0);
        $n2 = (float) ($last->n2 ?? 0);
        $ratio = $n2 > 0 ? $o2 / $n2 : null;
        $col = ($ratio !== null && $ratio > 0.2) ? 'gt02' : 'le02';

        $age = $transformer->manufacture_year
            ? max(0, now()->year - (int) $transformer->manufacture_year)
            : null;
        $bucket = $age === null ? 'unknown' : ($age <= 9 ? 'le9' : ($age <= 30 ? '9_30' : 'gt30'));

        return [$col, $bucket];
    }

    private function cromasLimits(Transformer $transformer): array
    {
        $oil   = $transformer->oilType?->code;
        $trafo = $transformer->transformerType?->code;
        if (!$oil) {
            return [];
        }

        $rows = json_decode(file_get_contents(database_path('seeders/data/cromas_rules.json')), true) ?? [];
        $bands = [];
        foreach ($rows as $r) {
            // silicona no se segmenta por trafo (trafo = null en los datos).
            if ($r['oil'] !== $oil || !($r['trafo'] === $trafo || $r['trafo'] === null)) {
                continue;
            }
            $bands[$r['gas']][] = [
                'from'  => (float) $r['from'],
                'to'    => $r['to'] === null ? null : (float) $r['to'],
                'score' => (int) $r['score'],
            ];
        }
        // Normaliza el score a severidad 0..1 por gas (peor banda = 1 = rojo).
        foreach ($bands as &$list) {
            usort($list, fn ($a, $b) => $a['from'] <=> $b['from']);
            $maxScore = max(array_column($list, 'score'));
            foreach ($list as &$b) {
                $b['sev'] = $maxScore > 1 ? (float) (($b['score'] - 1) / ($maxScore - 1)) : 0.0;
            }
            unset($b);
        }
        unset($list);
        return $bands;
    }

    /**
     * Lista de ensayos fisicoquímicos, cada uno con score/condición/color (según
     * aceite + clase de tensión).
     */
    private function fiquisList(Transformer $transformer): array
    {
        $oilCode = $transformer->oilType?->code;
        $voltage = $transformer->voltage_kv === null ? null : (float) $transformer->voltage_kv;

        $samples = $transformer->fiquis()->with('laboratory:id,name')->get();
        $counts = $this->sampleCommentCounts(Fiqui::class, $samples->pluck('id')->all());
        return $samples->map(function ($s) use ($oilCode, $voltage, $counts) {
            // Los EXTRA (D877, factor a 100 °C) también van al motor: no suman,
            // pero SUSTITUYEN al principal cuando es el único que se midió.
            $values = [];
            foreach (Fiqui::PARAMS as $p) {
                $values[$p] = $s->{$p} === null ? null : (float) $s->{$p};
            }
            $extra = [];
            foreach (Fiqui::EXTRA as $p) {
                $extra[$p] = $s->{$p} === null ? null : (float) $s->{$p};
            }
            $r = $this->fiquis->evaluate($oilCode, $voltage, $values + $extra);
            return array_merge(
                ['id' => $s->id, 'sample_date' => self::fechaMuestra($s->sample_date), 'report_number' => $s->report_number, 'laboratory_id' => $s->laboratory_id, 'laboratory_name' => $s->laboratory?->name],
                $values,
                $extra,
                [
                    'score'     => $r['score'] ?? null,
                    'condition' => $r['condition'] ?? null,
                    'color'     => $r['color'] ?? null,
                    'comments_count' => $counts[$s->id] ?? 0,
                ]
            );
        })->all();
    }

    /**
     * Límites de furanos para las tendencias: bandas del 2-FAL (la escala que
     * diagnostica), convertidas de ppm a ppb (×1000) con severidad 0..1 desde el
     * rating. Los demás compuestos furánicos no tienen límite propio.
     *
     * @return array<string,array<int,array{from:float,to:?float,sev:float}>>
     */
    private function furanosLimits(): array
    {
        $test = Test::where('code', 'furanos')->first();
        if (!$test) {
            return [];
        }
        $bands = ResultScale::where('test_id', $test->id)
            ->orderBy('sort_order')->get()
            ->map(fn ($s) => [
                'from' => $s->score_from === null ? 0.0 : (float) $s->score_from * 1000,
                'to'   => $s->score_to === null ? null : (float) $s->score_to * 1000,
                'sev'  => $s->hi_rating === null ? 0.0 : (float) (1 - $s->hi_rating / 4),
            ])->all();

        return ['fal' => $bands];
    }

    /**
     * Lista de ensayos de Factor de Potencia, cada uno con condición/color/rating
     * (por la escala única de fpot).
     */
    private function fpotsList(Transformer $transformer): array
    {
        $samples = $transformer->fpots()->with('laboratory:id,name')->get();
        $counts = $this->sampleCommentCounts(\App\Models\Fpot::class, $samples->pluck('id')->all());
        return $samples->map(function ($s) use ($counts) {
            $r = $this->fpot->evaluate($s->value === null ? null : (float) $s->value);
            return [
                'id'          => $s->id,
                'sample_date' => self::fechaMuestra($s->sample_date),
                'report_number' => $s->report_number,
                'laboratory_id' => $s->laboratory_id,
                'laboratory_name' => $s->laboratory?->name,
                'value'       => $s->value === null ? null : (float) $s->value,
                'temperature' => $s->temperature === null ? null : (float) $s->temperature,
                'condition'   => $r['condition'],
                'color'       => $r['color'],
                'rating'      => $r['rating'],
                'comments_count' => $counts[$s->id] ?? 0,
            ];
        })->all();
    }

    /**
     * Escala única de Factor de Potencia para las tendencias: las bandas de
     * result_scales (test `fpot`), con severidad 0..1 desde el rating. Una sola
     * escala universal (sin segmentar por aceite ni clase de tensión).
     *
     * @return array<int,array{from:float,to:?float,sev:float}>
     */
    private function fpotScale(): array
    {
        $test = Test::where('code', 'fpot')->first();
        if (!$test) {
            return [];
        }

        return ResultScale::where('test_id', $test->id)
            ->orderBy('sort_order')->get()
            ->map(fn ($s) => [
                'from' => $s->score_from === null ? 0.0 : (float) $s->score_from,
                'to'   => $s->score_to === null ? null : (float) $s->score_to,
                'sev'  => $s->hi_rating === null ? 0.0 : (float) (1 - $s->hi_rating / 4),
            ])->all();
    }

    /** Bitácora del transformador (eventos/comentarios) para timeline + kanban. */
    private function eventsList(Transformer $transformer): array
    {
        return $transformer->events()->get()->map(fn ($e) => [
            'id'        => $e->id,
            'title'     => $e->title,
            'body'      => $e->body,
            'status'    => $e->status,
            'category'  => $e->category,
            'starts_at' => optional($e->starts_at)->format('Y-m-d H:i'),
            'ends_at'   => optional($e->ends_at)->format('Y-m-d H:i'),
        ])->all();
    }
}
