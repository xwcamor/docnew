<script setup>
/**
 * IeeeDgaStatus — fila "IEEE C57.104 (Estados)" del resumen de cromatografía.
 *
 * Muestra los 4 badges (Tablas 1-4): verde = dentro de norma, rojo = se pasa,
 * gris = no evaluada. El diagnóstico (DGA Estado 1/2/3) abajo. Al hacer click en
 * un badge se abre un cuadro con la comparación gas por gas (valor vs límite,
 * coloreado), la columna que aplicó (O2/N2 + edad/período) y la FUENTE de los
 * datos. Todo viene de `dga` (payload cromasDgaStatus).
 *
 * Tabla 4 (tasa de generación): el motor elige automáticamente las últimas 3-6
 * muestras dentro de 4-24 meses. Desde el cuadro de la Tabla 4 se puede pasar a
 * SELECCIÓN MANUAL (como el sistema viejo): tildar 3-6 muestras y guardarlas; el
 * motor recalcula la tasa con esas. "Volver a automático" limpia la selección.
 */
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Drawer, Button, Tag, Tooltip, Checkbox } from 'ant-design-vue';
import { CalculatorOutlined, EditOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    dga: { type: Object, default: null },
    transformerSlug: { type: String, default: null },
});
const { t } = useI18n();

const has = computed(() => !!props.dga);
const status = computed(() => props.dga?.status ?? null);

const GAS = { h2: 'H₂', ch4: 'CH₄', c2h6: 'C₂H₆', c2h4: 'C₂H₄', c2h2: 'C₂H₂', co: 'CO', co2: 'CO₂' };

// Estado de cada tabla → color del badge.
//  ok=true → verde · ok=false / exceeded → rojo · null → gris (no evaluada)
function badgeState(table) {
    const d = props.dga;
    if (!d) return 'na';
    if (table === 1) return d.table1_ok === true ? 'ok' : d.table1_ok === false ? 'bad' : 'na';
    if (table === 2) return d.table2_exceeded === true ? 'bad' : 'ok';
    if (table === 3) return d.table3_ok === true ? 'ok' : d.table3_ok === false ? 'bad' : 'na';
    if (table === 4) return d.table4_ok === true ? 'ok' : d.table4_ok === false ? 'bad' : 'na';
    return 'na';
}
const badgeColors = { ok: '#1d7a44', bad: '#C8281D', na: '#9aa0a6' };

const statusColor = computed(() => (status.value === 1 ? '#1d7a44' : status.value === 2 ? '#d98e04' : '#C8281D'));

// ── Cuadro de detalle por tabla ──────────────────────────────────────────
const open = ref(false);
const tableKey = ref(1);
function openTable(n) { tableKey.value = n; open.value = true; }

// Filas del cuadro: gas, valor/delta/tasa, límite, ¿se pasa? — según la tabla.
const rows = computed(() => {
    const d = props.dga;
    if (!d) return [];
    if (tableKey.value === 4) {
        const pg = d.rate?.per_gas ?? {};
        return Object.entries(pg).map(([g, x]) => ({
            gas: GAS[g] ?? g, value: x.rate, valueDay: x.rate_day, limit: x.limit, exceeded: x.exceeded,
            unit: t('transformers.ieee_dga.per_year'),
        }));
    }
    const key = tableKey.value === 1 ? 'table1' : tableKey.value === 2 ? 'table2' : 'table3';
    const det = d.detail?.[key] ?? {};
    return Object.entries(det).map(([g, x]) => ({
        gas: GAS[g] ?? g,
        value: tableKey.value === 3 ? x.delta : x.value,
        limit: x.limit, exceeded: x.exceeded,
        unit: tableKey.value === 3 ? 'Δ ppm' : 'ppm',
    }));
});

// Contexto de la tabla como filas etiqueta/valor (apiladas, una por línea).
const ctxRows = computed(() => {
    const d = props.dga;
    if (!d) return [];
    const col = d.column === 'gt02' ? t('transformers.ieee_dga.col_gt02') : t('transformers.ieee_dga.col_le02');
    const rows = [{ label: t('transformers.ieee_dga.column_label'), value: col }];
    if (tableKey.value === 4) {
        if (!d.table4_applicable) return [{ label: '', value: t('transformers.ieee_dga.period_na') }];
        rows.push({ label: t('transformers.ieee_dga.table'), value: '4' });
        rows.push({ label: t('transformers.ieee_dga.period'), value: `${d.rate?.period_months} ${t('transformers.ieee_dga.months')}` });
        rows.push({ label: t('transformers.ieee_dga.samples_label'), value: String(d.rate?.samples) });
    } else if (tableKey.value === 3) {
        rows.push({ label: 'Δ', value: t('transformers.ieee_dga.delta_ctx') });
    } else {
        rows.push({ label: t('transformers.ieee_dga.age'), value: d.age_years != null ? `${d.age_years} ${t('transformers.ieee_dga.years')}` : '—' });
    }
    return rows;
});

// Muestras (fechas) que el motor usó para la tasa de la Tabla 4.
const usedSamples = computed(() => {
    const d = props.dga;
    const ids = d?.rate?.sample_ids ?? [];
    const byId = Object.fromEntries((d?.samples ?? []).map((s) => [s.id, s.date]));
    return ids.map((id) => byId[id]).filter(Boolean);
});

// ── Selección manual de Tabla 4 ──────────────────────────────────────────
const mode = computed(() => props.dga?.mode ?? 'auto');
const sampleList = computed(() => props.dga?.samples ?? []);
const editing = ref(false);
const selected = ref([...(props.dga?.rate_sample_ids ?? [])]);
const saving = ref(false);

const selValid = computed(() => selected.value.length >= 3 && selected.value.length <= 6);

function toggle(id) {
    const i = selected.value.indexOf(id);
    if (i >= 0) selected.value.splice(i, 1);
    else if (selected.value.length < 6) selected.value.push(id);
}

function save() {
    if (!selValid.value || !props.transformerSlug) return;
    saving.value = true;
    router.post(
        route('business_management.transformers.dga_rate_selection.save', props.transformerSlug),
        { sample_ids: selected.value },
        {
            preserveScroll: true,
            onFinish: () => { saving.value = false; editing.value = false; },
        },
    );
}

function backToAuto() {
    if (!props.transformerSlug) return;
    saving.value = true;
    router.delete(
        route('business_management.transformers.dga_rate_selection.clear', props.transformerSlug),
        {
            preserveScroll: true,
            onFinish: () => { saving.value = false; editing.value = false; },
        },
    );
}
</script>

<template>
    <div v-if="has" class="ieee-dga">
        <!-- Fila Estados: label + 4 badges (tablas) + botón Tabla 4 -->
        <div class="ieee-dga__row">
            <div class="ieee-dga__label">{{ $t('transformers.ieee_dga.estados') }}</div>
            <div class="ieee-dga__badges">
                <Tooltip v-for="n in [1,2,3]" :key="n" :title="`${$t('transformers.ieee_dga.table')} ${n}`">
                    <button class="ieee-dga__badge" :style="{ background: badgeColors[badgeState(n)] }" @click="openTable(n)">{{ n }}</button>
                </Tooltip>
                <Tooltip :title="`${$t('transformers.ieee_dga.table')} 4`">
                    <button class="ieee-dga__badge" :style="{ background: badgeColors[badgeState(4)] }" @click="openTable(4)">4</button>
                </Tooltip>
                <Button size="small" class="ieee-dga__t4btn" @click="openTable(4)">
                    <CalculatorOutlined /> {{ $t('transformers.ieee_dga.tabla4_btn') }}
                </Button>
                <Tag v-if="mode === 'manual'" color="blue" :bordered="false" class="ieee-dga__modetag">
                    {{ $t('transformers.ieee_dga.mode_manual') }}
                </Tag>
            </div>
        </div>

        <!-- Fila Diagnóstico -->
        <div class="ieee-dga__row">
            <div class="ieee-dga__label">{{ $t('transformers.ieee_dga.diagnostico') }}</div>
            <div class="ieee-dga__diag">
                <Tag :color="statusColor === '#1d7a44' ? 'green' : statusColor === '#d98e04' ? 'orange' : 'red'" :bordered="false">
                    {{ dga.label }}
                </Tag>
                <span class="ieee-dga__diagtext">{{ dga.text }}</span>
            </div>
        </div>

        <!-- Cuadro de detalle por tabla (fuente + comparación coloreada) -->
        <Drawer v-model:open="open" :title="`IEEE C57.104 · ${$t('transformers.ieee_dga.table')} ${tableKey}`" placement="right" :width="500">
            <dl class="ieee-dga__ctx">
                <div v-for="(c, i) in ctxRows" :key="i" class="ieee-dga__ctxrow">
                    <dt v-if="c.label">{{ c.label }}</dt>
                    <dd>{{ c.value }}</dd>
                </div>
            </dl>

            <!-- Muestras que se usaron para la tasa (solo Tabla 4) -->
            <div v-if="tableKey === 4 && usedSamples.length" class="ieee-dga__used">
                <p class="ieee-dga__usedhead">{{ $t('transformers.ieee_dga.used_samples') }} ({{ usedSamples.length }}):</p>
                <ul class="ieee-dga__usedlist">
                    <li v-for="(s, i) in usedSamples" :key="i">{{ s }}</li>
                </ul>
            </div>

            <table class="ieee-dga__detail">
                <thead>
                    <tr>
                        <th>Gas</th>
                        <th>{{ tableKey === 4 ? $t('transformers.ieee_dga.per_year') : tableKey === 3 ? 'Δ' : 'Valor' }}</th>
                        <th v-if="tableKey === 4">{{ $t('transformers.ieee_dga.per_day') }}</th>
                        <th>Límite</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.gas" :class="{ 'is-bad': r.exceeded }">
                        <td>{{ r.gas }}</td>
                        <td>{{ r.value ?? '—' }} <i v-if="tableKey !== 4">{{ r.unit }}</i></td>
                        <td v-if="tableKey === 4" class="ieee-dga__daycol">{{ r.valueDay ?? '—' }}</td>
                        <td>{{ r.limit ?? '—' }}</td>
                        <td>
                            <span v-if="r.exceeded" class="ieee-dga__x">se pasa</span>
                            <span v-else class="ieee-dga__ok">ok</span>
                        </td>
                    </tr>
                    <tr v-if="!rows.length"><td :colspan="tableKey === 4 ? 5 : 4" class="ieee-dga__na">{{ $t('transformers.ieee_dga.period_na') }}</td></tr>
                </tbody>
            </table>

            <!-- Selección manual de muestras para la Tabla 4 -->
            <div v-if="tableKey === 4" class="ieee-dga__manual">
                <div class="ieee-dga__manualhead">
                    <span class="ieee-dga__modelbl">
                        {{ mode === 'manual'
                            ? $t('transformers.ieee_dga.manual_active')
                            : $t('transformers.ieee_dga.used_auto') }}
                    </span>
                    <Button v-if="!editing" size="small" type="link" @click="editing = true">
                        <EditOutlined /> {{ $t('transformers.ieee_dga.edit_selection') }}
                    </Button>
                </div>

                <div v-if="editing" class="ieee-dga__picker">
                    <p class="ieee-dga__hint">{{ $t('transformers.ieee_dga.select_hint') }}</p>
                    <table class="ieee-dga__samples">
                        <thead>
                            <tr><th></th><th>Fecha</th><th>H₂</th><th>CH₄</th><th>C₂H₄</th><th>C₂H₂</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in sampleList" :key="s.id" :class="{ 'is-sel': selected.includes(s.id) }" @click="toggle(s.id)">
                                <td><Checkbox :checked="selected.includes(s.id)" /></td>
                                <td>{{ s.date ?? '—' }}</td>
                                <td>{{ s.h2 ?? '—' }}</td>
                                <td>{{ s.ch4 ?? '—' }}</td>
                                <td>{{ s.c2h4 ?? '—' }}</td>
                                <td>{{ s.c2h2 ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="!selValid" class="ieee-dga__warn">{{ $t('transformers.ieee_dga.min_samples') }}</p>
                    <div class="ieee-dga__actions">
                        <Button type="primary" size="small" :disabled="!selValid" :loading="saving" @click="save">
                            {{ $t('transformers.ieee_dga.save_use') }}
                        </Button>
                        <Button v-if="mode === 'manual'" size="small" danger :loading="saving" @click="backToAuto">
                            {{ $t('transformers.ieee_dga.back_to_auto') }}
                        </Button>
                        <Button size="small" type="text" @click="editing = false">{{ $t('global.cancel') || 'Cancelar' }}</Button>
                    </div>
                </div>
            </div>

            <p class="ieee-dga__source"><strong>{{ $t('global.source') || 'Fuente' }}:</strong> {{ dga.source }}</p>
        </Drawer>
    </div>
</template>

<style scoped>
.ieee-dga { display: flex; flex-direction: column; gap: 8px; }
.ieee-dga__row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.ieee-dga__label { font-weight: 600; color: var(--color-text, #1f2937); min-width: 200px; }
.ieee-dga__badges { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ieee-dga__badge {
    width: 26px; height: 26px; border-radius: 6px; border: 0; color: #fff;
    font-weight: 700; font-size: 0.82rem; cursor: pointer; line-height: 1;
}
.ieee-dga__t4btn { margin-left: 4px; }
.ieee-dga__modetag { margin-left: 2px; }
.ieee-dga__diag { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ieee-dga__diagtext { color: var(--color-text-muted, #6A6D70); font-size: 0.9rem; }
.ieee-dga__ctx { margin: 0 0 12px; font-size: 0.85rem; }
.ieee-dga__ctxrow { display: flex; gap: 8px; padding: 3px 0; }
.ieee-dga__ctxrow dt { min-width: 84px; color: var(--color-text-muted, #6A6D70); font-weight: 600; margin: 0; }
.ieee-dga__ctxrow dd { margin: 0; color: var(--color-text, #1f2937); }
.ieee-dga__used { margin: 0 0 14px; padding: 10px 12px; background: var(--color-surface-alt, #f6f7f9); border-radius: 8px; }
.ieee-dga__usedhead { margin: 0 0 4px; font-size: 0.82rem; font-weight: 600; color: var(--color-text, #1f2937); }
.ieee-dga__usedlist { margin: 0; padding-left: 16px; }
.ieee-dga__usedlist li { font-size: 0.82rem; color: var(--color-text, #1f2937); line-height: 1.6; }
.ieee-dga__daycol { color: var(--color-text-muted, #6A6D70); }
.ieee-dga__detail { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.ieee-dga__detail th { text-align: left; color: var(--color-text-muted, #6A6D70); font-weight: 600; padding: 6px 8px; border-bottom: 1px solid var(--color-border, #e5e7eb); }
.ieee-dga__detail td { padding: 7px 8px; border-bottom: 1px solid var(--color-border, #eef0f2); }
.ieee-dga__detail td i { font-style: normal; color: var(--color-text-muted, #9aa0a6); font-size: 0.78rem; }
.ieee-dga__detail tr.is-bad td { background: #C8281D0d; }
.ieee-dga__x { color: #C8281D; font-weight: 700; font-size: 0.8rem; }
.ieee-dga__ok { color: #1d7a44; font-size: 0.8rem; }
.ieee-dga__na { text-align: center; color: var(--color-text-muted, #9aa0a6); padding: 14px; }

/* Selección manual */
.ieee-dga__manual { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--color-border, #eef0f2); }
.ieee-dga__manualhead { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.ieee-dga__modelbl { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); }
.ieee-dga__picker { margin-top: 10px; }
.ieee-dga__hint { font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 8px; }
.ieee-dga__samples { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.ieee-dga__samples th { text-align: left; color: var(--color-text-muted, #6A6D70); font-weight: 600; padding: 5px 6px; border-bottom: 1px solid var(--color-border, #e5e7eb); }
.ieee-dga__samples td { padding: 6px; border-bottom: 1px solid var(--color-border, #eef0f2); cursor: pointer; }
.ieee-dga__samples tr.is-sel td { background: #0A6ED10f; }
.ieee-dga__warn { color: #C8281D; font-size: 0.8rem; margin: 8px 0 0; }
.ieee-dga__actions { display: flex; align-items: center; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.ieee-dga__source { margin-top: 16px; font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); line-height: 1.5; }
</style>
