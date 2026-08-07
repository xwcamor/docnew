<script setup>
import { ref, computed, watch } from 'vue';
import { Drawer, Spin, Empty } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';

/**
 * CromasExplainDrawer — "¿Por qué este resultado?". Muestra la traza completa del
 * diagnóstico de una fila de cromatografía: el cuadro de reglas usado (norma +
 * aceite + trafo), el cálculo del DGAF gas por gas (banda, score, peso, aporte),
 * la fórmula, el semáforo con la banda que cayó, y la condición IEEE C57.104.
 *
 * Pide la traza al backend al abrir (corre el motor sin persistir), así refleja
 * incluso valores en edición sin guardar.
 */
const props = defineProps({
    open:            { type: Boolean, default: false },
    transformerSlug: { type: String,  default: '' },
    row:             { type: Object,  default: null }, // { sample_date, h2..c2h2 }
});
const emit = defineEmits(['update:open']);

const { t } = useI18n();
const { condLabel } = useConditions();

const GAS_LABEL = {
    h2: 'H₂', o2: 'O₂', n2: 'N₂', ch4: 'CH₄', co: 'CO', co2: 'CO₂',
    c2h4: 'C₂H₄', c2h6: 'C₂H₆', c2h2: 'C₂H₂',
};
const colorHex = semaforoHex;

const loading = ref(false);
const error = ref(false);
const data = ref(null); // { cromas, ieee }

const fetchExplain = async () => {
    if (!props.row || !props.transformerSlug) return;
    loading.value = true;
    error.value = false;
    data.value = null;
    try {
        const { data: res } = await window.axios.post(
            route('business_management.transformers.cromas.explain', props.transformerSlug),
            { row: props.row },
        );
        data.value = res;
    } catch (_) {
        error.value = true; // fallo de red/servidor: NO es "sin reglas"
    } finally {
        loading.value = false;
    }
};

watch(() => props.open, (v) => { if (v) fetchExplain(); });

// Texto legible de la banda (umbrales de la regla que matcheó).
const OP = { '<': '<', '<=': '≤', '>': '>', '>=': '≥', '=': '=', '!=': '≠' };
const bandText = (bounds) => {
    if (!bounds || !bounds.length) return '—';
    return bounds.map((b) => `${OP[b.operator] ?? b.operator} ${fmtNum(b.value)}`).join('  ·  ');
};
const fmtNum = (v) => (v === null || v === undefined ? '—' : (Number.isInteger(v) ? v : Number(v).toFixed(2)));
const bandLabel = (from, to) => {
    const lo = from === null || from === undefined ? '−∞' : fmtNum(from);
    const hi = to === null || to === undefined ? '∞' : fmtNum(to);
    return `${lo} – ${hi}`;
};

// `cr` se usa como propiedad en el template (cr.has_rules, cr.dgaf…), así que
// tiene que ser un computed (auto-unwrap), no una función.
const cr = computed(() => data.value?.cromas ?? null);
</script>

<template>
    <Drawer
        :open="open"
        :title="$t('cromas.explain.title')"
        :width="560"
        placement="right"
        @close="emit('update:open', false)"
    >
        <div v-if="loading" class="ex-loading"><Spin /> <span>{{ $t('cromas.explain.loading') }}</span></div>

        <template v-else-if="cr && cr.has_rules">
            <p class="ex-sub">{{ $t('cromas.explain.subtitle') }}</p>

            <!-- Resultado -->
            <section class="ex-card ex-result" :style="{ '--c': colorHex(cr.color) }">
                <div class="ex-result__kpis">
                    <div>
                        <b class="ex-kpi-cond"><span class="ex-dot" :style="{ background: colorHex(cr.color) }"></span>{{ condLabel(cr.condition) }}</b>
                        <span>{{ $t('cromas.state') }}</span>
                    </div>
                    <div><b>{{ cr.dgaf }}</b><span>{{ $t('cromas.explain.dgaf') }}</span></div>
                    <div v-if="cr.rating !== null"><b>{{ cr.rating }}/4</b><span>{{ $t('cromas.explain.rating') }}</span></div>
                </div>
            </section>

            <!-- Referencia -->
            <section class="ex-card">
                <h4>{{ $t('cromas.explain.reference') }}</h4>
                <dl class="ex-ref">
                    <div><dt>{{ $t('cromas.explain.standard') }}</dt><dd>{{ cr.rule_set.standard ?? '—' }}<span v-if="cr.rule_set.standard_version" class="ex-muted"> · {{ cr.rule_set.standard_version }}</span></dd></div>
                    <div><dt>{{ $t('cromas.explain.oil') }}</dt><dd>{{ cr.rule_set.oil ?? '—' }}</dd></div>
                    <div><dt>{{ $t('cromas.explain.trafo') }}</dt><dd>{{ cr.rule_set.trafo ?? $t('cromas.explain.trafo_all') }}</dd></div>
                </dl>
                <p class="ex-note">{{ $t('cromas.explain.reference_note') }}</p>
            </section>

            <!-- Cálculo por gas -->
            <section class="ex-card">
                <h4>{{ $t('cromas.explain.calc') }}</h4>
                <table class="ex-table">
                    <thead>
                        <tr>
                            <th>{{ $t('cromas.explain.col_gas') }}</th>
                            <th class="r">{{ $t('cromas.explain.col_measured') }}</th>
                            <th>{{ $t('cromas.explain.col_band') }}</th>
                            <th class="r">{{ $t('cromas.explain.col_score') }}</th>
                            <th class="r">{{ $t('cromas.explain.col_weight') }}</th>
                            <th class="r">{{ $t('cromas.explain.col_contribution') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="g in cr.per_gas" :key="g.gas" :class="{ 'ex-missing': g.measured === null }">
                            <td><strong>{{ GAS_LABEL[g.gas] ?? g.gas }}</strong></td>
                            <td class="r">{{ g.measured === null ? '—' : fmtNum(g.measured) }}</td>
                            <td>
                                <span v-if="g.measured === null" class="ex-muted">{{ $t('cromas.explain.not_measured') }}</span>
                                <span v-else-if="!g.matched" class="ex-muted">{{ $t('cromas.explain.no_band') }}</span>
                                <span v-else>{{ bandText(g.matched.bounds) }}</span>
                            </td>
                            <td class="r">{{ g.matched ? fmtNum(g.matched.score) : '—' }}</td>
                            <td class="r">{{ g.matched ? fmtNum(g.matched.weight) : '—' }}</td>
                            <td class="r">{{ g.matched ? fmtNum(g.matched.contribution) : '—' }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="r">Σ</td>
                            <td class="r"><strong>{{ cr.sum_score_weight }} ÷ {{ cr.sum_weight }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <!-- Fórmula -->
            <section class="ex-card">
                <h4>{{ $t('cromas.explain.formula') }}</h4>
                <div class="ex-formula">
                    {{ $t('cromas.explain.formula_expr') }}
                    <div class="ex-formula__calc">= {{ cr.sum_score_weight }} ÷ {{ cr.sum_weight }} = <b>{{ cr.dgaf }}</b></div>
                </div>
            </section>

            <!-- Semáforo -->
            <section class="ex-card">
                <h4>{{ $t('cromas.explain.semaphore') }}</h4>
                <ul class="ex-scale">
                    <li v-for="(b, i) in cr.scale" :key="i" :class="{ 'is-match': b.matched }">
                        <span class="ex-dot" :style="{ background: colorHex(b.color) }"></span>
                        <span class="ex-scale__range">{{ bandLabel(b.from, b.to) }}</span>
                        <span class="ex-scale__cond">{{ condLabel(b.condition) }}</span>
                        <span v-if="b.matched" class="ex-scale__here">← {{ $t('cromas.explain.your_dgaf') }} ({{ cr.dgaf }})</span>
                    </li>
                </ul>
            </section>

        </template>

        <Empty v-else-if="error" :description="$t('cromas.explain.error')" />
        <Empty v-else :description="$t('cromas.explain.no_rules')" />
    </Drawer>
</template>

<style scoped>
.ex-loading { display: flex; align-items: center; gap: 10px; padding: 30px 0; color: var(--color-text-muted, #6A6D70); }
.ex-sub { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 16px; }
.ex-card { border: 1px solid var(--color-border, #eef0f2); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
.ex-card h4 { margin: 0 0 10px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); }
.ex-muted { color: var(--color-text-muted, #9aa0a6); }
.ex-dot { width: 11px; height: 11px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

.ex-result { border-left: 4px solid var(--c, #9aa0a6); }
.ex-result__main { display: flex; align-items: center; gap: 9px; }
.ex-result__cond { font-size: 1.15rem; font-weight: 700; color: var(--color-text, #32363a); }
.ex-result__kpis { display: flex; gap: 22px; }
.ex-result__kpis b { font-size: 1.25rem; font-weight: 700; color: var(--color-text, #32363a); display: block; }
.ex-result__kpis .ex-kpi-cond { display: flex; align-items: center; gap: 7px; }
.ex-result__kpis span { font-size: 0.72rem; color: var(--color-text-muted, #9aa0a6); }

.ex-ref { display: grid; gap: 0; margin: 0; }
.ex-ref > div { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid var(--color-border, #f2f4f6); }
.ex-ref dt { color: var(--color-text-muted, #6A6D70); font-size: 0.85rem; margin: 0; }
.ex-ref dd { margin: 0; font-weight: 600; color: var(--color-text, #32363a); }
.ex-note { font-size: 0.76rem; color: var(--color-text-muted, #9aa0a6); margin: 10px 0 0; line-height: 1.4; }

.ex-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
.ex-table th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.3px; color: var(--color-text-muted, #9aa0a6); font-weight: 600; padding: 4px 6px; border-bottom: 1px solid var(--color-border, #eef0f2); }
.ex-table td { padding: 6px 6px; border-bottom: 1px solid var(--color-border, #f2f4f6); }
.ex-table .r { text-align: right; }
.ex-table tfoot td { border-top: 2px solid var(--color-border, #e2e6ea); border-bottom: none; color: var(--color-text, #32363a); }
.ex-missing td { color: var(--color-text-muted, #b5b9bd); }

.ex-formula { font-size: 0.9rem; color: var(--color-text, #32363a); }
.ex-formula__calc { margin-top: 8px; font-size: 1rem; }
.ex-formula__calc b { color: #0A6ED1; }

.ex-scale { list-style: none; margin: 0; padding: 0; }
.ex-scale li { display: flex; align-items: center; gap: 9px; padding: 7px 8px; border-radius: 7px; font-size: 0.85rem; }
.ex-scale li.is-match { background: rgba(10, 110, 209, 0.08); font-weight: 600; }
.ex-scale__range { font-variant-numeric: tabular-nums; color: var(--color-text-muted, #6A6D70); min-width: 92px; }
.ex-scale__cond { color: var(--color-text, #32363a); }
.ex-scale__here { margin-left: auto; color: #0A6ED1; font-size: 0.78rem; }

.ex-ieee { list-style: none; margin: 0 0 4px; padding: 0; display: flex; flex-wrap: wrap; gap: 6px 14px; }
.ex-ieee li { display: inline-flex; gap: 6px; font-size: 0.82rem; }
.ex-ieee strong { color: var(--color-text, #32363a); }
.ex-ieee span { color: var(--color-text-muted, #6A6D70); }

html[data-theme="dark"] .ex-card { border-color: #3f4448; }
html[data-theme="dark"] .ex-result__cond, html[data-theme="dark"] .ex-table tfoot td, html[data-theme="dark"] .ex-scale__cond, html[data-theme="dark"] .ex-ieee strong { color: #e5e6e7; }
</style>
