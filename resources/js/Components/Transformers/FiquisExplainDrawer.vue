<script setup>
import { computed, ref, watch } from 'vue';
import { Drawer, Spin, Empty } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';
import { fiquiFullName } from '@/utils/fiquiHeader';

/**
 * FiquisExplainDrawer — "¿Por qué este resultado?" para fisicoquímico. Muestra la
 * norma (IEEE C57.106), el aceite + clase de tensión usados, el score 1..4 de cada
 * parámetro contra sus umbrales (con dirección y peso), la suma ponderada, el DGAF
 * y el semáforo con la banda donde cae. Pide la traza al backend al abrir.
 */
const props = defineProps({
    open:            { type: Boolean, default: false },
    transformerSlug: { type: String,  default: '' },
    values:          { type: Object,  default: null }, // { rig, ten, acid, wat, pot }
});
const emit = defineEmits(['update:open']);

const { t } = useI18n();
const { condLabel } = useConditions();
const colorHex = semaforoHex;

const loading = ref(false);
const data = ref(null);

const fetchIt = async () => {
    if (!props.values || !props.transformerSlug) { data.value = null; return; }
    loading.value = true;
    data.value = null;
    try {
        const { data: res } = await window.axios.post(
            route('business_management.transformers.fiquis.explain', props.transformerSlug),
            props.values,
        );
        data.value = res.fiquis;
    } catch (_) {
        data.value = null;
    } finally {
        loading.value = false;
    }
};
watch(() => props.open, (v) => { if (v) fetchIt(); });

const fmtNum = (v) => (v === null || v === undefined ? '—' : (Number.isInteger(v) ? v : Number(v).toFixed(2)));
const bandLabel = (from, to) => `${from === null || from === undefined ? '−∞' : fmtNum(from)} – ${to === null || to === undefined ? '∞' : fmtNum(to)}`;
// Con la condición de ensayo pegada: acá los dos métodos de una misma propiedad
// conviven en la misma lista y el nombre pelado no alcanza.
const paramLabel = (p) => fiquiFullName(t, p);
const dirLabel = (dir) => (dir === 'higher' ? t('fiquis.explain.dir_higher') : t('fiquis.explain.dir_lower'));
const classLabel = (c) => t('fiquis.explain.class_' + c);
const thresholdsText = (th) => (th && th.length ? th.join(' / ') : '—');
const d = () => data.value;

// Los que puntúan (entran al DGAF) y los de REFERENCIA (rigidez D877, factor de
// potencia a 100 °C): tienen umbral y estado propios, pero no peso.
const scoredParams = computed(() => (data.value?.params ?? []).filter((p) => p.scores !== false));
const refParams = computed(() => (data.value?.params ?? []).filter((p) => p.scores === false));

/** "Mín 25 kV" / "Máx 5 %" — el límite pelado de la norma, sin la tolerancia. */
const limitText = (p) => {
    const dir = p.dir === 'higher' ? t('fiquis.explain.limit_min') : t('fiquis.explain.limit_max');
    return `${dir} ${fmtNum(p.limit)}${p.unit ? ' ' + p.unit : ''}`;
};
</script>

<template>
    <Drawer
        :open="open"
        :title="$t('fiquis.explain.title')"
        :width="580"
        placement="right"
        @close="emit('update:open', false)"
    >
        <div v-if="loading" class="ex-loading"><Spin /> <span>{{ $t('fiquis.explain.loading') }}</span></div>

        <template v-else-if="d() && d().has_rules">
            <p class="ex-sub">{{ $t('fiquis.explain.subtitle') }}</p>

            <!-- Resultado -->
            <section class="ex-card ex-result" :style="{ '--c': colorHex(d().color) }">
                <div class="ex-result__kpis">
                    <div>
                        <b class="ex-kpi-cond"><span class="ex-dot" :style="{ background: colorHex(d().color) }"></span>{{ condLabel(d().condition) }}</b>
                        <span>{{ $t('fiquis.state') }}</span>
                    </div>
                    <div><b>{{ d().dgaf ?? '—' }}</b><span>{{ $t('fiquis.explain.dgaf') }}</span></div>
                    <div v-if="d().rating !== null"><b>{{ d().rating }}/4</b><span>{{ $t('fiquis.explain.rating') }}</span></div>
                </div>
            </section>

            <!-- Referencia -->
            <section class="ex-card">
                <h4>{{ $t('fiquis.explain.reference') }}</h4>
                <dl class="ex-ref">
                    <div><dt>{{ $t('fiquis.explain.standard') }}</dt><dd>{{ $t('fiquis.explain.standard_value') }}</dd></div>
                    <div><dt>{{ $t('fiquis.explain.oil') }}</dt><dd>{{ d().oil ?? '—' }}</dd></div>
                    <div><dt>{{ $t('fiquis.explain.class') }}</dt><dd>{{ classLabel(d().class) }}</dd></div>
                </dl>
                <p class="ex-note">{{ $t('fiquis.explain.reference_note') }}</p>
            </section>

            <!-- Cálculo por parámetro -->
            <section class="ex-card">
                <h4>{{ $t('fiquis.explain.calc') }}</h4>
                <table class="ex-table">
                    <thead>
                        <tr>
                            <th>{{ $t('fiquis.explain.col_param') }}</th>
                            <th class="r">{{ $t('fiquis.explain.col_value') }}</th>
                            <th>{{ $t('fiquis.explain.col_thresholds') }}</th>
                            <th class="r">{{ $t('fiquis.explain.col_score') }}</th>
                            <th class="r">{{ $t('fiquis.explain.col_weight') }}</th>
                            <th class="r">{{ $t('fiquis.explain.col_contribution') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in scoredParams" :key="p.param" :class="{ 'ex-missing': p.value === null || p.score === null }">
                            <td>
                                <!-- Cuando sustituye, el título es el del método que
                                     REALMENTE se midió: decir "25 °C" sobre un valor
                                     medido a 100 °C sería mentir sobre el dato. -->
                                <strong>{{ paramLabel(p.measured_as || p.param) }}</strong>
                                <span class="ex-dir">{{ dirLabel(p.dir) }}</span>
                                <span
                                    v-if="p.measured_as"
                                    class="ex-subst"
                                    :title="$t('fiquis.explain.substituted_note', { main: paramLabel(p.param) })"
                                >{{ $t('fiquis.explain.substituted', { main: paramLabel(p.param) }) }}</span>
                            </td>
                            <td class="r">{{ p.value === null ? '—' : fmtNum(p.value) }}<span v-if="p.unit && p.value !== null" class="ex-unit"> {{ p.unit }}</span></td>
                            <td>
                                <span v-if="p.score === null" class="ex-muted">{{ $t('fiquis.explain.not_measured') }}</span>
                                <span v-else>{{ thresholdsText(p.thresholds) }}</span>
                            </td>
                            <td class="r">{{ p.score ?? '—' }}</td>
                            <td class="r">{{ fmtNum(p.weight) }}</td>
                            <td class="r">{{ p.contribution ?? '—' }}</td>
                        </tr>

                        <!-- Parámetros de REFERENCIA: se evalúan contra su propio
                             límite pero no entran en el promedio (medirían dos
                             veces la misma propiedad). -->
                        <template v-if="refParams.length">
                            <tr class="ex-refhead">
                                <td colspan="6">
                                    {{ $t('fiquis.explain.ref_title') }}
                                    <span class="ex-refhead__why">{{ $t('fiquis.explain.ref_why') }}</span>
                                </td>
                            </tr>
                            <tr v-for="p in refParams" :key="p.param" class="ex-ref-row" :class="{ 'ex-missing': p.value === null }">
                                <td>
                                    <strong>{{ paramLabel(p.param) }}</strong>
                                    <span class="ex-dir">{{ dirLabel(p.dir) }}</span>
                                </td>
                                <td class="r">{{ p.value === null ? '—' : fmtNum(p.value) }}<span v-if="p.unit && p.value !== null" class="ex-unit"> {{ p.unit }}</span></td>
                                <td>
                                    <span v-if="p.value === null" class="ex-muted">{{ $t('fiquis.explain.not_measured') }}</span>
                                    <span v-else>{{ limitText(p) }}</span>
                                </td>
                                <td class="r">
                                    <span v-if="p.status" class="ex-status" :class="'is-' + p.status">{{ $t('fiquis.explain.status_' + p.status) }}</span>
                                    <span v-else>—</span>
                                </td>
                                <td class="r ex-muted" colspan="2">{{ $t('fiquis.explain.no_weight') }}</td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot v-if="d().has_data">
                        <tr>
                            <td colspan="5" class="r">Σ</td>
                            <td class="r"><strong>{{ d().sum_score_weight }} ÷ {{ d().sum_weight }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <template v-if="d().has_data">
                <!-- Fórmula -->
                <section class="ex-card">
                    <h4>{{ $t('fiquis.explain.formula') }}</h4>
                    <div class="ex-formula">
                        {{ $t('fiquis.explain.formula_expr') }}
                        <div class="ex-formula__calc">= {{ d().sum_score_weight }} ÷ {{ d().sum_weight }} = <b>{{ d().dgaf }}</b></div>
                    </div>
                </section>

                <!-- Semáforo -->
                <section class="ex-card">
                    <h4>{{ $t('fiquis.explain.semaphore') }}</h4>
                    <ul class="ex-scale">
                        <li v-for="(b, i) in d().semaphore" :key="i" :class="{ 'is-match': b.matched }">
                            <span class="ex-dot" :style="{ background: colorHex(b.color) }"></span>
                            <span class="ex-scale__range">{{ bandLabel(b.from, b.to) }}</span>
                            <span class="ex-scale__cond">{{ condLabel(b.condition) }}</span>
                            <span v-if="b.matched" class="ex-scale__here">← {{ $t('fiquis.explain.your_dgaf') }} ({{ d().dgaf }})</span>
                        </li>
                    </ul>
                </section>
            </template>
            <Empty v-else :description="$t('fiquis.explain.no_data')" />
        </template>

        <Empty v-else :description="$t('fiquis.explain.no_rules')" />
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
.ex-result__kpis .ex-kpi-cond { display: flex; align-items: center; gap: 7px; }
.ex-result__kpis b { font-size: 1.25rem; font-weight: 700; color: var(--color-text, #32363a); display: block; }
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

/* Bloque de parámetros de referencia (no puntúan). */
.ex-refhead td { padding-top: 12px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; color: var(--color-text-muted, #9aa0a6); border-top: 1px dashed var(--color-border, #e2e6ea); }
.ex-refhead__why { display: block; font-weight: 400; text-transform: none; letter-spacing: 0; font-size: 0.74rem; margin-top: 2px; }
.ex-ref-row td { background: rgba(120, 130, 140, 0.045); }
.ex-status { font-weight: 700; font-size: 0.76rem; }
.ex-status.is-ok   { color: #1D7044; }
.ex-status.is-near { color: #E9A23B; }
.ex-status.is-out  { color: #C8281D; }
html[data-theme="dark"] .ex-ref-row td { background: rgba(255, 255, 255, 0.04); }
.ex-dir { display: block; font-size: 0.68rem; color: var(--color-text-muted, #9aa0a6); font-weight: 400; }
/* Insignia "medido por ASTM D877": el método alterno ocupó el lugar del principal. */
.ex-subst {
    display: inline-block;
    margin-top: 3px;
    padding: 1px 6px;
    border-radius: 9px;
    font-size: 0.66rem;
    font-weight: 500;
    line-height: 1.5;
    color: var(--color-primary, #0a6ed1);
    background: color-mix(in srgb, var(--color-primary, #0a6ed1) 12%, transparent);
    cursor: help;
}
/* La separación va por CSS, no por un espacio en la plantilla: Vue condensa el
   espacio suelto delante de la interpolación y el valor quedaba pegado a la
   unidad ("0.01mg KOH/g"). nowrap para que la unidad no se parta a la mitad
   ("mN/ m") cuando la columna queda angosta. */
.ex-unit {
    color: var(--color-text-muted, #9aa0a6);
    font-size: 0.78rem;
    margin-left: 4px;
    white-space: nowrap;
}
.ex-formula { font-size: 0.9rem; color: var(--color-text, #32363a); }
.ex-formula__calc { margin-top: 8px; font-size: 1rem; }
.ex-formula__calc b { color: #0A6ED1; }
.ex-scale { list-style: none; margin: 0; padding: 0; }
.ex-scale li { display: flex; align-items: center; gap: 9px; padding: 7px 8px; border-radius: 7px; font-size: 0.85rem; }
.ex-scale li.is-match { background: rgba(10, 110, 209, 0.08); font-weight: 600; }
.ex-scale__range { font-variant-numeric: tabular-nums; color: var(--color-text-muted, #6A6D70); min-width: 92px; }
.ex-scale__cond { color: var(--color-text, #32363a); }
.ex-scale__here { margin-left: auto; color: #0A6ED1; font-size: 0.78rem; }
html[data-theme="dark"] .ex-card { border-color: #3f4448; }
html[data-theme="dark"] .ex-result__cond, html[data-theme="dark"] .ex-table tfoot td, html[data-theme="dark"] .ex-scale__cond, html[data-theme="dark"] .ex-ref dd { color: #e5e6e7; }
</style>
