<script setup>
import { ref, watch } from 'vue';
import { Drawer, Spin, Empty } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';

/**
 * FpotExplainDrawer — "¿Por qué este resultado?" para Factor de Potencia. El valor
 * medido entra tal cual (sin conversión) y se ubica en la escala editable; muestra
 * la banda donde cae. Pide la traza al backend al abrir.
 */
const props = defineProps({
    open:            { type: Boolean, default: false },
    transformerSlug: { type: String,  default: '' },
    value:           { type: [Number, String], default: null },
});
const emit = defineEmits(['update:open']);

const { t } = useI18n();
const { condLabel } = useConditions();
const colorHex = semaforoHex;

const loading = ref(false);
const data = ref(null);

const fetchIt = async () => {
    if (props.value === null || props.value === '' || !props.transformerSlug) { data.value = null; return; }
    loading.value = true;
    data.value = null;
    try {
        const { data: res } = await window.axios.post(
            route('business_management.transformers.fpot.explain', props.transformerSlug),
            { value: props.value },
        );
        data.value = res.fpot;
    } catch (_) {
        data.value = null;
    } finally {
        loading.value = false;
    }
};
watch(() => props.open, (v) => { if (v) fetchIt(); });

const fmtNum = (v) => (v === null || v === undefined ? '—' : (Number.isInteger(v) ? v : Number(v).toFixed(3)));
const bandLabel = (from, to) => `${from === null || from === undefined ? '−∞' : from} – ${to === null || to === undefined ? '∞' : to}`;
const d = () => data.value;
</script>

<template>
    <Drawer
        :open="open"
        :title="$t('fpot.explain.title')"
        :width="500"
        placement="right"
        @close="emit('update:open', false)"
    >
        <div v-if="loading" class="ex-loading"><Spin /> <span>{{ $t('fpot.explain.loading') }}</span></div>

        <template v-else-if="d() && d().has_value">
            <p class="ex-sub">{{ $t('fpot.explain.subtitle') }}</p>

            <section class="ex-card ex-result" :style="{ '--c': colorHex(d().color) }">
                <div class="ex-result__kpis">
                    <div>
                        <b class="ex-kpi-cond"><span class="ex-dot" :style="{ background: colorHex(d().color) }"></span>{{ condLabel(d().condition) }}</b>
                        <span>{{ $t('fpot.state') }}</span>
                    </div>
                    <div><b>{{ fmtNum(d().value) }}</b><span>{{ $t('fpot.explain.value') }}</span></div>
                    <div v-if="d().rating !== null"><b>{{ d().rating }}/4</b><span>{{ $t('fpot.explain.rating') }}</span></div>
                </div>
            </section>

            <section v-if="d().scale.length" class="ex-card">
                <h4>{{ $t('fpot.explain.scale') }}</h4>
                <ul class="ex-scale">
                    <li v-for="(b, i) in d().scale" :key="i" :class="{ 'is-match': b.matched }">
                        <span class="ex-dot" :style="{ background: colorHex(b.color) }"></span>
                        <span class="ex-scale__range">{{ bandLabel(b.from, b.to) }}</span>
                        <span class="ex-scale__cond">{{ condLabel(b.condition) }}</span>
                        <span v-if="b.matched" class="ex-scale__here">← {{ $t('fpot.explain.your_value') }} ({{ fmtNum(d().value) }})</span>
                    </li>
                </ul>
            </section>
        </template>

        <Empty v-else :description="$t('fpot.explain.no_value')" />
    </Drawer>
</template>

<style scoped>
.ex-loading { display: flex; align-items: center; gap: 10px; padding: 30px 0; color: var(--color-text-muted, #6A6D70); }
.ex-sub { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 16px; }
.ex-card { border: 1px solid var(--color-border, #eef0f2); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
.ex-card h4 { margin: 0 0 10px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); }
.ex-dot { width: 11px; height: 11px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.ex-result { border-left: 4px solid var(--c, #9aa0a6); }
.ex-result__main { display: flex; align-items: center; gap: 9px; }
.ex-result__cond { font-size: 1.15rem; font-weight: 700; color: var(--color-text, #32363a); }
.ex-result__kpis { display: flex; gap: 22px; }
.ex-result__kpis .ex-kpi-cond { display: flex; align-items: center; gap: 7px; }
.ex-result__kpis b { font-size: 1.25rem; font-weight: 700; color: var(--color-text, #32363a); display: block; }
.ex-result__kpis span { font-size: 0.72rem; color: var(--color-text-muted, #9aa0a6); }
.ex-scale { list-style: none; margin: 0; padding: 0; }
.ex-scale li { display: flex; align-items: center; gap: 9px; padding: 7px 8px; border-radius: 7px; font-size: 0.85rem; }
.ex-scale li.is-match { background: rgba(10, 110, 209, 0.08); font-weight: 600; }
.ex-scale__range { font-variant-numeric: tabular-nums; color: var(--color-text-muted, #6A6D70); min-width: 92px; }
.ex-scale__cond { color: var(--color-text, #32363a); }
.ex-scale__here { margin-left: auto; color: #0A6ED1; font-size: 0.78rem; }
html[data-theme="dark"] .ex-card { border-color: #3f4448; }
html[data-theme="dark"] .ex-result__cond, html[data-theme="dark"] .ex-scale__cond { color: #e5e6e7; }
</style>
