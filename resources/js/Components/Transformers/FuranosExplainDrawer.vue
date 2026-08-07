<script setup>
import { ref, watch } from 'vue';
import { Drawer, Spin, Empty, Alert } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';

/**
 * FuranosExplainDrawer — "¿Por qué este resultado?" para furanos. Muestra la
 * conversión 2-FAL (ppb→ppm), el DP de Chendong con su fórmula, y la escala
 * editable con la banda donde cae. Pide la traza al backend al abrir.
 */
const props = defineProps({
    open:            { type: Boolean, default: false },
    transformerSlug: { type: String,  default: '' },
    fal:             { type: [Number, String], default: null },
});
const emit = defineEmits(['update:open']);

const { t } = useI18n();
const { condLabel } = useConditions();
const colorHex = semaforoHex;

const loading = ref(false);
const data = ref(null);

const fetchIt = async () => {
    if (props.fal === null || props.fal === '' || !props.transformerSlug) { data.value = null; return; }
    loading.value = true;
    data.value = null;
    try {
        const { data: res } = await window.axios.post(
            route('business_management.transformers.furanos.explain', props.transformerSlug),
            { fal: props.fal },
        );
        data.value = res.furanos;
    } catch (_) {
        data.value = null;
    } finally {
        loading.value = false;
    }
};
watch(() => props.open, (v) => { if (v) fetchIt(); });

const fmtNum = (v) => (v === null || v === undefined ? '—' : (Number.isInteger(v) ? v : Number(v).toFixed(2)));
const bandLabel = (from, to) => `${from === null || from === undefined ? '−∞' : fmtNum(from)} – ${to === null || to === undefined ? '∞' : fmtNum(to)}`;
const d = () => data.value;
</script>

<template>
    <Drawer
        :open="open"
        :title="$t('furanos.explain.title')"
        :width="520"
        placement="right"
        @close="emit('update:open', false)"
    >
        <div v-if="loading" class="ex-loading"><Spin /> <span>{{ $t('furanos.explain.loading') }}</span></div>

        <template v-else-if="d() && d().has_value">
            <p class="ex-sub">{{ $t('furanos.explain.subtitle') }}</p>

            <Alert
                v-if="d().paper_warning"
                type="warning"
                show-icon
                class="ex-paper-warn"
                :message="$t('furanos.explain.paper_warning_title')"
                :description="$t('furanos.explain.paper_warning_body')"
            />

            <section class="ex-card ex-result" :style="{ '--c': colorHex(d().color) }">
                <div class="ex-result__kpis">
                    <div class="ex-result__kpi">
                        <b class="ex-result__cond">
                            <span class="ex-dot" :style="{ background: colorHex(d().color) }"></span>
                            {{ condLabel(d().condition) }}
                        </b>
                        <span>{{ $t('furanos.explain.status') }}</span>
                    </div>
                    <div class="ex-result__kpi">
                        <b>{{ fmtNum(d().fal_ppb) }}</b>
                        <span>{{ $t('furanos.explain.your_value') }} (ppb)</span>
                    </div>
                    <div class="ex-result__kpi">
                        <b>{{ d().rating !== null ? d().rating + '/4' : '—' }}</b>
                        <span>{{ $t('furanos.explain.rating') }}</span>
                    </div>
                </div>
            </section>

            <section class="ex-card">
                <h4>{{ $t('furanos.explain.conversion') }}</h4>
                <dl class="ex-ref">
                    <div><dt>{{ $t('furanos.explain.fal_ppb') }}</dt><dd>{{ fmtNum(d().fal_ppb) }} ppb</dd></div>
                    <div><dt>{{ $t('furanos.explain.ppm') }}</dt><dd>{{ fmtNum(d().ppm) }} ppm <span class="ex-muted">(÷ 1000)</span></dd></div>
                    <div><dt>{{ $t('furanos.explain.dp') }}</dt><dd>{{ d().dp ?? '—' }}</dd></div>
                    <div v-if="d().life_percent !== null && d().life_percent !== undefined">
                        <dt>{{ $t('furanos.explain.life') }}</dt>
                        <dd>{{ d().life_percent }}% <span class="ex-muted">{{ $t('furanos.explain.life_hint') }}</span></dd>
                    </div>
                </dl>
            </section>

            <!-- Fórmula (en su propio bloque) -->
            <section class="ex-card">
                <h4>{{ $t('furanos.explain.formula') }}</h4>
                <div class="ex-formula">{{ $t('furanos.explain.dp_formula') }}</div>
            </section>

            <section v-if="d().scale.length" class="ex-card">
                <h4>{{ $t('furanos.explain.scale') }}</h4>
                <ul class="ex-scale">
                    <li v-for="(b, i) in d().scale" :key="i" :class="{ 'is-match': b.matched }">
                        <span class="ex-dot" :style="{ background: colorHex(b.color) }"></span>
                        <span class="ex-scale__range">{{ bandLabel(b.from, b.to) }}</span>
                        <span class="ex-scale__cond">{{ condLabel(b.condition) }}</span>
                        <span v-if="b.matched" class="ex-scale__here">← {{ $t('furanos.explain.your_value') }} ({{ fmtNum(d().ppm) }} ppm)</span>
                    </li>
                </ul>
            </section>

            <!-- Fuente / referencia del diagnóstico -->
            <section v-if="d().reference" class="ex-card">
                <h4>{{ $t('furanos.explain.reference') }}</h4>
                <div v-if="d().reference.formula" class="ex-src">
                    <div class="ex-src__head">
                        <span class="ex-src__label">{{ $t('furanos.explain.source_formula') }}</span>
                        <span class="ex-badge" :class="d().reference.formula.status">
                            {{ $t('furanos.explain.status_' + d().reference.formula.status) }}
                        </span>
                    </div>
                    <p class="ex-src__cite">{{ d().reference.formula.citation }}</p>
                    <a v-if="d().reference.formula.url" :href="d().reference.formula.url" target="_blank" rel="noopener" class="ex-src__url">{{ d().reference.formula.url }}</a>
                </div>
                <div v-if="d().reference.scale" class="ex-src">
                    <div class="ex-src__head">
                        <span class="ex-src__label">{{ $t('furanos.explain.source_scale') }}</span>
                        <span class="ex-badge" :class="d().reference.scale.status">
                            {{ $t('furanos.explain.status_' + d().reference.scale.status) }}
                        </span>
                    </div>
                    <p class="ex-src__cite">{{ d().reference.scale.citation }}</p>
                    <a v-if="d().reference.scale.url" :href="d().reference.scale.url" target="_blank" rel="noopener" class="ex-src__url">{{ d().reference.scale.url }}</a>
                </div>
            </section>
        </template>

        <Empty v-else :description="$t('furanos.explain.no_value')" />
    </Drawer>
</template>

<style scoped>
.ex-loading { display: flex; align-items: center; gap: 10px; padding: 30px 0; color: var(--color-text-muted, #6A6D70); }
.ex-sub { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 16px; }
.ex-paper-warn { margin-bottom: 14px; }
.ex-card { border: 1px solid var(--color-border, #eef0f2); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
.ex-card h4 { margin: 0 0 10px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); }
.ex-muted { color: var(--color-text-muted, #9aa0a6); }
.ex-dot { width: 11px; height: 11px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.ex-result { border-left: 4px solid var(--c, #9aa0a6); }
.ex-result__kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; align-items: start; }
.ex-result__kpi { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.ex-result__kpi b { font-size: 1.15rem; font-weight: 700; color: var(--color-text, #32363a); }
.ex-result__kpi span { font-size: 0.72rem; color: var(--color-text-muted, #9aa0a6); }
.ex-result__cond { display: flex; align-items: center; gap: 7px; line-height: 1.2; }
.ex-ref { display: grid; gap: 0; margin: 0; }
.ex-ref > div { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid var(--color-border, #f2f4f6); }
.ex-ref dt { color: var(--color-text-muted, #6A6D70); font-size: 0.85rem; margin: 0; }
.ex-ref dd { margin: 0; font-weight: 600; color: var(--color-text, #32363a); }
.ex-note { font-size: 0.76rem; color: var(--color-text-muted, #9aa0a6); margin: 10px 0 0; line-height: 1.4; }
.ex-formula { font-size: 0.95rem; color: var(--color-text, #32363a); font-variant-numeric: tabular-nums; }
.ex-scale { list-style: none; margin: 0; padding: 0; }
.ex-scale li { display: flex; align-items: center; gap: 9px; padding: 7px 8px; border-radius: 7px; font-size: 0.85rem; }
.ex-scale li.is-match { background: rgba(10, 110, 209, 0.08); font-weight: 600; }
.ex-scale__range { font-variant-numeric: tabular-nums; color: var(--color-text-muted, #6A6D70); min-width: 92px; }
.ex-scale__cond { color: var(--color-text, #32363a); }
.ex-scale__here { margin-left: auto; color: #0A6ED1; font-size: 0.78rem; }
.ex-src { padding: 8px 0; border-bottom: 1px solid var(--color-border, #f2f4f6); }
.ex-src:last-child { border-bottom: none; }
.ex-src__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px; }
.ex-src__label { font-size: 0.82rem; font-weight: 600; color: var(--color-text, #32363a); }
.ex-src__cite { margin: 0; font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); line-height: 1.45; }
.ex-src__url { font-size: 0.76rem; color: #0A6ED1; word-break: break-all; }
.ex-badge { font-size: 0.68rem; font-weight: 600; padding: 1px 8px; border-radius: 10px; white-space: nowrap; }
.ex-badge.confirmed { background: rgba(29, 112, 68, 0.12); color: #1D7044; }
.ex-badge.unconfirmed { background: rgba(233, 162, 59, 0.15); color: #B26A00; }
html[data-theme="dark"] .ex-card { border-color: #3f4448; }
html[data-theme="dark"] .ex-result__cond, html[data-theme="dark"] .ex-scale__cond, html[data-theme="dark"] .ex-ref dd { color: #e5e6e7; }
</style>
