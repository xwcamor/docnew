<script setup>
import { computed } from 'vue';
import { Empty, Tag } from 'ant-design-vue';
import VChart from 'vue-echarts';
import { use } from 'echarts/core';
import { BarChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { useI18n } from '@/Plugins/i18n';

/**
 * KeyGasTab — método del Gas Clave (IEEE C57.104).
 *
 * Muestra el diagnóstico del último ensayo (tipo de falla + gas clave + confianza)
 * y un gráfico de barras que compara el PERFIL medido de gases contra la FIRMA de
 * la falla detectada. Más abajo, el historial de fallas por ensayo.
 */
use([BarChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const props = defineProps({
    cromas: { type: Array, default: () => [] },
});

const { t } = useI18n();

const GAS_KEYS = ['co', 'h2', 'ch4', 'c2h6', 'c2h4', 'c2h2'];
const GAS_SUB = { co: 'CO', h2: 'H₂', ch4: 'CH₄', c2h6: 'C₂H₆', c2h4: 'C₂H₄', c2h2: 'C₂H₂' };
const FAULT_COLOR = { arco: '#C8281D', corona: '#7E57C2', sobre_aceite: '#F4801E', sobre_celulosa: '#2AA198' };
const faultLabel = (f) => (f ? t('cromas.keygas_fault_' + f) : '—');
const faultColor = (f) => FAULT_COLOR[f] ?? '#9aa0a6';

const byDate = (arr) => [...arr].sort((a, b) => (a.sample_date < b.sample_date ? -1 : 1));
const withKey = computed(() => byDate(props.cromas.filter((c) => c.keyGas)));
const latest = computed(() => withKey.value[withKey.value.length - 1] ?? null);
const hasData = computed(() => latest.value !== null);
// Igual que Duval: el Gas Clave solo es válido con falla activa. Con gases
// normales el % es ruido y elegiría una falla falsa.
const activeFault = computed(() => !!latest.value?.active_fault);

const history = computed(() => [...withKey.value].reverse());

const option = computed(() => {
    if (!latest.value) return {};
    const kg = latest.value.keyGas;
    return {
        animationDuration: 700,
        grid: { top: 30, right: 12, bottom: 26, left: 40 },
        legend: { top: 0, textStyle: { fontSize: 11 } },
        tooltip: { trigger: 'axis', valueFormatter: (v) => `${v} %` },
        xAxis: { type: 'category', data: GAS_KEYS.map((g) => GAS_SUB[g]), axisLabel: { fontSize: 11 } },
        yAxis: { type: 'value', name: '%', axisLabel: { fontSize: 10 } },
        series: [
            {
                name: t('cromas.keygas_measured'), type: 'bar',
                data: GAS_KEYS.map((g) => kg.measured[g]),
                itemStyle: { color: faultColor(kg.fault), borderRadius: [3, 3, 0, 0] },
            },
            {
                name: t('cromas.keygas_signature'), type: 'bar',
                data: GAS_KEYS.map((g) => kg.signature[g]),
                itemStyle: { color: '#c7ced6', borderRadius: [3, 3, 0, 0] },
            },
        ],
    };
});
</script>

<template>
    <div class="kg">
        <div v-if="!hasData" class="kg__empty">
            <Empty :description="$t('cromas.keygas_no_data')" />
        </div>

        <div v-else class="kg__body">
            <div class="kg__main">
                <div v-if="!activeFault" class="kg__diag lift kg__diag--ok">
                    <span class="kg__lbl">{{ $t('cromas.keygas_title') }}</span>
                    <div class="kg__nofault">{{ $t('diagnostics.duval_no_fault') }}</div>
                    <div class="kg__date">{{ latest.sample_date }}</div>
                </div>
                <div v-else class="kg__diag lift" :style="{ borderColor: faultColor(latest.keyGas.fault) }">
                    <span class="kg__lbl">{{ $t('cromas.keygas_title') }}</span>
                    <div class="kg__fault">
                        <span class="kg__dot" :style="{ background: faultColor(latest.keyGas.fault) }"></span>
                        <strong>{{ faultLabel(latest.keyGas.fault) }}</strong>
                    </div>
                    <div class="kg__meta">
                        <Tag :color="faultColor(latest.keyGas.fault)">
                            {{ $t('cromas.keygas_key') }}: {{ GAS_SUB[latest.keyGas.key_gas] }}
                        </Tag>
                        <span class="kg__conf">{{ $t('cromas.keygas_confidence') }}: {{ latest.keyGas.confidence }}%</span>
                    </div>
                    <div class="kg__date">{{ latest.sample_date }}</div>
                </div>

                <VChart v-if="activeFault" class="kg__chart" :option="option" autoresize />
            </div>

            <div v-if="history.length > 1" class="kg__hist">
                <div class="kg__histtitle">{{ $t('cromas.keygas_history') }}</div>
                <ul>
                    <li v-for="c in history" :key="c.id">
                        <span class="kg__hdate">{{ c.sample_date }}</span>
                        <span class="kg__hdot" :style="{ background: faultColor(c.keyGas.fault) }"></span>
                        <span class="kg__hfault">{{ faultLabel(c.keyGas.fault) }}</span>
                        <span class="kg__hconf">{{ c.keyGas.confidence }}%</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<style scoped>
.kg__empty { padding: 24px 0; }
.kg__body { display: flex; flex-direction: column; gap: 18px; }
.kg__main { display: flex; gap: 20px; flex-wrap: wrap; align-items: stretch; }
.kg__diag { flex: 1 1 240px; min-width: 230px; max-width: 320px; border: 1px solid; border-left-width: 4px; border-radius: 10px; padding: 16px 18px; }
.kg__diag--ok { border-color: #1D7044; }
.kg__nofault { margin: 8px 0; font-size: 0.9rem; color: var(--color-text, #32363a); line-height: 1.4; }
.kg__lbl { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); }
.kg__fault { display: flex; align-items: center; gap: 9px; margin: 8px 0; font-size: 1.1rem; }
.kg__dot { width: 12px; height: 12px; border-radius: 50%; }
.kg__meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.kg__conf { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); }
.kg__date { font-size: 0.8rem; color: var(--color-text-muted, #9aa0a6); margin-top: 8px; }
.kg__chart { flex: 1 1 360px; min-width: 300px; height: 260px; }
.kg__hist { border-top: 1px solid var(--color-border, #eef0f2); padding-top: 12px; }
.kg__histtitle { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); margin-bottom: 8px; }
.kg__hist ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; }
.kg__hist li { display: flex; align-items: center; gap: 10px; font-size: 0.84rem; }
.kg__hdate { color: var(--color-text-muted, #6A6D70); width: 130px; }
.kg__hdot { width: 9px; height: 9px; border-radius: 50%; }
.kg__hfault { color: var(--color-text, #32363a); flex: 1; }
.kg__hconf { color: var(--color-text-muted, #9aa0a6); }

html[data-theme="dark"] .kg__hist, html[data-theme="dark"] .kg__histtitle { border-color: #3f4448; }
</style>
