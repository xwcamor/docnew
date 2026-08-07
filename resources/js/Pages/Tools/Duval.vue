<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Card, Select, SelectOption, InputNumber, Button, Tabs, TabPane, Tooltip } from 'ant-design-vue';
import { RadarChartOutlined, ReloadOutlined, ExperimentOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import DuvalTab from '@/Components/Transformers/DuvalTab.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const props = defineProps({
    oils: { type: Array, default: () => [] },
});

const { t } = useI18n();

// ── Estado de entrada ───────────────────────────────────────────────────────
const oil = ref('mineral');
const gases = reactive({ h2: null, ch4: null, c2h4: null, c2h6: null, c2h2: null });

// Los 5 gases de DGA. El motor usa los que correspondan a cada triángulo:
// T1 (CH4/C2H4/C2H2), T4 (H2/CH4/C2H6), T5 (CH4/C2H4/C2H6), pentágono (los 5).
const GAS_FIELDS = [
    { key: 'h2',   label: 'H₂' },
    { key: 'ch4',  label: 'CH₄' },
    { key: 'c2h4', label: 'C₂H₄' },
    { key: 'c2h6', label: 'C₂H₆' },
    { key: 'c2h2', label: 'C₂H₂' },
];

// ── Resultado del motor (live) ──────────────────────────────────────────────
const result = ref({ duval: null, geometry: { triangles: {}, pentagons: {} } });
const loading = ref(false);

// DuvalTab consume `cromas[].duval` + geometría. Le pasamos UNA muestra sintética
// (active_fault: true → siempre dibuja el punto si hay gases para ubicarlo).
const cromas = computed(() => {
    if (!result.value.duval) return [];
    return [{
        duval: result.value.duval,
        sample_date: '0000-00-00',
        active_fault: true,
    }];
});
const geometry = computed(() => result.value.geometry ?? { triangles: {}, pentagons: {} });
const hasPentagons = computed(() => Object.keys(geometry.value.pentagons ?? {}).length > 0);

// Tab activo (triángulo / pentágono). Si el aceite no tiene pentágonos, cae a tri.
const tab = ref('triangle');
watch(hasPentagons, (has) => { if (!has && tab.value === 'pentagon') tab.value = 'triangle'; });

const hasAnyGas = computed(() => GAS_FIELDS.some((f) => Number(gases[f.key]) > 0));

// ── Live calculation (debounced) ────────────────────────────────────────────
let timer = null;
const calculate = async () => {
    loading.value = true;
    try {
        const qs = new URLSearchParams({ oil: oil.value });
        GAS_FIELDS.forEach((f) => qs.append(f.key, gases[f.key] ?? 0));
        const res = await fetch(`${route('tools.duval.calculate')}?${qs.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.ok) result.value = await res.json();
    } finally {
        loading.value = false;
    }
};
const scheduleCalc = () => {
    clearTimeout(timer);
    timer = setTimeout(calculate, 280);
};

watch([oil, () => ({ ...gases })], scheduleCalc, { deep: true });

// Ejemplo representativo por caso (carga el del aceite SELECCIONADO). El de LTC
// es el punto del propio Excel oficial de Duval (cae en T3).
const EXAMPLES = {
    mineral:         { h2: 137.6, ch4: 5.5,  c2h4: 10.1, c2h6: 10.5, c2h2: 2.7 },
    silicona:        { h2: 30,    ch4: 40,   c2h4: 95,   c2h6: 20,   c2h2: 3 },
    vegetal_soya:    { h2: 30,    ch4: 40,   c2h4: 95,   c2h6: 20,   c2h2: 3 },
    vegetal_girasol: { h2: 30,    ch4: 40,   c2h4: 95,   c2h6: 20,   c2h2: 3 },
    ester_sintetico: { h2: 30,    ch4: 40,   c2h4: 95,   c2h6: 20,   c2h2: 3 },
    ltc:             { h2: 0,     ch4: 2342, c2h4: 3518, c2h6: 0,    c2h2: 12 },
};
const loadExample = () => {
    Object.assign(gases, EXAMPLES[oil.value] ?? EXAMPLES.mineral);
};
const clearAll = () => {
    Object.assign(gases, { h2: null, ch4: null, c2h4: null, c2h6: null, c2h2: null });
};

onMounted(loadExample);
</script>

<template>
    <Head :title="t('tools.duval_title')" />

    <div class="sap-index dtool">
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon"><RadarChartOutlined /></div>
                <div class="page-header__heading">
                    <h1>{{ t('tools.duval_title') }}</h1>
                    <p>{{ t('tools.duval_subtitle') }}</p>
                </div>
            </div>
        </div>

        <!-- Entradas: aceite + gases (live) -->
        <Card class="dtool__inputs" :bodyStyle="{ padding: '16px 18px' }">
            <div class="dtool__row">
                <div class="dtool__field dtool__field--oil">
                    <label>{{ t('tools.oil_type') }}</label>
                    <Select v-model:value="oil" style="width: 100%">
                        <SelectOption v-for="o in oils" :key="o.code" :value="o.code">{{ o.name }}</SelectOption>
                    </Select>
                </div>

                <div v-for="f in GAS_FIELDS" :key="f.key" class="dtool__field">
                    <label>{{ f.label }} <span class="dtool__unit">ppm</span></label>
                    <InputNumber
                        v-model:value="gases[f.key]"
                        :min="0"
                        :step="0.1"
                        :precision="1"
                        style="width: 100%"
                        @pressEnter="calculate"
                    />
                </div>

                <div class="dtool__actions">
                    <Tooltip :title="t('tools.example_hint')">
                        <Button @click="loadExample"><template #icon><ExperimentOutlined /></template>{{ t('tools.example') }}</Button>
                    </Tooltip>
                    <Button @click="clearAll"><template #icon><ReloadOutlined /></template>{{ t('tools.clear') }}</Button>
                </div>
            </div>
            <p class="dtool__note">{{ t('tools.gas_note') }}</p>
        </Card>

        <!-- Tabs: triángulo / pentágono -->
        <Card class="dtool__viz" :bodyStyle="{ padding: '8px 16px 16px' }">
            <Tabs v-model:activeKey="tab">
                <TabPane key="triangle" :tab="t('tools.tab_triangle')">
                    <DuvalTab
                        v-if="tab === 'triangle'"
                        :cromas="cromas"
                        :geometry="geometry"
                        force-mode="triangle"
                        hide-controls
                    />
                    <div v-if="!hasAnyGas" class="dtool__hint">{{ t('tools.enter_gases') }}</div>
                </TabPane>

                <TabPane key="pentagon" :tab="t('tools.tab_pentagon')" :disabled="!hasPentagons">
                    <DuvalTab
                        v-if="tab === 'pentagon'"
                        :cromas="cromas"
                        :geometry="geometry"
                        force-mode="pentagon"
                        hide-controls
                    />
                    <div v-if="!hasAnyGas" class="dtool__hint">{{ t('tools.enter_gases') }}</div>
                    <div v-else-if="!hasPentagons" class="dtool__hint">{{ t('tools.no_pentagon') }}</div>
                </TabPane>
            </Tabs>
        </Card>
    </div>
</template>

<style scoped>
.dtool__inputs { margin-bottom: 16px; }
.dtool__row {
    display: flex;
    align-items: flex-end;
    gap: 14px;
    flex-wrap: wrap;
}
.dtool__field { display: flex; flex-direction: column; gap: 4px; min-width: 110px; }
.dtool__field--oil { min-width: 220px; flex: 0 0 220px; }
.dtool__field label {
    font-size: 0.78rem; font-weight: 600; color: var(--color-text-strong, #1f2329);
}
.dtool__unit { font-weight: 400; color: var(--color-text-muted, #8a9099); font-size: 0.72rem; }
.dtool__actions { display: flex; gap: 8px; margin-left: auto; align-items: flex-end; }
.dtool__note {
    margin: 12px 0 0; font-size: 0.78rem; color: var(--color-text-muted, #6a7280);
}
.dtool__hint {
    text-align: center; color: var(--color-text-muted, #8a9099);
    font-size: 0.9rem; padding: 12px 16px 4px;
}
@media (max-width: 768px) {
    .dtool__field, .dtool__field--oil { flex: 1 1 calc(50% - 14px); min-width: 0; }
    .dtool__actions { margin-left: 0; width: 100%; }
}
</style>
