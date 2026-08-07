<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Modal, Button, Radio, RadioGroup, Checkbox, CheckboxGroup,
    Input, Tooltip, Tag, Space, Alert,
} from 'ant-design-vue';
import {
    FileExcelOutlined, FilePdfOutlined, FileWordOutlined, FileTextOutlined,
    DownloadOutlined, CheckCircleFilled, CloseCircleFilled, ThunderboltOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';
import { useAuth } from '@/Composables/useAuth';

const { t } = useI18n();
const { canUse: canUsePlanFeature } = usePlanFeatures();
const { isSuper } = useAuth();

/**
 * ExportDialog — diálogo SAP-style para exportar a Excel/PDF/Word con opciones.
 *
 * Uso:
 *   <ExportDialog
 *     v-model:open="exportOpen"
 *     :columns="exportableColumns"
 *     :selected-ids="selectedRowKeys"
 *     :has-filters="hasActiveFilters"
 *     :filters-summary="filtersSummary"
 *     :default-title="'Regiones'"
 *     :endpoints="{ excel: route('...export_excel'), pdf: route('...export_pdf'), word: route('...export_word') }"
 *   />
 *
 * El dialog construye internamente el payload (format, scope, columns, options)
 * y dispara la request POST contra el endpoint elegido. El backend redirige
 * al panel de descargas cuando el job se encola.
 */

const props = defineProps({
    open:           { type: Boolean, default: false },
    columns:        { type: Array,   required: true },   // [{ key, label, default? }]
    selectedIds:    { type: Array,   default: () => [] },
    hasFilters:     { type: Boolean, default: false },
    filtersSummary: { type: String,  default: '' },
    // Filtros actuales aplicados — se incluyen en el payload cuando scope='filtered'.
    // El dialog es agnóstico al shape: el backend recibe lo mismo que mandaría
    // la página al recargarse (created_from, name[], etc.).
    currentFilters: { type: Object,  default: () => ({}) },
    defaultTitle:   { type: String,  default: '' },
    endpoints:      { type: Object,  required: true },   // { excel, pdf, word, csv }
    // Límites por formato: { csv: 0, excel: 25000, pdf: 5000, word: 10000 }.
    // 0 = sin límite (CSV streaming). Si el count actual supera el límite del
    // formato, ese formato se deshabilita con tooltip explicativo.
    limits:         { type: Object,  default: () => ({}) },
    // Conteo de filas según scope actual. `totalRows` = con filtros aplicados,
    // `totalUnfiltered` = total sin filtros (para scope='all').
    totalRows:        { type: Number, default: 0 },
    totalUnfiltered:  { type: Number, default: 0 },
});

const emit = defineEmits(['update:open']);

// id y slug son identificadores internos: SOLO el super los ve como columna
// exportable (default desmarcado). El resto ni se entera de su existencia. El
// backend lo gatea igual (allowlist por rol); esto es para mostrar/ocultar el
// checkbox. Es central → cubre TODOS los módulos sin tocar cada exports.js.
const allColumns = computed(() => (
    isSuper.value
        ? [{ key: 'id', label: 'ID', default: false }, { key: 'slug', label: 'Slug', default: false }, ...props.columns]
        : props.columns
));

// ─── Estado del form ───────────────────────────────────────────────────────
const format       = ref('excel');           // 'excel' | 'pdf' | 'word'
const scope        = ref('filtered');        // 'filtered' | 'selected' | 'all'
const selectedCols = ref([]);
const title        = ref('');
// PDF
const orientation  = ref('portrait');        // 'portrait' | 'landscape'
const paperSize    = ref('a4');              // 'a4' | 'letter'
// Excel
const autofilter   = ref(true);
const freezeHeader = ref(true);
// Común
const includeFiltersSummary = ref(true);

const submitting = ref(false);

// ─── Inicialización: cuando se abre, resetear con defaults ────────────────
watch(() => props.open, (val) => {
    if (val) {
        // Defaults columnas: las marcadas con default:true (o todas si ninguna lo está)
        const defaults = allColumns.value.filter(c => c.default !== false).map(c => c.key);
        selectedCols.value = defaults.length ? defaults : allColumns.value.map(c => c.key);
        title.value = props.defaultTitle;
        // Si no hay seleccionados, default a 'filtered'
        if (props.selectedIds.length === 0 && scope.value === 'selected') {
            scope.value = 'filtered';
        }
        // Si hay seleccionados, ofrecer 'selected' por defecto (cumple expectativa SAP)
        if (props.selectedIds.length > 0) {
            scope.value = 'selected';
        }
    }
});

// ─── Helpers UI ────────────────────────────────────────────────────────────
// CSV es siempre libre (streaming sin costo). Excel/PDF/Word se gatean por
// plan via la feature `export_{format}`. Solo se muestran los formatos que
// el plan del tenant tiene habilitados — sin opciones-fantasma.
//
// Cada opcion declara su `planFeature`: si el plan no la tiene, la opcion
// queda fuera del listado renderizado en el form.
const ALL_FORMATS = [
    { key: 'excel', label: 'Excel', ext: '.xlsx', icon: FileExcelOutlined, color: '#1D7044', planFeature: 'export_excel' },
    { key: 'pdf',   label: 'PDF',   ext: '.pdf',  icon: FilePdfOutlined,   color: '#C8281D', planFeature: 'export_pdf' },
    { key: 'word',  label: 'Word',  ext: '.docx', icon: FileWordOutlined,  color: '#185ABD', planFeature: 'export_word' },
    { key: 'csv',   label: 'CSV',   ext: '.csv',  icon: FileTextOutlined,  color: '#475569', streaming: true, planFeature: 'export_csv' },
];

// formatOptions reactivo: solo formatos que el plan permite. Si Excel no esta
// permitido, ni siquiera aparece en el RadioGroup — coherente con el patron
// "no mostres lo que el usuario no puede hacer".
const formatOptions = computed(() =>
    ALL_FORMATS.filter(f => canUsePlanFeature(f.planFeature))
);

// Si la format actual ya no esta disponible (caso edge: cambio de plan en
// vivo), forzamos al primer formato disponible.
watch(formatOptions, (opts) => {
    if (opts.length && !opts.find(o => o.key === format.value)) {
        format.value = opts[0].key;
    }
}, { immediate: true });

const selectAllCols = () => { selectedCols.value = allColumns.value.map(c => c.key); };
const clearAllCols  = () => { selectedCols.value = []; };

// ─── Conteo de filas según scope ──────────────────────────────────────────
// Para 'selected' usamos selectedIds.length. Para 'filtered' usamos totalRows
// (que ya viene filtrado del backend). Para 'all' usamos totalUnfiltered.
const estimatedRows = computed(() => {
    if (scope.value === 'selected') return props.selectedIds.length;
    if (scope.value === 'all')      return props.totalUnfiltered;
    return props.totalRows;
});

// Filas que exportaría cada alcance (para los contadores + los checks).
const rowsForScope = (s) => s === 'selected' ? props.selectedIds.length
    : s === 'all' ? props.totalUnfiltered
    : props.totalRows;

// ─── Límite por formato (0 = sin límite) ──────────────────────────────────
const limitFor = (formatKey) => Number(props.limits?.[formatKey] ?? 0);

const formatExceedsLimit = (formatKey) => {
    const limit = limitFor(formatKey);
    if (limit === 0) return false;          // sin límite
    return estimatedRows.value > limit;
};

const formatDisabledReason = (formatKey) => {
    if (!formatExceedsLimit(formatKey)) return null;
    return t('global.export_format_limit_hint', { limit: limitFor(formatKey).toLocaleString() });
};

// ─── Recomendar CSV cuando el dataset es grande ───────────────────────────
// Cualquier otro formato con su límite excedido → CSV es el camino.
const shouldRecommendCsv = computed(() => formatExceedsLimit('excel'));

// Si el formato actual quedó deshabilitado tras cambiar scope, cambiamos a CSV.
watch([scope, estimatedRows], () => {
    if (formatExceedsLimit(format.value)) {
        format.value = 'csv';
    }
});

const canSubmit = computed(() =>
    selectedCols.value.length > 0 && !formatExceedsLimit(format.value)
);

// ─── Submit ────────────────────────────────────────────────────────────────
const close = () => { if (!submitting.value) emit('update:open', false); };

const submit = () => {
    if (!canSubmit.value) return;

    submitting.value = true;

    const payload = {
        scope:                   scope.value,
        selected_ids:            scope.value === 'selected' ? props.selectedIds : [],
        columns:                 selectedCols.value,
        title:                   title.value?.trim() || props.defaultTitle,
        include_filters_summary: includeFiltersSummary.value,
        // Solo enviamos los filtros si el alcance es "filtered". Para 'all' o
        // 'selected' los omitimos para que el backend no los aplique por error.
        ...(scope.value === 'filtered' ? { filters: props.currentFilters } : {}),
    };

    if (format.value === 'pdf') {
        payload.orientation = orientation.value;
        payload.paper_size  = paperSize.value;
    }
    if (format.value === 'excel') {
        payload.autofilter    = autofilter.value;
        payload.freeze_header = freezeHeader.value;
    }

    const endpoint = props.endpoints[format.value];
    router.post(endpoint, payload, {
        preserveState: false,
        onFinish: () => {
            submitting.value = false;
            emit('update:open', false);
        },
    });
};
</script>

<template>
    <Modal
        :open="open"
        @update:open="emit('update:open', $event)"
        :title="$t('global.export') + ' — ' + $t('global.report')"
        :footer="null"
        width="100%"
        :mask-closable="!submitting"
        :closable="!submitting"
        wrap-class-name="export-dialog export-dialog--fullscreen"
    >
        <!-- ── Formato ────────────────────────────────────────────────── -->
        <section class="export-section">
            <h4 class="export-section__title">
                {{ $t('global.format') }}
                <span class="export-section__count">
                    · {{ estimatedRows.toLocaleString() }} {{ estimatedRows === 1 ? $t('global.record') : $t('global.records') }}
                </span>
            </h4>

            <!-- Aviso cuando el dataset excede algún límite → recomendar CSV -->
            <Alert
                v-if="shouldRecommendCsv"
                type="info"
                show-icon
                class="csv-recommend"
            >
                <template #message>
                    {{ $t('regions.export_no_limit_hint') }}
                </template>
            </Alert>

            <div class="format-grid">
                <Tooltip
                    v-for="opt in formatOptions"
                    :key="opt.key"
                    :title="formatDisabledReason(opt.key)"
                    :open="formatDisabledReason(opt.key) ? undefined : false"
                >
                    <button
                        type="button"
                        class="format-card"
                        :class="{
                            'format-card--active': format === opt.key,
                            'format-card--disabled': formatExceedsLimit(opt.key),
                            'format-card--streaming': opt.streaming,
                        }"
                        :disabled="formatExceedsLimit(opt.key)"
                        @click="!formatExceedsLimit(opt.key) && (format = opt.key)"
                    >
                        <component :is="opt.icon" class="format-card__icon" :style="{ color: opt.color }" />
                        <span class="format-card__label">{{ opt.label }}</span>
                        <span class="format-card__ext">{{ opt.ext }}</span>
                        <!-- Estado: verde = cabe en el alcance actual; rojo = lo excede.
                             El máximo del formato se muestra SIEMPRE (excel/pdf/word). -->
                        <span class="format-card__status" :class="formatExceedsLimit(opt.key) ? 'is-bad' : 'is-ok'">
                            <template v-if="opt.streaming">
                                <ThunderboltOutlined /> {{ $t('global.unlimited') }}
                            </template>
                            <template v-else-if="formatExceedsLimit(opt.key)">
                                <CloseCircleFilled /> {{ $t('global.export_max_n', { n: limitFor(opt.key).toLocaleString() }) }}
                            </template>
                            <template v-else>
                                <CheckCircleFilled /> {{ $t('global.export_max_n', { n: limitFor(opt.key).toLocaleString() }) }}
                            </template>
                        </span>
                    </button>
                </Tooltip>
            </div>
        </section>

        <!-- ── Alcance ────────────────────────────────────────────────── -->
        <section class="export-section">
            <h4 class="export-section__title">{{ $t('global.scope') }}</h4>
            <RadioGroup v-model:value="scope" class="scope-group">
                <Radio value="filtered" class="scope-radio">
                    <span class="scope-radio__label">{{ $t('global.scope_filtered') }}
                        <Tag color="blue" :bordered="false" class="scope-radio__tag">{{ rowsForScope('filtered').toLocaleString() }}</Tag>
                    </span>
                    <Tag v-if="hasFilters" color="blue" :bordered="false" class="scope-radio__tag">
                        {{ $t('global.filters_active') }}
                    </Tag>
                    <p v-if="hasFilters && filtersSummary" class="scope-radio__hint">
                        {{ filtersSummary }}
                    </p>
                </Radio>
                <Radio
                    value="selected"
                    :disabled="selectedIds.length === 0"
                    class="scope-radio"
                >
                    <span class="scope-radio__label">
                        {{ $t('global.scope_selected') }}
                        <Tag v-if="selectedIds.length" color="default" :bordered="false" class="scope-radio__tag">
                            {{ selectedIds.length }}
                        </Tag>
                    </span>
                    <p v-if="selectedIds.length === 0" class="scope-radio__hint">
                        {{ $t('global.no_selected') }}
                    </p>
                </Radio>
                <Radio value="all" class="scope-radio">
                    <span class="scope-radio__label">{{ $t('global.scope_all') }}
                        <Tag color="default" :bordered="false" class="scope-radio__tag">{{ rowsForScope('all').toLocaleString() }}</Tag>
                    </span>
                    <p class="scope-radio__hint">{{ $t('global.scope_all_hint') }}</p>
                </Radio>
            </RadioGroup>
        </section>

        <!-- ── Columnas ───────────────────────────────────────────────── -->
        <section class="export-section">
            <div class="export-section__head">
                <h4 class="export-section__title">{{ $t('global.columns') }}</h4>
                <Space size="small">
                    <Button size="small" type="link" @click="selectAllCols">{{ $t('global.all') }}</Button>
                    <Button size="small" type="link" @click="clearAllCols">{{ $t('global.none') }}</Button>
                </Space>
            </div>
            <CheckboxGroup v-model:value="selectedCols" class="cols-grid">
                <Checkbox
                    v-for="col in allColumns"
                    :key="col.key"
                    :value="col.key"
                    class="cols-grid__item"
                >
                    {{ col.label }}
                </Checkbox>
            </CheckboxGroup>
            <p v-if="selectedCols.length === 0" class="cols-warn">
                {{ $t('global.select_at_least_column') }}
            </p>
        </section>

        <!-- ── Opciones específicas por formato ───────────────────────── -->
        <section v-if="format === 'pdf'" class="export-section">
            <h4 class="export-section__title">{{ $t('global.pdf_layout') }}</h4>
            <div class="opts-row">
                <div class="opts-col">
                    <label class="opts-label">{{ $t('global.orientation') }}</label>
                    <RadioGroup v-model:value="orientation" button-style="solid" size="small">
                        <Radio.Button value="portrait">{{ $t('global.portrait') }}</Radio.Button>
                        <Radio.Button value="landscape">{{ $t('global.landscape') }}</Radio.Button>
                    </RadioGroup>
                </div>
                <div class="opts-col">
                    <label class="opts-label">{{ $t('global.size') }}</label>
                    <RadioGroup v-model:value="paperSize" button-style="solid" size="small">
                        <Radio.Button value="a4">A4</Radio.Button>
                        <Radio.Button value="letter">{{ $t('global.letter') }}</Radio.Button>
                    </RadioGroup>
                </div>
            </div>
        </section>

        <section v-if="format === 'excel'" class="export-section">
            <h4 class="export-section__title">{{ $t('global.excel_options') }}</h4>
            <Space direction="vertical" size="small" :style="{ width: '100%' }">
                <Checkbox v-model:checked="autofilter">{{ $t('global.enable_autofilter') }}</Checkbox>
                <Checkbox v-model:checked="freezeHeader">{{ $t('global.freeze_header') }}</Checkbox>
            </Space>
        </section>

        <!-- ── Título personalizado ───────────────────────────────────── -->
        <section class="export-section">
            <h4 class="export-section__title">
                {{ $t('global.report_title') }}
                <Tooltip :title="$t('global.report_title_hint')">
                    <span class="hint-mark">?</span>
                </Tooltip>
            </h4>
            <Input
                v-model:value="title"
                :placeholder="defaultTitle"
                size="large"
                :maxlength="120"
                show-count
            />
        </section>

        <section v-if="hasFilters && (format === 'pdf' || format === 'word')" class="export-section">
            <Checkbox v-model:checked="includeFiltersSummary">
                {{ $t('global.include_filters_summary') }}
            </Checkbox>
        </section>

        <!-- ── Footer ─────────────────────────────────────────────────── -->
        <div class="export-footer">
            <Button :disabled="submitting" @click="close">{{ $t('global.cancel') }}</Button>
            <Button
                type="primary"
                :loading="submitting"
                :disabled="!canSubmit"
                @click="submit"
            >
                <DownloadOutlined />
                {{ $t('global.export') }} {{ formatOptions.find(o => o.key === format)?.label ?? '' }}
            </Button>
        </div>
    </Modal>
</template>

<style scoped>
.export-section { margin-bottom: 22px; }
.export-section__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.export-section__title {
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-text-muted);
    margin: 0 0 10px 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.export-section__count {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-muted);
    text-transform: none;
    letter-spacing: 0;
}
.csv-recommend { margin-bottom: 12px; }
.hint-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #475569;
    font-size: 0.65rem;
    font-weight: 700;
    cursor: help;
}

/* Format cards */
.format-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
@media (max-width: 600px) {
    .format-grid { grid-template-columns: repeat(2, 1fr); }
}
.format-card {
    position: relative;
    background: var(--color-surface);
    border: 1.5px solid var(--color-border-strong);
    border-radius: 6px;
    padding: 14px 10px 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.12s ease;
}
.format-card:hover:not(:disabled) { border-color: var(--color-text-dim); }
.format-card--active {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(10, 110, 209, 0.15);
}
.format-card--disabled {
    opacity: 0.45;
    cursor: not-allowed;
    background: var(--color-surface-alt);
}
.format-card--streaming {
    border-color: var(--color-warning);
}
.format-card--streaming.format-card--active {
    border-color: var(--color-warning);
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
}
.format-card__badge {
    position: absolute;
    top: 4px;
    left: 4px;
    display: inline-flex;
    align-items: center;
    gap: 2px;
    background: var(--color-warning);
    color: var(--color-text-on-dark);
    font-size: 0.65rem;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 8px;
}
.format-card__icon { font-size: 2rem; line-height: 1; }
.format-card__label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text);
}
.format-card__ext {
    font-size: 0.7rem;
    color: var(--color-text-dim);
    font-family: ui-monospace, SFMono-Regular, monospace;
}
.format-card__check {
    position: absolute;
    top: 6px;
    right: 6px;
    color: var(--color-primary);
    font-size: 0.85rem;
}
/* Estado de exportabilidad por formato según el alcance elegido */
.format-card__status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.68rem;
    font-weight: 600;
    margin-top: 4px;
}
.format-card__status.is-ok  { color: #1D7044; }
.format-card__status.is-bad { color: #C8281D; }

/* Scope radios */
.scope-group { display: flex; flex-direction: column; gap: 6px; width: 100%; }
.scope-radio {
    display: flex !important;
    align-items: flex-start;
    width: 100%;
    padding: 8px 10px;
    border-radius: 4px;
    transition: background 0.12s ease;
}
.scope-radio:hover { background: #f8fafc; }
.scope-radio__label {
    font-size: 0.875rem;
    color: #1f2937;
    font-weight: 500;
}
.scope-radio__tag { margin-left: 8px; }
.scope-radio__hint {
    margin: 4px 0 0 0;
    font-size: 0.78rem;
    color: #6A6D70;
    line-height: 1.4;
    padding-left: 0;
}

/* Columns grid */
.cols-grid {
    display: grid !important;
    grid-template-columns: repeat(2, 1fr);
    gap: 6px 16px;
    width: 100%;
}
.cols-grid__item { margin: 0 !important; }
.cols-warn {
    margin: 8px 0 0 0;
    font-size: 0.78rem;
    color: #C8281D;
}

/* Options rows */
.opts-row { display: flex; gap: 24px; flex-wrap: wrap; }
.opts-col { display: flex; flex-direction: column; gap: 6px; }
.opts-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: #334155;
}

/* Footer */
.export-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

@media (max-width: 600px) {
    .format-grid { grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .format-card { padding: 12px 6px 10px; }
    .format-card__icon { font-size: 1.6rem; }
    .cols-grid { grid-template-columns: 1fr; }
    .opts-row { flex-direction: column; gap: 12px; }
}
</style>

<style>
/* Dark mode (no scoped: el modal portea fuera) */
html[data-theme="dark"] .export-dialog .export-section__title { color: #a8aaae; }
html[data-theme="dark"] .export-dialog .format-card {
    background: #2c3034;
    border-color: #3f4448;
}
html[data-theme="dark"] .export-dialog .format-card:hover { border-color: #4db6e8; }
html[data-theme="dark"] .export-dialog .format-card--active {
    border-color: #4db6e8;
    box-shadow: 0 0 0 3px rgba(77, 182, 232, 0.15);
}
html[data-theme="dark"] .export-dialog .format-card__label { color: #e5e6e7; }
html[data-theme="dark"] .export-dialog .format-card__ext   { color: #6b7785; }
html[data-theme="dark"] .export-dialog .scope-radio__label { color: #e5e6e7; }
html[data-theme="dark"] .export-dialog .scope-radio:hover { background: #313a44; }
html[data-theme="dark"] .export-dialog .scope-radio__hint { color: #a8aaae; }
html[data-theme="dark"] .export-dialog .opts-label        { color: #cbd5e1; }
html[data-theme="dark"] .export-dialog .export-footer     { border-top-color: #3f4448; }
html[data-theme="dark"] .export-dialog .hint-mark {
    background: #3f4448;
    color: #cbd5e1;
}
</style>
