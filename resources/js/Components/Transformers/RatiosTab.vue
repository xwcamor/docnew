<script setup>
import { computed } from 'vue';
import { Empty, Tooltip } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * RatiosTab — métodos de relaciones de gases: Rogers + Doernenburg (IEC 60599).
 * Complementarios: solo se interpretan con FALLA ACTIVA (como Duval/Gas Clave).
 * Muestra las relaciones de la última muestra y la falla que indica cada método.
 */
const props = defineProps({
    cromas: { type: Array, default: () => [] },
});

const { t } = useI18n();

const byDate = (arr) => [...arr].sort((a, b) => (a.sample_date < b.sample_date ? -1 : 1));
const withRatios = computed(() => byDate(props.cromas.filter((c) => c.ratios)));
const latest = computed(() => withRatios.value[withRatios.value.length - 1] ?? null);
const hasData = computed(() => latest.value !== null);
const activeFault = computed(() => !!latest.value?.active_fault);

const methods = computed(() => {
    if (!latest.value) return [];
    const r = latest.value.ratios;
    return [
        { key: 'rogers', name: t('cromas.ratios.rogers'), data: r.rogers },
        { key: 'doernenburg', name: t('cromas.ratios.doernenburg'), data: r.doernenburg },
    ];
});

const faultLabel = (m) => {
    if (!m.complete) return t('cromas.ratios.na');
    return m.fault ? t('cromas.ratios.fault.' + m.fault) : t('cromas.ratios.na');
};
const faultBad = (m) => m.complete && m.fault && m.fault !== 'normal';
const fmt = (v) => (v === null || v === undefined ? '—' : v);
</script>

<template>
    <div class="rt">
        <div v-if="!hasData" class="rt__empty">
            <Empty :description="$t('cromas.ratios.no_data')" />
        </div>

        <template v-else>
            <Tooltip :title="$t('cromas.ratios.help')">
                <p class="rt__help">{{ $t('cromas.ratios.help') }}</p>
            </Tooltip>

            <div v-if="!activeFault" class="rt__nofault">{{ $t('cromas.ratios.no_fault') }}</div>

            <div class="rt__grid">
                <div v-for="m in methods" :key="m.key" class="rt__card" :class="{ 'rt__card--bad': activeFault && faultBad(m.data) }">
                    <div class="rt__name">{{ m.name }}</div>
                    <ul class="rt__ratios">
                        <li v-for="(rr, i) in m.data.ratios" :key="i">
                            <span class="rt__rlabel">{{ rr.label }}</span>
                            <span class="rt__rval">{{ fmt(rr.value) }}</span>
                        </li>
                    </ul>
                    <div class="rt__verdict">
                        <span class="rt__vlbl">{{ $t('cromas.ratios.verdict') }}</span>
                        <strong v-if="activeFault" :class="{ 'rt__bad': faultBad(m.data) }">{{ faultLabel(m.data) }}</strong>
                        <strong v-else class="rt__muted">{{ $t('cromas.ratios.na') }}</strong>
                    </div>
                </div>
            </div>
            <div class="rt__date">{{ latest.sample_date }}</div>
        </template>
    </div>
</template>

<style scoped>
.rt__empty { padding: 24px 0; }
.rt__help { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 12px; }
.rt__nofault { font-size: 0.9rem; color: var(--color-text, #32363a); background: var(--color-surface-alt, #f5f6f7); border-left: 3px solid #1D7044; padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; }
.rt__grid { display: flex; gap: 18px; flex-wrap: wrap; }
.rt__card { flex: 1 1 260px; min-width: 240px; border: 1px solid var(--color-border, #eef0f2); border-left: 4px solid #9aa0a6; border-radius: 10px; padding: 14px 16px; }
.rt__card--bad { border-left-color: #C8281D; }
.rt__name { font-size: 0.95rem; font-weight: 600; color: var(--color-text, #32363a); margin-bottom: 10px; }
.rt__ratios { list-style: none; margin: 0 0 12px; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.rt__ratios li { display: flex; justify-content: space-between; font-size: 0.85rem; }
.rt__rlabel { color: var(--color-text-muted, #6A6D70); }
.rt__rval { font-variant-numeric: tabular-nums; color: var(--color-text, #32363a); }
.rt__verdict { border-top: 1px dashed var(--color-border, #eef0f2); padding-top: 10px; display: flex; flex-direction: column; gap: 2px; }
.rt__vlbl { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); }
.rt__verdict strong { font-size: 0.98rem; color: var(--color-text, #32363a); }
.rt__bad { color: #C8281D; }
.rt__muted { color: var(--color-text-muted, #9aa0a6); font-weight: 500; }
.rt__date { font-size: 0.8rem; color: var(--color-text-muted, #9aa0a6); margin-top: 12px; }
html[data-theme="dark"] .rt__card { background: #1f2429; border-color: #3f4448; }
</style>
