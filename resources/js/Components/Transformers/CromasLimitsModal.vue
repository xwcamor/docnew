<script setup>
import { computed } from 'vue';
import { Modal } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * CromasLimitsModal — popup comparativo de límites por gas de las DOS normas
 * (IEC 60599 valor típico vs IEEE C57.104-2019 Tabla 1, percentil 90), según
 * aceite+trafo + O2/N2 y edad, con el valor de la última muestra coloreado
 * (verde ≤ límite, rojo si lo supera). O2/N2 son informativos.
 */
const props = defineProps({
    open:    { type: Boolean, default: false },
    norms:   { type: Object, default: () => ({ gases: {}, oil: null, trafo: null }) },
    latest:  { type: Object, default: null }, // { h2, o2, ... } de la última muestra
});
const emit = defineEmits(['update:open']);

const { t } = useI18n();

const GASES = ['h2', 'o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2'];
const LABEL = {
    h2: 'H₂', o2: 'O₂', n2: 'N₂', ch4: 'CH₄', co: 'CO',
    co2: 'CO₂', c2h4: 'C₂H₄', c2h6: 'C₂H₆', c2h2: 'C₂H₂',
};

const rows = computed(() => GASES.map((g) => {
    const lim = props.norms?.gases?.[g] ?? {};
    const measured = props.latest && props.latest[g] != null ? Number(props.latest[g]) : null;
    return {
        gas: g,
        name: t('cromas.' + g),
        label: LABEL[g],
        measured,
        iec: lim.iec ?? null,
        ieee: lim.ieee ?? null,
        overIec: lim.iec != null && measured != null && measured > lim.iec,
        overIeee: lim.ieee != null && measured != null && measured > lim.ieee,
    };
}));

const title = computed(() => {
    const ctx = [props.norms?.oil, props.norms?.trafo].filter(Boolean).join(' · ');
    return ctx ? `${t('cromas.limits_title')} — ${ctx}` : t('cromas.limits_title');
});

const num = (v) => (v === null || v === undefined ? '—' : v);
</script>

<template>
    <Modal :open="open" :title="title" :footer="null" :width="640" @cancel="emit('update:open', false)">
        <p class="cl-note">{{ $t('cromas.limits_note') }}</p>
        <table class="cl-table">
            <thead>
                <tr>
                    <th class="left">{{ $t('cromas.gases') }}</th>
                    <th>{{ $t('cromas.limits_measured') }}</th>
                    <th>IEC 60599</th>
                    <th>IEEE C57.104-2019</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.gas" :class="{ 'cl-info': r.iec === null && r.ieee === null }">
                    <td class="left"><strong>{{ r.label }}</strong> <span class="cl-name">{{ r.name }}</span></td>
                    <td :class="{ 'cl-over': r.overIec || r.overIeee, 'cl-ok': r.measured != null && !(r.overIec || r.overIeee) }">
                        {{ num(r.measured) }}
                    </td>
                    <td :class="{ 'cl-over': r.overIec }">{{ num(r.iec) }}</td>
                    <td :class="{ 'cl-over': r.overIeee }">{{ num(r.ieee) }}</td>
                </tr>
            </tbody>
        </table>
        <p class="cl-foot">{{ $t('cromas.limits_foot') }}</p>
    </Modal>
</template>

<style scoped>
.cl-note { font-size: 0.85rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 12px; }
.cl-table { width: 100%; border-collapse: collapse; }
.cl-table th { text-align: center; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.3px; color: var(--color-text-muted, #6A6D70); padding: 8px 10px; border-bottom: 1px solid var(--color-border, #eef0f2); }
.cl-table td { text-align: center; padding: 8px 10px; border-bottom: 1px solid var(--color-border, #f2f4f6); font-size: 0.88rem; font-variant-numeric: tabular-nums; }
.cl-table .left { text-align: left; }
.cl-name { color: var(--color-text-muted, #9aa0a6); font-size: 0.78rem; }
.cl-ok { color: #1D7044; font-weight: 600; }
.cl-over { color: #C8281D; font-weight: 700; }
.cl-info td { opacity: 0.6; }
.cl-foot { font-size: 0.76rem; color: var(--color-text-muted, #9aa0a6); margin: 12px 0 0; line-height: 1.4; }
</style>
