<script setup>
/**
 * Comparación "por grupos" — gases de varios transformadores a la vez.
 *   mode=gases    → solo los gráficos comparativos.
 *   mode=patrones → gráficos + tabla de datos por muestra.
 * El selector arma el grupo; "Comparar" recarga con los ids elegidos.
 */
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Select, Button, Card, message } from 'ant-design-vue';
import { LineChartOutlined, ExperimentOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GasComparisonCharts from '@/Components/Comparison/GasComparisonCharts.vue';
import ComparisonMatrix from '@/Components/Comparison/ComparisonMatrix.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const props = defineProps({
    mode:               { type: String, default: 'gases' },
    cap:                { type: Number, default: 8 },
    transformerOptions: { type: Array,  default: () => [] },
    customerOptions:    { type: Array,  default: () => [] },
    substationOptions:  { type: Array,  default: () => [] },
    brandOptions:       { type: Array,  default: () => [] },
    typeOptions:        { type: Array,  default: () => [] },
    selected:           { type: Array,  default: () => [] },
    data:               { type: Array,  default: () => [] },
    matrix:             { type: Array,  default: () => [] },
});

const { t } = useI18n();

const picked = ref([...props.selected]);
const loadingAction = ref(null);   // 'one' | 'all' | null — qué botón está cargando

const MAX = computed(() => props.cap || 8);

const runCompare = (ids, which) => {
    if (!ids.length) {
        message.warning(t('comparison.pick_hint'));
        return;
    }
    loadingAction.value = which;
    router.get(
        route('business_management.comparison.' + props.mode),
        { ids: ids.slice(0, MAX.value) },
        { preserveScroll: true, onFinish: () => { loadingAction.value = null; } },
    );
};

// ── Filtros de candidatos: acotan QUÉ trafos se pueden elegir/comparar ──────
const fBrand = ref(null);
const fType = ref(null);
const fBand = ref(null);
const fFault = ref(null);
const fCustomer = ref(null);
const fSubstation = ref(null);

const conditionOptions = computed(() => [
    { value: 'good',    label: t('transformers.band_good') },
    { value: 'regular', label: t('transformers.band_regular') },
    { value: 'bad',     label: t('transformers.band_bad') },
    { value: 'none',    label: t('transformers.band_none') },
]);
// Solo las fallas presentes en los datos visibles.
const faultOptions = computed(() => {
    const seen = [...new Set(props.transformerOptions.map((o) => o.fault).filter(Boolean))];
    return seen.map((f) => ({ value: f, label: t('comparison.duval_' + f) }));
});

const candidates = computed(() => props.transformerOptions.filter((o) =>
    (!fCustomer.value   || o.customer_id   === fCustomer.value) &&
    (!fSubstation.value || o.substation_id === fSubstation.value) &&
    (!fBrand.value      || o.brand_id      === fBrand.value) &&
    (!fType.value       || o.type_id       === fType.value) &&
    (!fBand.value       || o.band          === fBand.value) &&
    (!fFault.value      || o.fault         === fFault.value),
));

// Si cambia el filtro, podar la selección a los candidatos válidos.
watch(candidates, (list) => {
    const ok = new Set(list.map((o) => o.value));
    picked.value = picked.value.filter((id) => ok.has(id));
});

const compare = () => runCompare(picked.value, 'one');

// Comparar TODOS los candidatos filtrados (cap por modo).
const compareFiltered = () => {
    const ids = candidates.value.map((o) => o.value);
    if (!ids.length) { message.warning(t('comparison.pick_hint')); return; }
    if (ids.length > MAX.value) {
        message.info(t('comparison.group_capped', { max: MAX.value, total: ids.length }));
    }
    picked.value = ids.slice(0, MAX.value);
    runCompare(ids, 'all');
};

const isPatrones = computed(() => props.mode === 'patrones');
const hasResult = computed(() => (isPatrones.value ? props.matrix.length : props.data.length) > 0);
</script>

<template>
    <Head :title="isPatrones ? $t('comparison.patrones_title') : $t('comparison.gases_title')" />

    <div class="sap-index cmp-page">
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon">
                    <ExperimentOutlined v-if="isPatrones" />
                    <LineChartOutlined v-else />
                </div>
                <div class="page-header__heading">
                    <h1>{{ isPatrones ? $t('comparison.patrones_title') : $t('comparison.gases_title') }}</h1>
                    <p>{{ $t('comparison.subtitle') }}</p>
                </div>
            </div>
        </div>

        <Card class="cmp-picker" :body-style="{ padding: '16px' }">
            <!-- Filtros de candidatos: acotan qué trafos comparar (diagnóstico). -->
            <div class="cmp-filters">
                <span class="cmp-filters__lbl">{{ $t('comparison.filter_by') }}</span>
                <Select v-model:value="fCustomer" allow-clear show-search option-filter-prop="label"
                        :options="customerOptions" :placeholder="$t('transformers.customer')" class="cmp-filters__sel" />
                <Select v-model:value="fSubstation" allow-clear show-search option-filter-prop="label"
                        :options="substationOptions" :placeholder="$t('transformers.substation')" class="cmp-filters__sel" />
                <Select v-model:value="fBrand" allow-clear show-search option-filter-prop="label"
                        :options="brandOptions" :placeholder="$t('transformers.brand')" class="cmp-filters__sel" />
                <Select v-model:value="fType" allow-clear show-search option-filter-prop="label"
                        :options="typeOptions" :placeholder="$t('transformers.transformer_type')" class="cmp-filters__sel" />
                <Select v-model:value="fBand" allow-clear option-filter-prop="label"
                        :options="conditionOptions" :placeholder="$t('transformers.health_state')" class="cmp-filters__sel" />
                <Select v-model:value="fFault" allow-clear option-filter-prop="label"
                        :options="faultOptions" :placeholder="$t('comparison.col_duval')" class="cmp-filters__sel" />
            </div>

            <div class="cmp-picker__row">
                <Select
                    v-model:value="picked"
                    mode="multiple"
                    size="large"
                    show-search
                    option-filter-prop="label"
                    :max-tag-count="8"
                    :options="candidates"
                    :placeholder="$t('comparison.pick_hint')"
                    class="cmp-picker__select"
                />
                <Button type="primary" size="large" :loading="loadingAction === 'one'" :disabled="loadingAction !== null" @click="compare">
                    {{ $t('comparison.compare') }}
                </Button>
                <Button size="large" :loading="loadingAction === 'all'" :disabled="!candidates.length || loadingAction !== null" @click="compareFiltered">
                    {{ $t('comparison.compare_all', { n: candidates.length }) }}
                </Button>
            </div>

            <p class="cmp-picker__note">{{ $t('comparison.max_note', { max: MAX }) }}</p>
        </Card>

        <Card v-if="hasResult" class="cmp-result" :body-style="{ padding: '16px' }">
            <ComparisonMatrix v-if="isPatrones" :rows="matrix" />
            <GasComparisonCharts v-else :transformers="data" />
        </Card>
    </div>
</template>

<style scoped>
.cmp-page { display: flex; flex-direction: column; gap: 16px; }
.cmp-picker__row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.cmp-picker__select { flex: 1; min-width: 280px; }
.cmp-filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.cmp-filters__lbl { font-size: 0.84rem; color: var(--color-text-muted, #6A6D70); font-weight: 600; }
.cmp-filters__sel { min-width: 150px; }
.cmp-picker__note { margin: 12px 0 0; font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); }
.cmp-table { margin-top: 20px; }
.cmp-table__title { font-size: 0.95rem; font-weight: 700; margin: 0 0 10px; color: var(--color-text, #32363a); }
</style>
