<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, usePage, Link } from '@inertiajs/vue3';
import {
    Card, Button, Input, InputNumber, Select, Switch, Popconfirm, Alert, Tooltip, message,
} from 'ant-design-vue';
import { PlusOutlined, DeleteOutlined, SaveOutlined, UndoOutlined, ExperimentOutlined, TableOutlined, InfoCircleOutlined, SafetyCertificateOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import RatingStars from '@/Components/Diagnostics/RatingStars.vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex, setDiagnosticColors } from '@/utils/severity';

defineOptions({ layout: AppLayout });

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    colors: { type: Array, default: () => [] },
    conditionLabels: { type: Array, default: () => [] },
    labelLocales: { type: Array, default: () => [] },
});

const { t } = useI18n();
const { ratingLabel } = useConditions();
const page = usePage();

// Código canónico interno por rating (lo que se guarda en condition_label y se
// copia a las muestras). La PALABRA visible sale de la tarjeta de etiquetas; el
// usuario ya no escribe la condición — se deriva del rating. Una sola fuente.
const CANON = { 4: 'Muy Bueno', 3: 'Bueno', 2: 'Medio', 1: 'Malo', 0: 'Muy Malo' };
const condFor = (rating) => (rating === null || rating === undefined ? '—' : ratingLabel(rating));

// Copia editable local. La condición se deriva del rating (no se edita a mano).
const state = ref(props.blocks.map((b) => ({ ...b, bands: b.bands.map((x) => ({ ...x })) })));
const saving = ref(null);

// Pruebas (semáforo por medición) vs Índice de Salud (semáforo global combinado).
const testBlocks = computed(() => state.value.filter((b) => b.code !== 'hi'));
const hiBlocks = computed(() => state.value.filter((b) => b.code === 'hi'));

const colorOptions = props.colors.map((c) => ({ value: c, label: c }));
const ratingOptions = [4, 3, 2, 1, 0].map((r) => ({ value: r, label: String(r) }));

// "Desde" derivado: el corte de la banda anterior (−∞ la primera).
const fromOf = (bands, i) => (i === 0 ? '−∞' : (bands[i - 1].score_to ?? '∞'));
const toOf = (bands, i) => (i === bands.length - 1 ? '∞' : (bands[i].score_to ?? '∞'));

const addBand = (block) => {
    const bands = block.bands;
    const lastCut = bands.length >= 2 ? Number(bands[bands.length - 2].score_to ?? 0) : 0;
    bands.splice(bands.length - 1, 0, {
        condition_label: CANON[2], color: 'yellow', hi_rating: block.has_rating ? 2 : null,
        score_to: lastCut + 1,
    });
};
const removeBand = (block, i) => {
    if (block.bands.length <= 2) { message.warning(t('diagnostic_rules.min_bands')); return; }
    block.bands.splice(i, 1);
};

const save = (block) => {
    block.bands[block.bands.length - 1].score_to = null;
    saving.value = block.code;
    router.put(route('system_management.diagnostic_rules.update', block.code), {
        bands: block.bands.map((b) => ({
            // La condición se deriva del rating (código canónico interno); ya no
            // es texto libre. Las palabras visibles viven en la tarjeta de etiquetas.
            condition_label: CANON[b.hi_rating] ?? b.condition_label ?? CANON[2],
            color: b.color,
            hi_rating: block.has_rating ? b.hi_rating : null,
            score_to: b.score_to,
        })),
        ...(block.has_hi ? { hi_weight: block.hi_weight, hi_enabled: block.hi_enabled } : {}),
    }, {
        preserveScroll: true,
        onSuccess: () => { if (page.props.flash?.success) message.success(page.props.flash.success); },
        onError: (e) => { message.error(Object.values(e)[0] || t('diagnostic_rules.error')); },
        onFinish: () => { saving.value = null; },
    });
};

const restore = () => {
    router.post(route('system_management.diagnostic_rules.restore'), {}, {
        preserveScroll: true,
        onSuccess: () => { message.success(t('diagnostic_rules.restored')); router.reload({ only: ['blocks'] }); },
    });
};

// ── Etiquetas de calificación por idioma (indexadas por rating) ──
const labelRows = ref(props.conditionLabels.map((r) => ({ rating: r.rating, labels: { ...r.labels } })));
const savingLabels = ref(false);
const localeName = (loc) => loc.toUpperCase();

// Tras restaurar (reload de la prop), resincroniza la copia editable.
watch(() => props.conditionLabels, (rows) => {
    labelRows.value = rows.map((r) => ({ rating: r.rating, labels: { ...r.labels } }));
});

const saveLabels = () => {
    savingLabels.value = true;
    router.put(route('system_management.diagnostic_rules.labels_update'), {
        labels: labelRows.value.map((r) => ({ rating: r.rating, labels: r.labels })),
    }, {
        preserveScroll: true,
        onSuccess: () => { if (page.props.flash?.success) message.success(page.props.flash.success); },
        onError: (e) => { message.error(Object.values(e)[0] || t('diagnostic_rules.error')); },
        onFinish: () => { savingLabels.value = false; },
    });
};

const restoreLabels = () => {
    router.post(route('system_management.diagnostic_rules.labels_restore'), {}, {
        preserveScroll: true,
        onSuccess: () => {
            message.success(t('diagnostic_rules.labels_restored'));
            router.reload({ only: ['conditionLabels'] });
        },
    });
};

// ── Colores del diagnóstico (semáforo + gradiente de celda) ──
// El base sale de la prop COMPARTIDA diagnosticColors (resuelta por scope:
// super edita el global; admin su override). Editable con override por tenant.
const SEMA_TOKENS = ['green', 'lime', 'yellow', 'orange', 'red'];      // ratings 4..0
const STOP_TOKENS = ['good', 'warn', 'bad'];
const SEMA_RATING = { green: 4, lime: 3, yellow: 2, orange: 1, red: 0 };
const cloneColors = (c) => ({
    sema:  Object.fromEntries(SEMA_TOKENS.map((k) => [k, c?.sema?.[k] ?? '#000000'])),
    stops: Object.fromEntries(STOP_TOKENS.map((k) => [k, c?.stops?.[k] ?? '#000000'])),
});
const colorsForm = ref(cloneColors(page.props.diagnosticColors));
const savingColors = ref(false);
const gradientCss = computed(() => `linear-gradient(90deg, ${colorsForm.value.stops.good}, ${colorsForm.value.stops.warn}, ${colorsForm.value.stops.bad})`);

watch(() => page.props.diagnosticColors, (c) => { colorsForm.value = cloneColors(c); });

const saveColors = () => {
    savingColors.value = true;
    router.put(route('system_management.diagnostic_rules.colors_update'), colorsForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            if (page.props.flash?.success) message.success(page.props.flash.success);
            setDiagnosticColors(page.props.diagnosticColors);
        },
        onError: (e) => { message.error(Object.values(e)[0] || t('diagnostic_rules.error')); },
        onFinish: () => { savingColors.value = false; },
    });
};

const restoreColors = () => {
    router.post(route('system_management.diagnostic_rules.colors_restore'), {}, {
        preserveScroll: true,
        onSuccess: () => {
            message.success(t('diagnostic_rules.colors_restored'));
            router.reload();
        },
    });
};
</script>

<template>
    <Head :title="$t('diagnostic_rules.title')" />
    <div class="sap-index rules-page">
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon"><ExperimentOutlined /></div>
                <div class="page-header__heading">
                    <h1>{{ $t('diagnostic_rules.title') }}</h1>
                    <p>{{ $t('diagnostic_rules.subtitle') }}</p>
                </div>
            </div>
            <div class="mi-title__actions">
                <Popconfirm :title="$t('diagnostic_rules.restore_confirm')" @confirm="restore">
                    <Button danger><template #icon><UndoOutlined /></template>{{ $t('diagnostic_rules.restore') }}</Button>
                </Popconfirm>
            </div>
        </div>

        <Alert type="info" show-icon class="mb" :message="$t('diagnostic_rules.note')" />

        <!-- ── Etiquetas de calificación por idioma (indexadas por rating) ── -->
        <Card class="rule-card labels-card mb" :bodyStyle="{ padding: '18px 20px' }">
            <div class="rule-head">
                <h3>{{ $t('diagnostic_rules.labels_section') }}</h3>
                <Popconfirm :title="$t('diagnostic_rules.labels_restore_confirm')" @confirm="restoreLabels">
                    <Button size="small"><template #icon><UndoOutlined /></template>{{ $t('diagnostic_rules.labels_restore') }}</Button>
                </Popconfirm>
            </div>
            <p class="rules-sec__hint" style="margin-top:0">{{ $t('diagnostic_rules.labels_hint') }}</p>

            <div class="labels-grid">
                <div v-for="row in labelRows" :key="row.rating" class="label-cell">
                    <div class="label-cell__head">
                        <RatingStars :rating="row.rating" />
                        <span class="labels-rating__n">{{ row.rating }}/4</span>
                    </div>
                    <div v-for="loc in labelLocales" :key="loc" class="label-cell__field">
                        <span v-if="labelLocales.length > 1" class="label-cell__loc">{{ localeName(loc) }}</span>
                        <Input v-model:value="row.labels[loc]" size="small" :maxlength="60" />
                    </div>
                </div>
            </div>

            <div class="rule-acts">
                <Button type="primary" size="small" :loading="savingLabels" @click="saveLabels">
                    <template #icon><SaveOutlined /></template>{{ $t('diagnostic_rules.labels_save') }}
                </Button>
            </div>
        </Card>

        <!-- ── Colores del diagnóstico (semáforo de condición + gradiente de celda) ── -->
        <Card class="rule-card colors-card mb" :bodyStyle="{ padding: '18px 20px' }">
            <div class="rule-head">
                <h3>{{ $t('diagnostic_rules.colors_section') }}</h3>
                <Popconfirm :title="$t('diagnostic_rules.colors_restore_confirm')" @confirm="restoreColors">
                    <Button size="small"><template #icon><UndoOutlined /></template>{{ $t('diagnostic_rules.colors_restore') }}</Button>
                </Popconfirm>
            </div>
            <p class="rules-sec__hint" style="margin-top:0">{{ $t('diagnostic_rules.colors_hint') }}</p>

            <div class="colors-sub">{{ $t('diagnostic_rules.colors_sema') }}</div>
            <div class="colors-grid">
                <label v-for="tk in SEMA_TOKENS" :key="tk" class="color-cell">
                    <input type="color" v-model="colorsForm.sema[tk]" class="color-cell__sw" />
                    <span class="color-cell__lbl">{{ condFor(SEMA_RATING[tk]) }}</span>
                    <span class="color-cell__hex">{{ colorsForm.sema[tk] }}</span>
                </label>
            </div>

            <div class="colors-sub">{{ $t('diagnostic_rules.colors_stops') }}</div>
            <div class="colors-grid">
                <label v-for="tk in STOP_TOKENS" :key="tk" class="color-cell">
                    <input type="color" v-model="colorsForm.stops[tk]" class="color-cell__sw" />
                    <span class="color-cell__lbl">{{ $t('diagnostic_rules.colors_stop_' + tk) }}</span>
                    <span class="color-cell__hex">{{ colorsForm.stops[tk] }}</span>
                </label>
            </div>
            <div class="colors-preview" :style="{ background: gradientCss }"></div>

            <div class="rule-acts">
                <Button type="primary" size="small" :loading="savingColors" @click="saveColors">
                    <template #icon><SaveOutlined /></template>{{ $t('diagnostic_rules.colors_save') }}
                </Button>
            </div>
        </Card>

        <!-- ── Semáforos por prueba (2 columnas) ── -->
        <h2 class="rules-sec">{{ $t('diagnostic_rules.tests_section') }}</h2>
        <p class="rules-sec__hint">{{ $t('diagnostic_rules.tests_hint') }}</p>

        <div class="rules-grid">
            <Card v-for="block in testBlocks" :key="block.code" class="rule-card" :bodyStyle="{ padding: '18px 20px' }">
                <div class="rule-head">
                    <h3>{{ block.name }}</h3>
                    <div v-if="block.has_hi" class="rule-hi">
                        <Tooltip :title="$t('diagnostic_rules.hi_weight_help')">
                            <span class="rule-hi__lbl">{{ $t('diagnostic_rules.hi_weight') }} <InfoCircleOutlined /></span>
                        </Tooltip>
                        <InputNumber v-model:value="block.hi_weight" :min="0" :max="100" size="small" style="width:68px" />
                        <span class="rule-hi__sep"></span>
                        <Switch v-model:checked="block.hi_enabled" size="small" />
                        <span class="rule-hi__state" :class="{ on: block.hi_enabled }">
                            {{ block.hi_enabled ? $t('diagnostic_rules.counts') : $t('diagnostic_rules.not_counts') }}
                        </span>
                    </div>
                </div>

                <!-- Norma + condiciones que seleccionan estas reglas, y sus editores. -->
                <div class="rule-meta">
                    <span v-if="block.norm" class="rule-meta__norm"><SafetyCertificateOutlined /> {{ block.norm }}</span>
                    <span v-if="block.conditions.length" class="rule-meta__cond">
                        {{ $t('diagnostic_rules.applies_by') }}
                        <template v-for="(c, i) in block.conditions" :key="c"><b>{{ $t('diagnostic_rules.cond_' + c) }}</b><span v-if="i < block.conditions.length - 1"> · </span></template>
                    </span>
                    <Link v-for="lnk in block.links" :key="lnk.key" :href="route(lnk.route, lnk.param || undefined)" class="rule-meta__link">
                        <Button size="small"><template #icon><TableOutlined /></template>{{ $t('diagnostic_rules.link_' + lnk.key) }}</Button>
                    </Link>
                </div>

                <!-- Vista previa del semáforo: cómo queda la escala de un vistazo. -->
                <div class="rule-preview">
                    <div
                        v-for="(b, i) in block.bands" :key="'p' + i"
                        class="rule-preview__seg" :style="{ background: semaforoHex(b.color) }"
                    >
                        <span class="rule-preview__lbl">{{ condFor(b.hi_rating) }}</span>
                        <span class="rule-preview__rng">{{ fromOf(block.bands, i) }} – {{ toOf(block.bands, i) }}</span>
                    </div>
                </div>

                <div class="rule-table-wrap">
                <table class="rule-table">
                    <thead>
                        <tr>
                            <th>{{ $t('diagnostic_rules.color') }}</th>
                            <th>{{ $t('diagnostic_rules.condition') }}</th>
                            <th v-if="block.has_rating">
                                <Tooltip :title="$t('diagnostic_rules.rating_help')">{{ $t('diagnostic_rules.rating') }} <InfoCircleOutlined /></Tooltip>
                            </th>
                            <th>{{ $t('diagnostic_rules.from') }}</th>
                            <th>{{ $t('diagnostic_rules.to') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(b, i) in block.bands" :key="i">
                            <td :data-label="$t('diagnostic_rules.color')">
                                <Select v-model:value="b.color" :options="colorOptions" size="small" style="width:104px">
                                    <template #option="{ value }">
                                        <span class="dot" :style="{ background: semaforoHex(value) }"></span> {{ value }}
                                    </template>
                                    <template #suffixIcon><span class="dot" :style="{ background: semaforoHex(b.color) }"></span></template>
                                </Select>
                            </td>
                            <td :data-label="$t('diagnostic_rules.condition')">
                                <span class="cond-auto"><RatingStars :rating="b.hi_rating" :size="13" /> {{ condFor(b.hi_rating) }}</span>
                            </td>
                            <td v-if="block.has_rating" :data-label="$t('diagnostic_rules.rating')">
                                <Select v-model:value="b.hi_rating" :options="ratingOptions" size="small" style="width:60px" />
                            </td>
                            <td class="muted" :data-label="$t('diagnostic_rules.from')">{{ fromOf(block.bands, i) }}</td>
                            <td :data-label="$t('diagnostic_rules.to')">
                                <span v-if="i === block.bands.length - 1" class="muted">∞</span>
                                <InputNumber v-else v-model:value="b.score_to" size="small" style="width:90px" :step="0.1" />
                            </td>
                            <td class="rule-table__del">
                                <Button v-if="i !== block.bands.length - 1" type="text" size="small" danger @click="removeBand(block, i)">
                                    <template #icon><DeleteOutlined /></template>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>

                <div class="rule-acts">
                    <Button size="small" @click="addBand(block)"><template #icon><PlusOutlined /></template>{{ $t('diagnostic_rules.add_band') }}</Button>
                    <Button type="primary" size="small" :loading="saving === block.code" @click="save(block)">
                        <template #icon><SaveOutlined /></template>{{ $t('diagnostic_rules.save') }}
                    </Button>
                </div>
            </Card>
        </div>

        <!-- ── Índice de Salud (semáforo global combinado) ── -->
        <h2 class="rules-sec">{{ $t('diagnostic_rules.hi_section') }}</h2>
        <p class="rules-sec__hint">{{ $t('diagnostic_rules.hi_hint') }}</p>

        <Card v-for="block in hiBlocks" :key="block.code" class="rule-card rule-card--hi" :bodyStyle="{ padding: '18px 20px' }">
            <div class="rule-preview">
                <div
                    v-for="(b, i) in block.bands" :key="'h' + i"
                    class="rule-preview__seg" :style="{ background: semaforoHex(b.color) }"
                >
                    <span class="rule-preview__lbl">{{ condFor(b.hi_rating) }}</span>
                    <span class="rule-preview__rng">{{ fromOf(block.bands, i) }} – {{ toOf(block.bands, i) }}</span>
                </div>
            </div>

            <div class="rule-table-wrap">
            <table class="rule-table">
                <thead>
                    <tr>
                        <th>{{ $t('diagnostic_rules.color') }}</th>
                        <th>{{ $t('diagnostic_rules.condition') }}</th>
                        <th><Tooltip :title="$t('diagnostic_rules.rating_help')">{{ $t('diagnostic_rules.rating') }} <InfoCircleOutlined /></Tooltip></th>
                        <th>{{ $t('diagnostic_rules.from') }}</th>
                        <th>{{ $t('diagnostic_rules.to') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(b, i) in block.bands" :key="i">
                        <td :data-label="$t('diagnostic_rules.color')">
                            <Select v-model:value="b.color" :options="colorOptions" size="small" style="width:104px">
                                <template #option="{ value }"><span class="dot" :style="{ background: semaforoHex(value) }"></span> {{ value }}</template>
                                <template #suffixIcon><span class="dot" :style="{ background: semaforoHex(b.color) }"></span></template>
                            </Select>
                        </td>
                        <td :data-label="$t('diagnostic_rules.condition')"><span class="cond-auto"><RatingStars :rating="b.hi_rating" :size="13" /> {{ condFor(b.hi_rating) }}</span></td>
                        <td :data-label="$t('diagnostic_rules.rating')"><Select v-model:value="b.hi_rating" :options="ratingOptions" size="small" style="width:60px" /></td>
                        <td class="muted" :data-label="$t('diagnostic_rules.from')">{{ fromOf(block.bands, i) }}</td>
                        <td :data-label="$t('diagnostic_rules.to')">
                            <span v-if="i === block.bands.length - 1" class="muted">∞</span>
                            <InputNumber v-else v-model:value="b.score_to" size="small" style="width:90px" :step="0.1" />
                        </td>
                        <td class="rule-table__del">
                            <Button v-if="i !== block.bands.length - 1" type="text" size="small" danger @click="removeBand(block, i)">
                                <template #icon><DeleteOutlined /></template>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>

            <div class="rule-acts">
                <Button size="small" @click="addBand(block)"><template #icon><PlusOutlined /></template>{{ $t('diagnostic_rules.add_band') }}</Button>
                <Button type="primary" size="small" :loading="saving === block.code" @click="save(block)">
                    <template #icon><SaveOutlined /></template>{{ $t('diagnostic_rules.save') }}
                </Button>
            </div>
        </Card>
    </div>
</template>

<style scoped>
.mb { margin-bottom: 16px; }
.rules-sec { font-size: 1.05rem; font-weight: 700; margin: 22px 0 2px; color: var(--color-text, #1f2937); }
.rules-sec__hint { margin: 0 0 14px; font-size: 0.84rem; color: var(--color-text-muted, #6A6D70); }

/* 2 columnas full-width; 1 columna en pantallas chicas. */
.rules-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
@media (max-width: 1100px) { .rules-grid { grid-template-columns: 1fr; } }

.rule-card { border-radius: 12px; }
.rule-card--hi { border: 1px solid var(--brand, #0A6ED1)33; }
.rule-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:8px; flex-wrap:wrap; }
/* Barra de norma + condiciones + editores de cada prueba (agrupa el contexto). */
.rule-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;
    padding:8px 10px; border-radius:8px; background:var(--color-surface-alt,#f5f6f7); font-size:0.8rem; }
.rule-meta__norm { display:inline-flex; align-items:center; gap:6px; font-weight:700; color:var(--brand,#0A6ED1); }
.rule-meta__cond { color:var(--color-text-muted,#6A6D70); }
.rule-meta__cond b { color:var(--color-text,#32363A); font-weight:600; }
.rule-meta__link { margin-left:auto; }
.rule-meta__link + .rule-meta__link { margin-left:6px; }
.rule-head h3 { margin:0; font-size:1.02rem; }
.rule-hi { display:flex; align-items:center; gap:8px; font-size:0.8rem; color:var(--color-text-muted,#6A6D70); }
.rule-hi__lbl { white-space: nowrap; }
.rule-hi__sep { width: 1px; height: 18px; background: var(--color-border, #e5e7eb); }
.rule-hi__state { font-weight: 600; color: var(--color-text-muted, #9aa0a6); }
.rule-hi__state.on { color: #1D7044; }

/* Barra-preview del semáforo. */
.rule-preview { display: flex; gap: 3px; border-radius: 8px; overflow: hidden; margin-bottom: 14px; }
.rule-preview__seg { flex: 1; min-width: 0; padding: 8px 6px; color: #fff; text-align: center; line-height: 1.2; }
.rule-preview__lbl { display: block; font-size: 0.74rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rule-preview__rng { display: block; font-size: 0.66rem; opacity: 0.92; font-variant-numeric: tabular-nums; margin-top: 2px; }

/* La tabla de bandas tiene inputs de ancho fijo (color/rating/hasta) → en
   pantalla chica se desborda. El wrap le da scroll horizontal en vez de romper
   el card. min-width mantiene las columnas usables al hacer scroll. */
.rule-table-wrap { overflow-x: auto; max-width: 100%; }
.rule-table { width:100%; border-collapse:collapse; min-width: 440px; }
.rule-table th { text-align:left; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.4px; color:var(--color-text-muted,#6A6D70); padding:6px 8px; border-bottom:1px solid var(--color-border,#eef0f2); white-space: nowrap; }
.rule-table td { padding:5px 8px; border-bottom:1px solid var(--color-border,#f2f4f6); font-size:0.85rem; }
.rule-table .muted { color:var(--color-text-muted,#9aa0a6); font-variant-numeric:tabular-nums; }
.cond-auto { display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; color:var(--color-text,#1f2937); white-space:nowrap; }
.rule-acts { display:flex; gap:8px; margin-top:14px; }
.dot { width:10px; height:10px; border-radius:50%; display:inline-block; }

/* Pantalla chica: la tabla de bandas se apila como tarjetas (sin scroll). Cada
   fila = una banda; cada celda muestra su etiqueta (data-label) a la izquierda y
   el control a la derecha. */
@media (max-width: 640px) {
    .rule-table-wrap { overflow-x: visible; }
    .rule-table { min-width: 0; }
    .rule-table thead { display: none; }
    .rule-table tbody, .rule-table tr, .rule-table td { display: block; width: 100%; }
    .rule-table tr {
        border: 1px solid var(--color-border, #e5e7eb);
        border-radius: 8px;
        padding: 6px 12px;
        margin-bottom: 10px;
    }
    .rule-table td {
        border: 0;
        padding: 6px 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .rule-table td + td { border-top: 1px solid var(--color-border, #f2f4f6); }
    .rule-table td::before {
        content: attr(data-label);
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: var(--color-text-muted, #6A6D70);
        font-weight: 600;
        flex-shrink: 0;
    }
    .cond-auto { white-space: normal; }
    .rule-table__del { justify-content: flex-end; }
    .rule-table__del::before { content: none; }
    .rule-table__del:empty { display: none; }
}

/* Etiquetas de calificación. */
.labels-card { border: 1px solid var(--brand, #0A6ED1)22; }
.labels-rating__n { font-size: 0.74rem; color: var(--color-text-muted, #9aa0a6); font-variant-numeric: tabular-nums; }

/* Etiquetas en horizontal: 5 tarjetas (rating 4..0) en fila; responsive. */
.labels-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
.label-cell { border: 1px solid var(--color-border, #eef0f2); border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
.label-cell__head { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.label-cell__field { display: flex; flex-direction: column; gap: 3px; }
.label-cell__loc { font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #9aa0a6); }
@media (max-width: 1100px) { .labels-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 760px)  { .labels-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px)  { .labels-grid { grid-template-columns: 1fr; } }

/* Colores del diagnóstico */
.colors-sub { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); margin: 6px 0 8px; }
.colors-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 14px; }
.color-cell { border: 1px solid var(--color-border, #eef0f2); border-radius: 8px; padding: 10px; display: flex; flex-direction: column; align-items: center; gap: 6px; cursor: pointer; }
.color-cell__sw { width: 100%; height: 34px; padding: 0; border: none; background: none; cursor: pointer; }
.color-cell__lbl { font-size: 0.78rem; color: var(--color-text, #32363a); text-align: center; }
.color-cell__hex { font-size: 0.7rem; font-family: monospace; color: var(--color-text-muted, #9aa0a6); text-transform: uppercase; }
.colors-preview { height: 16px; border-radius: 8px; border: 1px solid var(--color-border, #eef0f2); margin-bottom: 4px; }
@media (max-width: 760px) { .colors-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 480px) { .colors-grid { grid-template-columns: repeat(2, 1fr); } }
</style>
