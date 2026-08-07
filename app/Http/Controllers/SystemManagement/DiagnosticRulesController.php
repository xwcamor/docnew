<?php

namespace App\Http\Controllers\SystemManagement;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ResultScale;
use App\Models\Rule;
use App\Models\RuleCondition;
use App\Models\RuleSet;
use App\Models\Test;
use App\Models\Variable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * DiagnosticRulesController — UI de edición de reglas de diagnóstico (solo super).
 * Ver docs/EDICION-REGLAS.md.
 *
 * Fase 1: SEMÁFORO (result_scales) de cromas/furanos/fiquis/fpot y del Índice de
 * Salud, más los pesos del HI (tests.hi_weight/hi_enabled). Las bandas se editan
 * por "punto de corte" (score_to): el `score_from` de cada banda es el `score_to`
 * de la anterior → contiguidad garantizada por construcción.
 *
 * Fase 2: CUADROS DE REGLAS (rule_sets → rules + rule_conditions). Cada cuadro es
 * una combinación prueba+aceite+trafo+norma; sus reglas son los escalones
 * (variable, condiciones AND, score, peso, prioridad) que el motor evalúa.
 */
class DiagnosticRulesController extends Controller
{
    private const COLORS = ['green', 'lime', 'yellow', 'orange', 'red'];
    // Pruebas con semáforo en result_scales. 'hi' = escala global (test_id null).
    private const TESTS = ['cromas', 'fiquis', 'furanos', 'fpot'];
    // Operadores válidos de una condición (igual que RuleCondition::matches()).
    private const OPERATORS = ['<', '<=', '>', '>=', '=', '!='];

    public function index()
    {
        // Metadata por prueba: su NORMA y las CONDICIONES que seleccionan sus
        // reglas, más los enlaces de edición que le corresponden. Agrupar todo
        // por prueba evita la "mescolanza" de tener semáforos, cuadros, umbrales
        // y límites dispersos sin contexto.
        $meta = [
            'cromas' => [
                'norm'       => 'IEC 60599 · IEEE C57.104',
                'conditions' => ['oil', 'transformer_type'],
                'links'      => [
                    ['key' => 'sets',        'route' => 'system_management.diagnostic_rules.sets',      'param' => null],
                ],
            ],
            'fiquis' => [
                'norm'       => 'IEEE C57.106',
                'conditions' => ['oil', 'voltage_class'],
                'links'      => [
                    ['key' => 'fiquis_thresholds', 'route' => 'system_management.diagnostic_rules.data_edit', 'param' => 'fiquis_rules'],
                ],
            ],
            'furanos' => ['norm' => 'DP Chendong', 'conditions' => ['oil'], 'links' => []],
            'fpot'    => ['norm' => '—',          'conditions' => ['oil'], 'links' => []],
        ];

        // Los cuadros de reglas (sets) ya son tenant-safe (copy-on-write): el
        // admin del workspace edita los SUYOS sin tocar los globales → el link
        // se muestra también a los admins.

        $blocks = [];
        foreach (self::TESTS as $code) {
            $test = Test::where('code', $code)->first();
            if (!$test) {
                continue;
            }
            $blocks[] = [
                'code'       => $code,
                'name'       => $test->name,
                'has_rating' => true,
                'has_hi'     => true,
                'hi_weight'  => (int) $test->hi_weight,
                'hi_enabled' => (bool) $test->hi_enabled,
                'bands'      => $this->bandsFor($test->id),
                'norm'       => $meta[$code]['norm'] ?? null,
                'conditions' => $meta[$code]['conditions'] ?? [],
                'links'      => $meta[$code]['links'] ?? [],
            ];
        }
        // Escala del Índice de Salud (test_id NULL).
        $blocks[] = [
            'code'       => 'hi',
            'name'       => __('diagnostic_rules.hi_scale'),
            'has_rating' => true,   // el HI ahora guarda su rating (no depende del texto)
            'has_hi'     => false,
            'hi_weight'  => null,
            'hi_enabled' => null,
            'bands'      => $this->bandsFor(null),
            'norm'       => null,
            'conditions' => [],
            'links'      => [],
        ];

        return inertia('SystemManagement/DiagnosticRules/Index', [
            'blocks'          => $blocks,
            'colors'          => self::COLORS,
            'conditionLabels' => $this->conditionLabelsPayload(),
            'labelLocales'    => $this->labelLocales(),
        ]);
    }

    /**
     * Idioma editable de las etiquetas: SOLO el idioma ACTIVO del sistema. El
     * editor muestra una columna, no todas — con N idiomas la tabla sería
     * ilegible. Para editar otro idioma se cambia el idioma del sistema y se
     * vuelve (el guardado hace merge: no pisa los otros idiomas ya guardados).
     */
    private function labelLocales(): array
    {
        return [app()->getLocale()];
    }

    /**
     * Etiquetas de calificación por rating (4..0) y por idioma, para el editor.
     * Lee el dataset editable (con fallback al archivo de idioma por hueco).
     */
    private function conditionLabelsPayload(): array
    {
        $stored = \App\Support\Diagnostics\RuleData::get(\App\Support\Diagnostics\ConditionLabel::DATASET_KEY);
        $locales = $this->labelLocales();
        $out = [];
        foreach ([4, 3, 2, 1, 0] as $rating) {
            $row = ['rating' => $rating, 'labels' => []];
            foreach ($locales as $loc) {
                $row['labels'][$loc] = $stored[(string) $rating][$loc]
                    ?? $stored[$rating][$loc]
                    ?? \App\Support\Diagnostics\ConditionLabel::for($rating, $loc);
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Persiste las etiquetas de calificación por idioma (dataset condition_labels). */
    public function updateLabels(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'labels'            => ['required', 'array', 'min:1'],
            'labels.*.rating'   => ['required', 'integer', 'between:0,4'],
            'labels.*.labels'   => ['required', 'array'],
            'labels.*.labels.*' => ['nullable', 'string', 'max:60'],
        ]);

        $before = \App\Support\Diagnostics\RuleData::get(\App\Support\Diagnostics\ConditionLabel::DATASET_KEY);
        $tenantId = $this->ruleTenantId();

        $ds = \App\Models\DiagnosticDataset::firstOrNew([
            'key'       => \App\Support\Diagnostics\ConditionLabel::DATASET_KEY,
            'tenant_id' => $tenantId,
        ]);

        // MERGE: se edita SOLO el idioma activo; se preservan los valores de los
        // demás idiomas ya guardados en el override (no se pisan).
        $active  = app()->getLocale();
        $payload = is_array($ds->data) ? $ds->data : [];
        foreach ($data['labels'] as $row) {
            $rating = (string) ((int) $row['rating']);
            $v = trim((string) ($row['labels'][$active] ?? ''));
            if (!isset($payload[$rating]) || !is_array($payload[$rating])) {
                $payload[$rating] = [];
            }
            if ($v !== '') {
                $payload[$rating][$active] = $v;
            } else {
                unset($payload[$rating][$active]);
            }
            if (empty($payload[$rating])) {
                unset($payload[$rating]);
            }
        }

        $ds->label = $ds->label ?: 'condition_labels';
        $ds->data = $payload;
        $ds->updated_by = auth()->id();
        if (!$ds->exists) {
            $ds->created_by = auth()->id();
        }
        $ds->save();

        \App\Support\Diagnostics\RuleData::flush(\App\Support\Diagnostics\ConditionLabel::DATASET_KEY);
        $this->audit('condition_labels', 'updated', ['labels' => $before], ['labels' => $payload]);

        return back()->with('success', __('diagnostic_rules.labels_saved'));
    }

    /**
     * Persiste los colores del diagnóstico (semáforo + gradiente de celda) del
     * scope que edita: super → el GLOBAL; admin → el override de su workspace.
     * Mismo patrón híbrido que las etiquetas (firstOrNew por key+tenant_id).
     */
    public function updateColors(Request $request): RedirectResponse
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $data = $request->validate([
            'sema'        => ['required', 'array'],
            'sema.green'  => $hex, 'sema.lime'   => $hex, 'sema.yellow' => $hex,
            'sema.orange' => $hex, 'sema.red'    => $hex,
            'stops'       => ['required', 'array'],
            'stops.good'  => $hex, 'stops.warn'  => $hex, 'stops.bad'   => $hex,
        ]);

        $key = \App\Support\Diagnostics\DiagnosticColors::KEY;
        $before = \App\Support\Diagnostics\RuleData::get($key);
        $tenantId = $this->ruleTenantId();

        // Solo se guardan tokens con HEX válido; el resto cae al default al leer.
        $payload = ['sema' => [], 'stops' => []];
        foreach (\App\Support\Diagnostics\DiagnosticColors::SEMA_TOKENS as $tk) {
            if (!empty($data['sema'][$tk])) { $payload['sema'][$tk] = $data['sema'][$tk]; }
        }
        foreach (\App\Support\Diagnostics\DiagnosticColors::STOP_TOKENS as $tk) {
            if (!empty($data['stops'][$tk])) { $payload['stops'][$tk] = $data['stops'][$tk]; }
        }

        $ds = \App\Models\DiagnosticDataset::firstOrNew(['key' => $key, 'tenant_id' => $tenantId]);
        $ds->label = $ds->label ?: 'diagnostic_colors';
        $ds->data = $payload;
        $ds->updated_by = auth()->id();
        if (!$ds->exists) { $ds->created_by = auth()->id(); }
        $ds->save();

        \App\Support\Diagnostics\RuleData::flush($key);
        $this->audit('diagnostic_colors', 'updated', ['colors' => $before], ['colors' => $payload]);

        return back()->with('success', __('diagnostic_rules.colors_saved'));
    }

    /** Restaura los colores de fábrica (borra el override del que edita → default). */
    public function restoreColors(): RedirectResponse
    {
        $key = \App\Support\Diagnostics\DiagnosticColors::KEY;
        $tenantId = $this->ruleTenantId();
        $q = \App\Models\DiagnosticDataset::where('key', $key);
        $q = $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId);
        $q->delete();
        \App\Support\Diagnostics\RuleData::flush($key);

        return back()->with('success', __('diagnostic_rules.colors_restored'));
    }

    /** Restaura las etiquetas de calificación de fábrica (borra el override → JSON). */
    public function restoreLabels(): RedirectResponse
    {
        // Restaurar borra SOLO el override del que edita: super → la global;
        // un tenant → la suya (cae al global). Nunca borra la del otro.
        $tenantId = $this->ruleTenantId();
        $q = \App\Models\DiagnosticDataset::where('key', \App\Support\Diagnostics\ConditionLabel::DATASET_KEY);
        $q = $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId);
        $q->delete();
        \App\Support\Diagnostics\RuleData::flush(\App\Support\Diagnostics\ConditionLabel::DATASET_KEY);

        return back()->with('success', __('diagnostic_rules.labels_restored'));
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        $isHi = $code === 'hi';
        $test = $isHi ? null : Test::where('code', $code)->firstOrFail();
        abort_if(!$isHi && !in_array($code, self::TESTS, true), 404);

        $data = $request->validate([
            'bands'                   => ['required', 'array', 'min:1'],
            'bands.*.condition_label' => ['required', 'string', 'max:60'],
            'bands.*.color'           => ['required', 'in:' . implode(',', self::COLORS)],
            'bands.*.hi_rating'       => ['nullable', 'numeric', 'between:0,4'],
            // Punto de corte superior de la banda; la última debe ser null (+∞).
            'bands.*.score_to'        => ['nullable', 'numeric'],
            'hi_weight'               => ['sometimes', 'integer', 'min:0', 'max:100'],
            'hi_enabled'              => ['sometimes', 'boolean'],
        ]);

        $bands = array_values($data['bands']);
        // Validación: cortes ascendentes; solo la última banda sin tope (∞).
        $prev = null;
        foreach ($bands as $i => $b) {
            $isLast = $i === count($bands) - 1;
            $to = $b['score_to'];
            if ($isLast && $to !== null) {
                return back()->withErrors(['bands' => __('diagnostic_rules.err_last_open')]);
            }
            if (!$isLast && $to === null) {
                return back()->withErrors(['bands' => __('diagnostic_rules.err_cut_required')]);
            }
            if (!$isLast && $prev !== null && (float) $to <= (float) $prev) {
                return back()->withErrors(['bands' => __('diagnostic_rules.err_ascending')]);
            }
            if (!$isLast) {
                $prev = $to;
            }
        }

        $before = $this->bandsFor($test?->id);
        $tenantId = $this->ruleTenantId();

        DB::transaction(function () use ($bands, $test, $tenantId) {
            // CRÍTICO: el DELETE se scopea por tenant. Super (null) borra/recrea
            // las GLOBALES; un tenant solo las SUYAS → nunca puede borrar las
            // reglas globales de los demás.
            $del = ResultScale::where('test_id', $test?->id);
            $del = $tenantId === null ? $del->whereNull('tenant_id') : $del->where('tenant_id', $tenantId);
            $del->delete();

            $from = null;
            foreach ($bands as $i => $b) {
                $to = ($i === count($bands) - 1) ? null : (float) $b['score_to'];
                ResultScale::create([
                    'tenant_id'       => $tenantId,
                    'test_id'         => $test?->id,
                    'score_from'      => $from,
                    'score_to'        => $to,
                    'condition_label' => $b['condition_label'],
                    'color'           => $b['color'],
                    'hi_rating'       => ($b['hi_rating'] ?? null) === null ? null : (float) $b['hi_rating'],
                    'sort_order'      => $i + 1,
                ]);
                $from = $to;
            }
        });

        // Los pesos del HI (tests.hi_weight/hi_enabled) son GLOBALES → solo super
        // los edita. Un tenant personaliza sus bandas, no los pesos.
        if ($test && $tenantId === null) {
            $test->update(array_filter([
                'hi_weight'  => $data['hi_weight'] ?? null,
            ], fn ($v) => $v !== null) + ['hi_enabled' => $request->boolean('hi_enabled')]);
        }

        $this->audit($code, 'updated', $before, $this->bandsFor($test?->id));
        $this->queueFleetRecalc();

        return back()->with('success', __('diagnostic_rules.saved'));
    }

    /** Restaura los semáforos por defecto (re-corre el seeder de catálogos). */
    public function restore(): RedirectResponse
    {
        $before = ['note' => 'pre-restore'];
        $tenantId = $this->ruleTenantId();
        if ($tenantId === null) {
            // super: reseña el estándar de fábrica (global).
            Artisan::call('db:seed', ['--class' => \Database\Seeders\DiagnosticCatalogSeeder::class, '--force' => true]);
        } else {
            // tenant: borra SOLO sus semáforos override → cae al global. Nunca toca el global.
            ResultScale::where('tenant_id', $tenantId)->delete();
        }
        $this->audit('all', 'restored', $before, ['note' => 'defaults']);
        $this->queueFleetRecalc();

        return back()->with('success', __('diagnostic_rules.restored'));
    }

    /**
     * Encola el recálculo de la caché de flota tras un cambio de reglas que
     * afecta el DIAGNÓSTICO (semáforos, pesos HI, cuadros, umbrales fiquis —
     * no colores/etiquetas, que son presentación). Scope: super (global) →
     * flota completa; admin → solo los trafos de su workspace. El delay +
     * unicidad del job agrupan varios "Guardar" seguidos en UN recálculo.
     */
    private function queueFleetRecalc(): void
    {
        \App\Jobs\RecalculateFleetCache::dispatch($this->ruleTenantId())
            ->delay(now()->addMinutes(2))
            ->afterCommit();
    }

    /**
     * Tenant cuyas reglas se editan: super edita las GLOBALES (null); un admin
     * de workspace editaría las SUYAS (override). Hoy el editor es solo-super →
     * null → escribe global (idéntico). Deja listo el write-path híbrido.
     */
    private function ruleTenantId(): ?int
    {
        $user = auth()->user();
        if ($user && method_exists($user, 'hasRole') && $user->hasRole('super')) {
            return null;
        }

        return $user?->tenant_id;
    }

    private function bandsFor(?int $testId): array
    {
        // Muestra las bandas EFECTIVAS del editor (tenant → su override, super →
        // global), vía el resolver híbrido.
        return \App\Support\Diagnostics\ResultScaleResolver::bands($testId)
            ->map(fn ($s) => [
                'score_from'      => $s->score_from === null ? null : (float) $s->score_from,
                'score_to'        => $s->score_to === null ? null : (float) $s->score_to,
                'condition_label' => $s->condition_label,
                'color'           => $s->color,
                'hi_rating'       => $s->hi_rating === null ? null : (float) $s->hi_rating,
            ])->all();
    }

    // ───────────────────────── Fase 2: cuadros de reglas ─────────────────────

    /**
     * Guard del editor de cuadros. Devuelve el tenant cuyas reglas se editan
     * (null = super → globales). Reglas de acceso:
     *  - super: SOLO cuadros globales (tenant_id null).
     *  - admin: cuadros globales (para personalizarlos vía copy-on-write) o los
     *    SUYOS. Nunca los de otro tenant.
     */
    private function assertCanEditSet(RuleSet $ruleSet): ?int
    {
        $tenantId = $this->ruleTenantId();
        if ($tenantId === null) {
            abort_unless($ruleSet->tenant_id === null, 403);
        } else {
            abort_unless($ruleSet->tenant_id === null || $ruleSet->tenant_id === $tenantId, 403);
        }

        return $tenantId;
    }

    /** Clave de combinación de un cuadro (prueba+aceite+trafo) para mapear override↔global. */
    private function setKey(RuleSet $s): string
    {
        return $s->test_id . '|' . $s->oil_type_id . '|' . ($s->transformer_type_id ?? 'all');
    }

    /** Override propio del tenant para la combinación de $ruleSet (o null). */
    private function ownOverrideFor(RuleSet $ruleSet, int $tenantId): ?RuleSet
    {
        return RuleSet::where('tenant_id', $tenantId)
            ->where('test_id', $ruleSet->test_id)
            ->where('oil_type_id', $ruleSet->oil_type_id)
            ->where(fn ($q) => $ruleSet->transformer_type_id === null
                ? $q->whereNull('transformer_type_id')
                : $q->where('transformer_type_id', $ruleSet->transformer_type_id))
            ->first();
    }

    private function setRow(RuleSet $s, bool $customized): array
    {
        return [
            'id'           => $s->id,
            'test'         => $s->test?->name,
            'test_code'    => $s->test?->code,
            'oil'          => $s->oilType?->name,
            'trafo'        => $s->transformerType?->name, // null = todos los trafos
            'standard'     => $s->standard?->code,
            'label'        => $s->label,
            'total_weight' => (float) $s->total_weight,
            'is_active'    => (bool) $s->is_active,
            'rules_count'  => $s->rules_count ?? $s->rules()->count(),
            'customized'   => $customized, // el tenant tiene un override propio
        ];
    }

    /**
     * Lista los cuadros EFECTIVOS para quien edita:
     *  - super: los globales de fábrica.
     *  - admin: la base global, sustituyendo por su override donde lo tenga
     *    (marcado "personalizado"), más overrides propios sin global equivalente.
     */
    public function sets(Request $request)
    {
        $tenantId = $this->ruleTenantId();
        $with = ['test:id,code,name', 'oilType:id,name', 'transformerType:id,name', 'standard:id,code,name,version'];

        $overrides = $tenantId === null
            ? collect()
            : RuleSet::where('tenant_id', $tenantId)->with($with)->withCount('rules')
                ->get()->keyBy(fn (RuleSet $s) => $this->setKey($s));

        $globals = RuleSet::whereNull('tenant_id')->with($with)->withCount('rules')
            ->orderBy('test_id')->orderBy('oil_type_id')->orderBy('transformer_type_id')->get();

        $rows = $globals->map(function (RuleSet $g) use ($overrides) {
            $ov = $overrides->get($this->setKey($g));
            return $this->setRow($ov ?: $g, customized: (bool) $ov);
        });

        // Overrides del tenant para combinaciones que NO existen en la base global.
        $globalKeys = $globals->map(fn (RuleSet $g) => $this->setKey($g))->flip();
        $orphans = $overrides
            ->reject(fn (RuleSet $s, string $key) => $globalKeys->has($key))
            ->map(fn (RuleSet $s) => $this->setRow($s, customized: true))
            ->values();

        $sets = $rows->concat($orphans)->values();

        return inertia('SystemManagement/DiagnosticRules/Sets', [
            'sets'    => $sets,
            'tests'   => $sets->pluck('test')->filter()->unique()->values(),
            'isSuper' => $tenantId === null,
        ]);
    }

    /** Editor de un cuadro: sus reglas + condiciones, con opciones de variables. */
    public function editSet(RuleSet $ruleSet)
    {
        $tenantId = $this->assertCanEditSet($ruleSet);

        // Si un admin abre el cuadro GLOBAL pero ya tiene override propio, lo
        // redirige a editar el suyo. Si no tiene, ve el global y al guardar se
        // creará su copia (inherited = aviso en la UI).
        $inherited = false;
        if ($tenantId !== null && $ruleSet->tenant_id === null) {
            $own = $this->ownOverrideFor($ruleSet, $tenantId);
            if ($own) {
                return redirect()->route('system_management.diagnostic_rules.set_edit', $own->id);
            }
            $inherited = true;
        }

        $ruleSet->load([
            'test:id,code,name', 'oilType:id,name', 'transformerType:id,name',
            'standard:id,code,name,version',
            'rules' => fn ($q) => $q->orderBy('priority'),
            'rules.conditions' => fn ($q) => $q->orderBy('sort_order'),
        ]);

        return inertia('SystemManagement/DiagnosticRules/SetEdit', [
            // inherited = el admin ve el cuadro de fábrica; al guardar se crea su
            // copia (no toca el global). Para super siempre false.
            'inherited' => $inherited,
            'set' => [
                'id'           => $ruleSet->id,
                'test'         => $ruleSet->test?->name,
                'oil'          => $ruleSet->oilType?->name,
                'trafo'        => $ruleSet->transformerType?->name,
                'standard'     => trim(($ruleSet->standard?->code ?? '') . ' ' . ($ruleSet->standard?->version ?? '')),
                'label'        => $ruleSet->label,
                'total_weight' => (float) $ruleSet->total_weight,
                'is_active'    => (bool) $ruleSet->is_active,
            ],
            'rules' => $ruleSet->rules->map(fn (Rule $r) => [
                'variable_id'  => $r->variable_id,
                'score'        => (float) $r->score,
                'weight'       => (float) $r->weight,
                'priority'     => (int) $r->priority,
                'result_label' => $r->result_label,
                'is_active'    => (bool) $r->is_active,
                'conditions'   => $r->conditions->map(fn (RuleCondition $c) => [
                    'variable_id' => $c->variable_id,
                    'operator'    => $c->operator,
                    'value'       => (float) $c->value,
                ])->values(),
            ])->values(),
            'variables' => Variable::orderBy('sort_order')->orderBy('code')
                ->get(['id', 'code', 'name', 'unit'])
                ->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->name, 'unit' => $v->unit]),
            'operators' => self::OPERATORS,
        ]);
    }

    /** Reemplaza reglas + condiciones del cuadro (transacción + auditoría). */
    public function updateSet(Request $request, RuleSet $ruleSet): RedirectResponse
    {
        $tenantId = $this->assertCanEditSet($ruleSet);
        $data = $request->validate([
            'label'        => ['nullable', 'string', 'max:120'],
            'total_weight' => ['required', 'numeric', 'min:0'],
            'is_active'    => ['required', 'boolean'],
            'rules'                          => ['present', 'array'],
            'rules.*.variable_id'            => ['required', 'integer', 'exists:variables,id'],
            'rules.*.score'                  => ['required', 'numeric'],
            'rules.*.weight'                 => ['required', 'numeric', 'min:0'],
            'rules.*.priority'               => ['required', 'integer', 'min:0'],
            'rules.*.result_label'           => ['nullable', 'string', 'max:120'],
            'rules.*.is_active'              => ['required', 'boolean'],
            'rules.*.conditions'             => ['present', 'array'],
            'rules.*.conditions.*.variable_id' => ['required', 'integer', 'exists:variables,id'],
            'rules.*.conditions.*.operator'  => ['required', 'in:' . implode(',', self::OPERATORS)],
            'rules.*.conditions.*.value'     => ['required', 'numeric'],
        ]);

        // COPY-ON-WRITE: si un admin edita el cuadro GLOBAL, no lo toca; crea (o
        // reusa) su propio override de esa combinación y edita ESE. Su propio
        // override, o el super sobre el global, se editan directo.
        $target = $ruleSet;
        if ($tenantId !== null && $ruleSet->tenant_id === null) {
            $target = $this->ownOverrideFor($ruleSet, $tenantId);
            if (!$target) {
                $target = RuleSet::create([
                    'tenant_id'           => $tenantId,
                    'test_id'             => $ruleSet->test_id,
                    'oil_type_id'         => $ruleSet->oil_type_id,
                    'transformer_type_id' => $ruleSet->transformer_type_id,
                    'standard_id'         => $ruleSet->standard_id,
                    'total_weight'        => $ruleSet->total_weight,
                    'label'               => $ruleSet->label,
                    'is_active'           => $ruleSet->is_active,
                ]);
            }
        }

        $before = $this->setSnapshot($target);

        DB::transaction(function () use ($data, $target) {
            $target->update([
                'label'        => $data['label'] ?? $target->label,
                'total_weight' => $data['total_weight'],
                'is_active'    => $data['is_active'],
            ]);

            // Reemplazo total: borra reglas (y sus condiciones) y recrea.
            RuleCondition::whereIn('rule_id', $target->rules()->pluck('id'))->delete();
            $target->rules()->delete();

            foreach (array_values($data['rules']) as $r) {
                $rule = Rule::create([
                    'rule_set_id'  => $target->id,
                    'variable_id'  => $r['variable_id'],
                    'score'        => $r['score'],
                    'weight'       => $r['weight'],
                    'priority'     => $r['priority'],
                    'result_label' => $r['result_label'] ?? null,
                    'is_active'    => $r['is_active'],
                ]);
                foreach (array_values($r['conditions']) as $i => $c) {
                    RuleCondition::create([
                        'rule_id'     => $rule->id,
                        'variable_id' => $c['variable_id'],
                        'operator'    => $c['operator'],
                        'value'       => $c['value'],
                        'sort_order'  => $i + 1,
                    ]);
                }
            }
        });

        $this->audit('ruleset:' . $target->id, 'updated', $before, $this->setSnapshot($target->fresh()));
        $this->queueFleetRecalc();

        return redirect()->route('system_management.diagnostic_rules.sets')
            ->with('success', __('diagnostic_rules.set_saved'));
    }

    /**
     * Restaura un cuadro a fábrica: borra el override del tenant (cae al global).
     * Solo aplica a admins sobre SUS overrides; super no tiene override que borrar.
     */
    public function restoreSet(RuleSet $ruleSet): RedirectResponse
    {
        $tenantId = $this->ruleTenantId();
        if ($tenantId === null) {
            return back()->with('error', __('diagnostic_rules.set_restore_super_na'));
        }

        // Acepta el id del override propio o el del global (busca el override).
        $own = $ruleSet->tenant_id === $tenantId
            ? $ruleSet
            : $this->ownOverrideFor($ruleSet, $tenantId);

        if ($own && $own->tenant_id === $tenantId) {
            $before = $this->setSnapshot($own);
            DB::transaction(function () use ($own) {
                RuleCondition::whereIn('rule_id', $own->rules()->pluck('id'))->delete();
                $own->rules()->delete();
                $own->delete();
            });
            $this->audit('ruleset:' . $own->id, 'restored', $before, ['note' => 'fell back to global']);
            $this->queueFleetRecalc();
        }

        return redirect()->route('system_management.diagnostic_rules.sets')
            ->with('success', __('diagnostic_rules.set_restored'));
    }

    /** Foto del cuadro (reglas + condiciones) para la auditoría. */
    private function setSnapshot(RuleSet $ruleSet): array
    {
        return $ruleSet->load(['rules' => fn ($q) => $q->orderBy('priority'), 'rules.conditions'])
            ->rules->map(fn (Rule $r) => [
                'variable_id' => $r->variable_id,
                'score'       => (float) $r->score,
                'weight'      => (float) $r->weight,
                'priority'    => (int) $r->priority,
                'conditions'  => $r->conditions->map(fn (RuleCondition $c) => [
                    'variable_id' => $c->variable_id,
                    'operator'    => $c->operator,
                    'value'       => (float) $c->value,
                ])->all(),
            ])->all();
    }

    private function audit(string $code, string $event, array $old, array $new): void
    {
        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => $event,
            'auditable_type' => 'diagnostic_rules',
            'auditable_id'   => 0,
            'module'         => 'diagnostic_rules',
            'old_values'     => ['test' => $code, 'bands' => $old],
            'new_values'     => ['test' => $code, 'bands' => $new],
            'url'            => null,
            'ip_address'     => request()?->ip(),
            'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
            'created_at'     => now(),
        ]);
    }

    // ── Datos de norma editables (fiquis_rules) ──────────────────────────────

    private const DATASETS = ['fiquis_rules'];

    public function editData(string $key)
    {
        abort_unless(in_array($key, self::DATASETS, true), 404);

        // fiquis vive en tablas relacionales (fiqui_params + fiqui_thresholds).
        $data = \App\Services\Diagnostics\FiquisDiagnosisService::loadFromDb();

        return inertia('SystemManagement/DiagnosticRules/DataEdit', [
            'dataKey' => $key,
            'data'    => $data,
        ]);
    }

    public function updateData(Request $request, string $key): RedirectResponse
    {
        abort_unless(in_array($key, self::DATASETS, true), 404);
        $incoming = $request->input('data');
        abort_unless(is_array($incoming), 422);

        $this->updateFiquisRules($incoming, $this->ruleTenantId());
        $this->queueFleetRecalc();

        return redirect()->route('system_management.diagnostic_rules.data_edit', $key)
            ->with('success', __('diagnostic_rules.data_saved'));
    }

    /**
     * Persiste los umbrales/pesos editados a las tablas. Solo ACTUALIZA filas
     * existentes (no crea ni borra): la estructura (parámetros, aceites, clases)
     * no se toca desde este editor — agregar un parámetro es otro flujo.
     */
    private function updateFiquisRules(array $incoming, ?int $tenantId = null): void
    {
        // Umbrales: updateOrCreate scopeado por tenant → super edita/crea la
        // celda GLOBAL; un tenant crea/edita SU override de esa celda (no toca
        // la global). Sin override del tenant, el motor cae a la global.
        foreach (($incoming['tables'] ?? []) as $oil => $classes) {
            foreach ((array) $classes as $cls => $byParam) {
                foreach ((array) $byParam as $pk => $vals) {
                    $vals = array_values((array) $vals);
                    \App\Models\FiquiThreshold::updateOrCreate(
                        ['param_key' => $pk, 'oil_code' => $oil, 'voltage_class' => $cls, 'tenant_id' => $tenantId],
                        [
                            't1' => isset($vals[0]) && is_numeric($vals[0]) ? (float) $vals[0] : null,
                            't2' => isset($vals[1]) && is_numeric($vals[1]) ? (float) $vals[1] : null,
                            't3' => isset($vals[2]) && is_numeric($vals[2]) ? (float) $vals[2] : null,
                        ]
                    );
                }
            }
        }
        // Los params (peso/tolerancia) son GLOBALES → solo super los edita.
        if ($tenantId === null) {
            foreach (($incoming['params'] ?? []) as $pk => $meta) {
                $patch = [];
                if (isset($meta['weight']) && is_numeric($meta['weight'])) { $patch['weight'] = (float) $meta['weight']; }
                if (isset($meta['tol'])    && is_numeric($meta['tol']))    { $patch['tol']    = (float) $meta['tol']; }
                if ($patch) {
                    \App\Models\FiquiParam::where('key', $pk)->update($patch);
                }
            }
        }
    }

    public function restoreData(string $key): RedirectResponse
    {
        abort_unless(in_array($key, self::DATASETS, true), 404);

        $tenantId = $this->ruleTenantId();

        if ($tenantId === null) {
            // super → restaura la norma de fábrica (global).
            $coreKeys = \App\Models\FiquiParam::where('is_core', true)->pluck('key')->all();
            \App\Models\FiquiThreshold::whereNull('tenant_id')->whereIn('param_key', $coreKeys)->delete();
            \App\Models\FiquiParam::where('is_core', true)->delete();
            (new \Database\Seeders\FiquiRulesSeeder())->run();
        } else {
            // tenant → borra SOLO sus umbrales override (cae al global).
            \App\Models\FiquiThreshold::where('tenant_id', $tenantId)->delete();
        }
        $this->queueFleetRecalc();

        return redirect()->route('system_management.diagnostic_rules.data_edit', $key)
            ->with('success', __('diagnostic_rules.data_restored'));
    }
}
