<script setup>
import { reactive, computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Card, Button, Popconfirm, InputNumber, Alert, message } from 'ant-design-vue';
import { SaveOutlined, UndoOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { useI18n } from '@/Plugins/i18n';
import { fiquiFullName } from '@/utils/fiquiHeader';

/**
 * DataEdit — editor estructurado de un dataset de norma (fiquis_rules).
 * Solo edita los VALORES (umbrales); la estructura (claves,
 * normas, semáforo) no se toca, así no se puede romper el esquema. Guarda a BD.
 */
const props = defineProps({
    dataKey: { type: String, required: true },
    data:    { type: Object, required: true },
});

const { t } = useI18n();
const form = reactive(JSON.parse(JSON.stringify(props.data)));
const saving = ref(false);

const isFiquis = computed(() => props.dataKey === 'fiquis_rules');
const title = computed(() => (isFiquis.value ? t('fiquis.tab') : 'IEEE C57.104'));

// ── Fiquis ──────────────────────────────────────────────────────────────────
const oils = computed(() => Object.keys(form.tables ?? {}));
const classLabel = (c) => (c === 'all' ? t('diagnostic_rules.data_class_all') : t('fiquis.diag.class_' + c));
const oilLabel = (o) => o.replace('vegetal_', '').replace(/^\w/, (m) => m.toUpperCase());
// Con la condición de ensayo pegada: en esta tabla conviven las dos rigideces y
// los dos factores de potencia, y con el nombre pelado se repiten y no se sabe
// cuál es cuál.
const paramName = (p) => fiquiFullName(t, p);
const paramMeta = (p) => form.params?.[p] ?? {};
const paramsOf = (oil, cls) => Object.keys(form.tables[oil][cls] ?? {});

/**
 * Todas las celdas se editan con TRES campos.
 *
 * Los métodos alternos (rigidez D877, factor de potencia a 100 °C) llegan con un
 * solo valor porque la norma publica un límite de aceptación, no una gradación.
 * Igual puntúan cuando el laboratorio no corrió el principal: ahí lo sustituyen,
 * con las mismas tres bandas del color de la celda. Cargando aquí los tres
 * umbrales pasan a graduar 1..4 como el resto de los parámetros.
 *
 * Se rellena a 3 al montar para que v-model tenga dónde escribir; el backend
 * guarda null lo que quede vacío y el motor vuelve al límite único.
 */
const esAlterno = (p) => paramMeta(p).mode === 'limit';
for (const oil of Object.keys(form.tables ?? {})) {
    for (const cls of Object.keys(form.tables[oil])) {
        for (const p of Object.keys(form.tables[oil][cls])) {
            const vals = form.tables[oil][cls][p];
            while (vals.length < 3) vals.push(null);
        }
    }
}
// Una gradación es completa o no es: t3 sin t2 haría que el motor leyera t1 como
// el límite de aceptación, que en "mayor = mejor" es el extremo opuesto.
const gradacionIncompleta = (oil, cls, p) => {
    const [, t2, t3] = form.tables[oil][cls][p];
    const lleno = (v) => v !== null && v !== undefined && v !== '';
    return lleno(t2) !== lleno(t3);
};
const hayIncompletas = computed(() => oils.value.some((oil) =>
    Object.keys(form.tables[oil]).some((cls) =>
        paramsOf(oil, cls).some((p) => gradacionIncompleta(oil, cls, p)))));

// ── IEEE ────────────────────────────────────────────────────────────────────
const GAS = { h2: 'H₂', ch4: 'CH₄', c2h2: 'C₂H₂', c2h4: 'C₂H₄', c2h6: 'C₂H₆', co: 'CO', co2: 'CO₂' };
const ieeeGases = computed(() => Object.keys(form.gases ?? {}));

const save = () => {
    if (isFiquis.value && hayIncompletas.value) {
        message.error(t('diagnostic_rules.data_partial_warn'));
        return;
    }
    saving.value = true;
    router.put(route('system_management.diagnostic_rules.data_update', props.dataKey), { data: form }, {
        preserveScroll: true,
        onSuccess: () => message.success(t('diagnostic_rules.data_saved')),
        onError: () => message.error(t('global.error')),
        onFinish: () => { saving.value = false; },
    });
};
const restore = () => {
    router.post(route('system_management.diagnostic_rules.data_restore', props.dataKey), {}, {
        onSuccess: () => message.success(t('diagnostic_rules.data_restored')),
    });
};
</script>

<template>
    <Head :title="title" />
    <AppLayout>
        <SectionHeader :title="`${$t('diagnostic_rules.data_edit_title')}: ${title}`" :subtitle="$t('diagnostic_rules.data_edit_hint')">
            <template #actions>
                <Button @click="router.get(route('system_management.diagnostic_rules.index'))">
                    <template #icon><ArrowLeftOutlined /></template>{{ $t('global.back') }}
                </Button>
                <Popconfirm :title="$t('diagnostic_rules.data_restore_confirm')" @confirm="restore">
                    <Button><template #icon><UndoOutlined /></template>{{ $t('diagnostic_rules.data_restore') }}</Button>
                </Popconfirm>
                <Button type="primary" :loading="saving" @click="save">
                    <template #icon><SaveOutlined /></template>{{ $t('diagnostic_rules.save') }}
                </Button>
            </template>
        </SectionHeader>

        <Alert type="info" show-icon class="mb" :message="$t('diagnostic_rules.data_edit_note')" />

        <!-- ════════ FISICOQUÍMICO ════════ -->
        <template v-if="isFiquis">
            <Alert type="info" show-icon class="mb" :message="$t('diagnostic_rules.data_alt_note')" />
            <Alert v-if="hayIncompletas" type="warning" show-icon class="mb" :message="$t('diagnostic_rules.data_partial_warn')" />
            <Card v-for="oil in oils" :key="oil" class="ds-card" :bodyStyle="{ padding: '16px 18px' }">
                <h3 class="ds-oil">{{ oilLabel(oil) }}</h3>
                <div v-for="cls in Object.keys(form.tables[oil])" :key="cls" class="ds-class">
                    <div class="ds-class__lbl">{{ classLabel(cls) }}</div>
                    <table class="ds-table">
                        <thead><tr>
                            <th class="left">{{ $t('diagnostic_rules.data_param') }}</th>
                            <th>{{ $t('diagnostic_rules.data_t1') }}</th>
                            <th>{{ $t('diagnostic_rules.data_t2') }}</th>
                            <th>{{ $t('diagnostic_rules.data_t3') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="p in paramsOf(oil, cls)" :key="p" :class="{ 'ds-row-bad': gradacionIncompleta(oil, cls, p) }">
                                <td class="left">
                                    <strong>{{ paramName(p) }}</strong>
                                    <span class="ds-meta">{{ paramMeta(p).astm }}{{ paramMeta(p).unit ? ' · ' + paramMeta(p).unit : '' }}<template v-if="esAlterno(p)"> · {{ $t('diagnostic_rules.data_limit_mode') }}</template></span>
                                </td>
                                <td v-for="i in 3" :key="i">
                                    <InputNumber
                                        v-model:value="form.tables[oil][cls][p][i - 1]"
                                        size="small"
                                        :step="0.01"
                                        :placeholder="esAlterno(p) && i > 1 ? $t('diagnostic_rules.data_derived') : ''"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </template>

        <!-- ════════ IEEE C57.104 ════════ -->
        <Card v-else class="ds-card" :bodyStyle="{ padding: '16px 18px' }">
            <table class="ds-table">
                <thead><tr>
                    <th class="left">{{ $t('diagnostic_rules.data_gas') }}</th>
                    <th>{{ $t('diagnostic_rules.data_cond', { n: 1 }) }}</th>
                    <th>{{ $t('diagnostic_rules.data_cond', { n: 2 }) }}</th>
                    <th>{{ $t('diagnostic_rules.data_cond', { n: 3 }) }}</th>
                </tr></thead>
                <tbody>
                    <tr v-for="g in ieeeGases" :key="g">
                        <td class="left"><strong>{{ GAS[g] || g }}</strong> <span class="ds-meta">ppm</span></td>
                        <td v-for="(val, i) in form.gases[g]" :key="i">
                            <InputNumber v-model:value="form.gases[g][i]" size="small" :step="1" />
                        </td>
                    </tr>
                    <tr v-if="form.tdcg" class="ds-tdcg">
                        <td class="left"><strong>TDCG</strong> <span class="ds-meta">ppm</span></td>
                        <td v-for="(val, i) in form.tdcg" :key="i">
                            <InputNumber v-model:value="form.tdcg[i]" size="small" :step="1" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </AppLayout>
</template>

<style scoped>
.mb { margin-bottom: 16px; }
.ds-card { margin-bottom: 16px; }
.ds-oil { font-size: 1.05rem; margin: 0 0 10px; color: var(--color-text, #32363a); }
.ds-class { margin-bottom: 14px; }
.ds-class__lbl { font-size: 0.82rem; font-weight: 600; color: #0A6ED1; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px; }
.ds-table { width: 100%; border-collapse: collapse; }
.ds-table th { font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); font-weight: 600; text-align: center; padding: 5px 8px; border-bottom: 1px solid var(--color-border, #eef0f2); }
.ds-table td { padding: 5px 8px; text-align: center; border-bottom: 1px solid var(--color-border, #f3f4f6); }
.ds-table .left { text-align: left; }
.ds-meta { display: block; font-size: 0.72rem; color: var(--color-text-muted, #9aa0a6); }
.ds-na, .ds-single { color: var(--color-text-muted, #c2c6cb); font-size: 0.78rem; }
/* Gradación a medias (t3 sin t2, o al revés): no se puede guardar así. */
.ds-row-bad td { background: color-mix(in srgb, var(--color-warning, #E9A23B) 12%, transparent); }
.ds-tdcg td { border-top: 2px solid var(--color-border, #eef0f2); }
</style>
