<script setup>
import { computed, ref, watch } from 'vue';
import { Button, Empty, Tooltip, Popover, Badge, Drawer, Modal as AntModal, Input as AntInput, message } from 'ant-design-vue';
import {
    DeleteOutlined, UndoOutlined, SaveOutlined, InfoCircleOutlined, TableOutlined, FileExcelOutlined, MessageOutlined, PlusOutlined,
} from '@ant-design/icons-vue';
import writeXlsxFile from 'write-excel-file/browser';
import { useDiagnosticGrid } from '@/Composables/useDiagnosticGrid';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { useAuth } from '@/Composables/useAuth';
import { semaforoHex } from '@/utils/severity';
import CommentThread from '@/Components/Comments/CommentThread.vue';

/**
 * DiagnosticGrid — grilla editable estilo Excel reutilizable por las pruebas de
 * diagnóstico (cromas, furanos, fiquis, fpot). Dibuja la fecha + las columnas
 * numéricas editables (con navegación de teclado y pegado), la barra de
 * Guardar/Descartar y la columna de acciones (ⓘ + eliminar). Las columnas de
 * diagnóstico (Estado, IEEE, DP, …) las inyecta cada prueba por slots:
 *   - #diag-head : los <th> de las columnas de diagnóstico
 *   - #diag      : scoped slot ({ row }) con los <td> de diagnóstico de la fila
 *   - #legend    : leyenda opcional debajo de la tabla
 * Emite `explain(row)` al tocar el ⓘ.
 */
const props = defineProps({
    // Columnas numéricas editables: [{ key, label, tip? }].
    numericCols:     { type: Array,  required: true },
    requiredKeys:    { type: Array,  default: () => [] },
    // "Al menos uno de" estas claves debe tener valor en una fila con datos
    // (ej. cromas: al menos un gas de falla; O2/N2 no cuentan).
    requireAnyOf:    { type: Array,  default: () => [] },
    // Tinte de fila según el diagnóstico: (row) => color CSS | null. Lo usa la
    // prueba para resaltar filas "no buenas" (ej. furanos desde Medio/Malo).
    rowTint:         { type: Function, default: null },
    samples:         { type: Array,  default: () => [] },
    transformerSlug: { type: String, required: true },
    batchRoute:      { type: String, required: true },
    previewRoute:    { type: String, required: true },
    canEdit:         { type: Boolean, default: false },
    dateLabel:       { type: String, default: '' },
    seedDiag:        { type: Function, default: () => null },
    // Tinte + tooltip opcional por celda numérica (límites por banda).
    cellStyle:       { type: Function, default: null },
    cellTip:         { type: Function, default: null },
    showExplain:     { type: Boolean, default: true },
    // Nombre base del archivo al exportar (ej. 'furanos').
    exportName:      { type: String, default: 'ensayos' },
    // Tipo comentable para comentarios por muestra (ej. 'furano', 'chromatographical').
    // Si se define y el usuario tiene permiso, cada fila guardada muestra el botón.
    commentType:     { type: String, default: null },
    // Catálogo de laboratorios (per-tenant) para el select de la columna Laboratorio.
    laboratories:    { type: Array, default: () => [] },
});
const emit = defineEmits(['explain', 'lab-created']);

const { t } = useI18n();
const { condLabel } = useConditions();
const { can } = useAuth();

// Comentarios por muestra: botón por fila → drawer con el hilo (carga diferida).
const canViewComments = can('comments.view');
const commentOpen = ref(false);
const commentRow = ref(null);
const openComments = (row) => { commentRow.value = row; commentOpen.value = true; };
const onCommentCount = (n) => { if (commentRow.value) commentRow.value.comments_count = n; };

// ── Alta rápida de laboratorio (botón + del catálogo) ───────────────────────
// Permite agregar un laboratorio sin salir de la carga de muestras. Emite
// `lab-created` para que el padre lo agregue al catálogo de TODAS las pestañas.
const canCreateLab = can('laboratories.create');
// Copia local del catálogo de labs para inyectar uno recién creado sin recargar.
// Se resincroniza si el padre refresca la prop (p.ej. tras guardar muestras).
const localLabs = ref([...props.laboratories]);
watch(() => props.laboratories, (v) => { localLabs.value = [...v]; });
const labModal = ref({ open: false, saving: false, error: null, name: '' });
const openLabModal = () => { labModal.value = { open: true, saving: false, error: null, name: '' }; };
const createLab = async () => {
    const name = labModal.value.name.trim();
    if (!name) { labModal.value.error = t('diagnostics.lab_name_required'); return; }
    labModal.value.saving = true; labModal.value.error = null;
    try {
        const { data } = await window.axios.post(route('business_management.laboratories.quick_store'), { name });
        localLabs.value.push({ id: data.id, name: data.name });
        emit('lab-created', { id: data.id, name: data.name });
        labModal.value.open = false;
        message.success(t('global.success'));
    } catch (e) {
        labModal.value.error = e?.response?.data?.errors?.name?.[0]
            || e?.response?.data?.message || t('global.error');
    } finally {
        labModal.value.saving = false;
    }
};

const numericKeys = computed(() => props.numericCols.map((c) => c.key));

// Orden de columnas para la mini guía de pegado (Fecha + columnas numéricas).
// Se prefiere el SÍMBOLO cuando existe: es lo que trae el Excel del laboratorio
// y ocupa menos que el nombre completo.
const pasteColumns = computed(
    () => [props.dateLabel || t('diagnostics.date'), ...props.numericCols.map((c) => c.sym || c.label)],
);

const grid = useDiagnosticGrid({
    numericKeys: numericKeys.value,
    requiredKeys: props.requiredKeys,
    requireAnyOf: props.requireAnyOf,
    source: () => props.samples,
    transformerSlug: props.transformerSlug,
    batchRoute: props.batchRoute,
    previewRoute: props.previewRoute,
    seedDiag: props.seedDiag,
    canEdit: props.canEdit,
});

const {
    rows, upserts, dirtyCount, isDirtyRow, rowIsEmpty, missesAnyOf, missesAnyOfKey,
    setCell, onKeydown, onInput, onPaste, toggleDelete, saving, saveAll, discard,
    sortKey, sortDir, sortBy,
} = grid;

const caret = (key) => (sortKey.value === key ? (sortDir.value === 'asc' ? '▲' : '▼') : '');

// Filas cuya FECHA rechazó el servidor (ej. duplicada). Se marca la celda en
// rojo hasta que el usuario la edite o reintente. Guarda los `key` de fila.
const dupDateKeys = ref(new Set());
const clearDupError = (row) => {
    if (dupDateKeys.value.has(row.key)) {
        dupDateKeys.value.delete(row.key);
        dupDateKeys.value = new Set(dupDateKeys.value);
    }
};

// Celda inválida → se pinta de rojo. Solo en filas con datos (no en la fila
// fantasma vacía): la fecha es obligatoria si hay datos (o la rechazó el
// servidor), y las claves marcadas como requeridas no pueden quedar vacías.
const cellHasError = (row, key) => {
    if (!props.canEdit || row._deleted || rowIsEmpty(row)) return false;
    if (key === 'sample_date') return !row.sample_date || dupDateKeys.value.has(row.key);
    if (props.requiredKeys.includes(key) && (row[key] === '' || row[key] == null)) return true;
    // "Al menos uno de": si la fila no cumple, se marcan en rojo TODAS las
    // candidatas de ESE grupo (hasta que cargues una). Si esta clave ya tiene
    // valor, no.
    return missesAnyOfKey(row, key);
};

const fmt = (v) => (v === null || v === undefined || v === '' ? '—' : v);
const labName = (id) => localLabs.value.find((l) => l.id === id)?.name || '';
const onSave = () => {
    dupDateKeys.value = new Set();   // limpia marcas previas en cada intento
    saveAll({
        onInvalid: () => message.error(t('diagnostics.row_needs_data')),
        onError: (errors) => {
            // Mapea los errores upserts.{i}.sample_date a la fila por su índice.
            const flagged = new Set();
            Object.keys(errors || {}).forEach((k) => {
                const m = k.match(/^upserts\.(\d+)\.sample_date$/);
                if (m) {
                    const r = upserts.value[Number(m[1])];
                    if (r) flagged.add(r.key);
                }
            });
            dupDateKeys.value = flagged;
            const first = errors && Object.values(errors)[0];
            message.error(first || t('diagnostics.save_failed'));
        },
    });
};

// Filas reales (sin fantasma ni borradas), para exportar/contar.
const realRows = computed(() => rows.value.filter((r) => !r._deleted && !rowIsEmpty(r)));
const canExport = computed(() => realRows.value.length > 0);

/**
 * Exporta las muestras a un .xlsx real (write-excel-file), sin backend: las
 * muestras ya están en pantalla. Incluye fecha + columnas numéricas + DP (si
 * aplica) + Estado (con el color del semáforo). Los números van como números.
 */
// Paleta del informe (la misma banda #354A5F del PDF y del Word), no el azul
// SAP de la interfaz: en una hoja impresa el azul brillante a todo lo ancho de
// la cabecera se ve chillón.
const AZUL  = '#354A5F';   // fondo de la cabecera
// Un solo borde, OSCURO, para cabecera y datos. El gris claro que se probó
// antes se confundía con las líneas de fondo de Excel (que además no se
// imprimen) y la hoja seguía leyéndose como texto suelto.
const BORDE = '#2A3B4C';

const HEAD = {
    fontWeight: 'bold', backgroundColor: AZUL, color: '#FFFFFF',
    align: 'center', alignVertical: 'center', borderColor: BORDE, wrap: true,
};

/**
 * Cabecera de una columna numérica en el .xlsx, con las MISMAS líneas que la
 * cabecera de la tabla en pantalla: nombre (+ símbolo) · norma o unidad ·
 * condición de ensayo.
 *
 * En pantalla el nombre corto alcanza porque la línea de abajo y la columna
 * vecina desambiguan; en una hoja suelta no hay nada de eso, así que se usa el
 * nombre COMPLETO cuando la columna lo trae (`full`) — si no, se exportaban dos
 * "Rigidez Dieléctrica" y dos "Factor de Potencia" indistinguibles, y sin la
 * unidad no se sabía si un 45 eran kV o ppm.
 */
const cabeceraCol = (c) => {
    const nombre = c.full || c.label;
    return [c.sym ? `${nombre} (${c.sym})` : nombre, c.sub, c.sub2].filter(Boolean).join('\n');
};
// Las celdas VACÍAS también se devuelven como celda (no como null): si no, la
// rejilla se corta justo donde falta un dato y la tabla queda con agujeros.
// Sin `type` porque no hay valor que tipar.
const num = (v) => {
    const n = (v === '' || v === null || v === undefined) ? null : Number(v);
    return (n === null || Number.isNaN(n))
        ? { value: null, align: 'center', borderColor: BORDE }
        : { type: Number, value: n, align: 'center', borderColor: BORDE };
};
const txt = (v) => ({ type: String, value: v ?? null, align: 'center', borderColor: BORDE });
const exportExcel = async () => {
    const data = realRows.value;
    if (!data.length) return;
    const hasDp = data.some((r) => r._diag && r._diag.dp != null);
    const hasState = data.some((r) => r._diag && r._diag.condition);

    const header = [
        { value: props.dateLabel || t('diagnostics.date'), ...HEAD },
        ...props.numericCols.map((c) => ({ value: cabeceraCol(c), ...HEAD })),
    ];
    if (hasDp) header.push({ value: 'DP', ...HEAD });
    if (hasState) header.push({ value: t('diagnostics.state'), ...HEAD });

    const body = data.map((r) => {
        const cells = [
            txt(r.sample_date ? String(r.sample_date) : null),
            ...props.numericCols.map((c) => num(r[c.key])),
        ];
        if (hasDp) cells.push(num(r._diag?.dp));
        if (hasState) {
            const cond = r._diag?.condition;
            const bg = r._diag?.color ? semaforoHex(r._diag.color) : null;
            cells.push({
                type: String,
                value: cond ? condLabel(cond) : null,
                align: 'center', borderColor: BORDE,
                ...(bg ? { backgroundColor: bg, color: '#FFFFFF', fontWeight: 'bold' } : {}),
            });
        }
        return cells;
    });

    // Anchos: la fecha más ancha y cada columna según su línea de cabecera más
    // larga, para que la norma y la unidad no queden cortadas.
    const anchoCol = (c) => Math.min(24, Math.max(12, ...cabeceraCol(c).split('\n').map((l) => l.length + 2)));
    const columns = [{ width: 20 }, ...props.numericCols.map((c) => ({ width: anchoCol(c) }))];
    if (hasDp) columns.push({ width: 10 });
    if (hasState) columns.push({ width: 16 });

    // La API del build browser devuelve { toBlob, toFile }: hay que llamar .toFile().
    await writeXlsxFile([header, ...body], { columns, fontFamily: 'Calibri', fontSize: 11 })
        .toFile(`${props.exportName}-${props.transformerSlug}.xlsx`);
};
</script>

<template>
    <div class="dg">
        <div class="dg__bar">
            <span class="dg__title"><slot name="title" /></span>
            <div class="dg__tools">
                <Tooltip :title="$t('diagnostics.export_help')">
                    <Button v-if="canExport" size="small" @click="exportExcel">
                        <template #icon><FileExcelOutlined /></template>{{ $t('diagnostics.export') }}
                    </Button>
                </Tooltip>
                <template v-if="canEdit">
                <Tooltip v-if="canCreateLab" :title="$t('diagnostics.add_lab')">
                    <Button size="small" @click="openLabModal">
                        <template #icon><PlusOutlined /></template>{{ $t('diagnostics.laboratory') }}
                    </Button>
                </Tooltip>
                <Popover trigger="click" placement="bottomRight">
                    <template #content>
                        <div class="dg-guide">
                            <p class="dg-guide__intro">{{ $t('diagnostics.paste_guide_intro') }}</p>
                            <p class="dg-guide__lbl">{{ $t('diagnostics.paste_guide_cols') }}</p>
                            <div class="dg-guide__cols">
                                <span v-for="(c, i) in pasteColumns" :key="i" class="dg-guide__chip">{{ c }}</span>
                            </div>
                            <ol class="dg-guide__steps">
                                <li>{{ $t('diagnostics.paste_guide_step1') }}</li>
                                <li>{{ $t('diagnostics.paste_guide_step2') }}</li>
                                <li>{{ $t('diagnostics.paste_guide_step3') }}</li>
                            </ol>
                            <p class="dg-guide__note">{{ $t('diagnostics.paste_guide_date') }}</p>
                        </div>
                    </template>
                    <Tooltip :title="$t('diagnostics.import_help')">
                        <Button size="small">
                            <template #icon><TableOutlined /></template>{{ $t('diagnostics.paste_guide_open') }}
                        </Button>
                    </Tooltip>
                </Popover>
                <span class="dg__dirty" :class="{ 'is-dirty': dirtyCount > 0 }">
                    {{ dirtyCount > 0 ? $t('diagnostics.unsaved', { count: dirtyCount }) : $t('diagnostics.all_saved') }}
                </span>
                <Tooltip :title="$t('diagnostics.discard_help')">
                    <Button size="small" :disabled="dirtyCount === 0 || saving" @click="discard">
                        <template #icon><UndoOutlined /></template>{{ $t('diagnostics.discard') }}
                    </Button>
                </Tooltip>
                <Tooltip :title="$t('diagnostics.save_help')">
                    <Button type="primary" size="small" :loading="saving" :disabled="dirtyCount === 0" @click="onSave">
                        <template #icon><SaveOutlined /></template>{{ $t('diagnostics.save_all') }}
                    </Button>
                </Tooltip>
                </template>
            </div>
        </div>

        <p v-if="canEdit" class="dg__hint">{{ $t('diagnostics.editor_hint') }}</p>

        <div v-if="!canEdit && !samples.length" class="dg__empty">
            <Empty :description="$t('diagnostics.no_samples')" />
        </div>

        <div v-else class="dg__wrap">
            <table class="dg__table" :class="{ 'is-edit': canEdit }">
                <thead>
                    <tr>
                        <th class="left sortable" @click="sortBy('sample_date')">
                            <Tooltip :title="$t('diagnostics.date_help')">{{ dateLabel || $t('diagnostics.date') }}</Tooltip><i class="th-caret">{{ caret('sample_date') }}</i>
                        </th>
                        <th class="left sortable" @click="sortBy('report_number')">
                            {{ $t('diagnostics.report_number') }}<i class="th-caret">{{ caret('report_number') }}</i>
                        </th>
                        <th class="left">{{ $t('diagnostics.laboratory') }}</th>
                        <th v-for="col in numericCols" :key="col.key" class="sortable" :class="{ 'th-2line': col.sub || col.sym, 'th-3line': col.sym }" @click="sortBy(col.key)">
                            <!-- Líneas: nombre · [símbolo | método+condición] · unidad -->
                            <Tooltip v-if="col.tip" :title="col.tip">{{ col.label }}</Tooltip>
                            <template v-else>{{ col.label }}</template><i class="th-caret">{{ caret(col.key) }}</i>
                            <!-- Línea del SÍMBOLO (H₂, CO₂…): identificador que el
                                 diagnosticador lee primero, por eso va destacado. -->
                            <span v-if="col.sym" class="th-sub th-sub--sym">{{ col.sym }}</span>
                            <span v-if="col.sub" class="th-sub">{{ col.sub }}</span>
                            <span v-if="col.sub2" class="th-sub th-sub--unit">{{ col.sub2 }}</span>
                        </th>
                        <slot name="diag-head" :sort-by="sortBy" :sort-key="sortKey" :caret="caret" />
                        <th class="col-act"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, ri) in rows"
                        :key="row.key"
                        :class="{ 'row-deleted': row._deleted, 'row-dirty': canEdit && !row._deleted && isDirtyRow(row) }"
                        :style="rowTint && rowTint(row) ? { background: rowTint(row) } : null"
                    >
                        <!-- Fecha -->
                        <td class="left">
                            <template v-if="canEdit">
                                <input
                                    class="cell cell--date" :class="{ 'cell--error': cellHasError(row, 'sample_date') }" type="text" :placeholder="$t('diagnostics.sample_date_hint')"
                                    :value="row.sample_date" :ref="(el) => setCell(el, ri, 0)" :disabled="row._deleted"
                                    @input="(e) => { clearDupError(row); onInput(e, ri, 0); }" @keydown="(e) => onKeydown(e, ri, 0)" @paste="(e) => onPaste(e, ri, 0)"
                                />
                            </template>
                            <template v-else>{{ row.sample_date }}</template>
                        </td>

                        <!-- N° de informe (texto libre, no afecta el diagnóstico) -->
                        <td class="left">
                            <template v-if="canEdit">
                                <input
                                    class="cell cell--report" type="text" :value="row.report_number" :disabled="row._deleted"
                                    @input="(e) => { row.report_number = e.target.value; }"
                                />
                            </template>
                            <template v-else>{{ row.report_number || '—' }}</template>
                        </td>

                        <!-- Laboratorio (select del catálogo per-tenant) -->
                        <td class="left">
                            <template v-if="canEdit">
                                <select
                                    class="cell cell--lab" :value="row.laboratory_id ?? ''" :disabled="row._deleted"
                                    @change="(e) => { row.laboratory_id = e.target.value ? Number(e.target.value) : null; }"
                                >
                                    <option value="">—</option>
                                    <option v-for="l in localLabs" :key="l.id" :value="l.id">{{ l.name }}</option>
                                </select>
                            </template>
                            <template v-else>{{ labName(row.laboratory_id) || '—' }}</template>
                        </td>

                        <!-- Columnas numéricas -->
                        <td v-for="(col, ci) in numericCols" :key="col.key" :style="cellStyle ? cellStyle(col.key, row[col.key]) : null">
                            <template v-if="canEdit">
                                <input
                                    class="cell cell--num" :class="{ 'cell--error': cellHasError(row, col.key) }" type="text" inputmode="decimal"
                                    :value="row[col.key]" :ref="(el) => setCell(el, ri, ci + 1)" :disabled="row._deleted"
                                    @input="(e) => onInput(e, ri, ci + 1)" @keydown="(e) => onKeydown(e, ri, ci + 1)" @paste="(e) => onPaste(e, ri, ci + 1)"
                                />
                            </template>
                            <template v-else>
                                <Tooltip v-if="cellTip && cellTip(col.key, row[col.key])" :title="cellTip(col.key, row[col.key])">{{ fmt(row[col.key]) }}</Tooltip>
                                <template v-else>{{ fmt(row[col.key]) }}</template>
                            </template>
                        </td>

                        <!-- Columnas de diagnóstico (inyectadas por la prueba) -->
                        <slot name="diag" :row="row" />

                        <!-- Acciones -->
                        <td class="col-act">
                            <div class="row-acts">
                                <Tooltip v-if="showExplain && !rowIsEmpty(row) && !row._deleted" :title="$t('diagnostics.explain_open')">
                                    <button class="ic" @click="emit('explain', row)"><InfoCircleOutlined /></button>
                                </Tooltip>
                                <Tooltip v-if="commentType && canViewComments && row.id && !row._deleted" :title="$t('comments.title')">
                                    <button class="ic" @click="openComments(row)">
                                        <Badge :count="row.comments_count || 0" size="small" :offset="[2, -2]">
                                            <MessageOutlined />
                                        </Badge>
                                    </button>
                                </Tooltip>
                                <Tooltip v-if="canEdit && row._deleted" :title="$t('diagnostics.restore_row')">
                                    <button class="ic" @click="toggleDelete(row)"><UndoOutlined /></button>
                                </Tooltip>
                                <Tooltip v-else-if="canEdit && !rowIsEmpty(row)" :title="$t('diagnostics.delete_row')">
                                    <button class="ic ic--danger" @click="toggleDelete(row)"><DeleteOutlined /></button>
                                </Tooltip>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <slot name="legend" />

        <!-- Comentarios de la muestra seleccionada (carga diferida). -->
        <Drawer
            v-if="commentType"
            v-model:open="commentOpen"
            :title="`${$t('comments.title')} · ${commentRow ? commentRow.sample_date : ''}`"
            placement="right"
            :width="420"
        >
            <CommentThread
                v-if="commentRow"
                :key="commentRow.id"
                :type="commentType"
                :id="commentRow.id"
                context="sample"
                lazy
                @count="onCommentCount"
            />
        </Drawer>

        <!-- Alta rápida de laboratorio (catálogo per-tenant). -->
        <AntModal
            v-model:open="labModal.open"
            :title="$t('diagnostics.add_lab')"
            :confirm-loading="labModal.saving"
            :ok-text="$t('global.create')"
            :cancel-text="$t('global.cancel')"
            @ok="createLab"
        >
            <AntInput
                v-model:value="labModal.name"
                :placeholder="$t('diagnostics.lab_name')"
                :maxlength="255"
                @press-enter="createLab"
            />
            <p v-if="labModal.error" class="dg-lab-error">{{ labModal.error }}</p>
        </AntModal>
    </div>
</template>

<style scoped>
.dg__bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 6px; flex-wrap: wrap; }
.dg__count { font-weight: 400; color: var(--color-text-muted, #6A6D70); }
.dg__title { font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); text-transform: uppercase; letter-spacing: 0.4px; }
.dg__tools { display: flex; align-items: center; gap: 10px; }
.dg__dirty { font-size: 0.78rem; color: var(--color-text-muted, #9aa0a6); }
.dg__dirty.is-dirty { color: #E9A23B; font-weight: 600; }
.dg__hint { font-size: 0.76rem; color: var(--color-text-muted, #9aa0a6); margin: 0 0 12px; }
.dg-lab-error { color: #C8281D; font-size: 0.8rem; margin: 8px 0 0; }

/* Mini guía "Importar desde Excel" (contenido del popover) */
.dg-guide { max-width: 340px; }
.dg-guide__intro { margin: 0 0 10px; font-size: 0.82rem; color: var(--color-text, #32363a); }
.dg-guide__lbl { margin: 0 0 6px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); }
.dg-guide__cols { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 10px; }
.dg-guide__chip { background: rgba(10, 110, 209, 0.10); color: #0A6ED1; padding: 2px 9px; border-radius: 999px; font-size: 0.76rem; font-weight: 600; }
.dg-guide__steps { margin: 0 0 8px; padding-left: 18px; font-size: 0.8rem; color: var(--color-text, #32363a); line-height: 1.5; }
.dg-guide__steps li { margin-bottom: 4px; }
.dg-guide__note { margin: 0; font-size: 0.76rem; color: var(--color-text-muted, #9aa0a6); }
.dg__empty { padding: 24px 0; }
/* Scroll propio con cabecera fija (sticky), como en las tablas index. */
.dg__wrap { max-height: 62vh; overflow: auto; }
.dg__table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.dg__table th, .dg__table td { padding: 8px 10px; text-align: center; white-space: nowrap; border-bottom: 1px solid var(--color-border, #eef0f2); }
.dg__table th { background: var(--color-surface-alt, #f5f6f7); color: var(--color-text-strong, #32363a); font-weight: 600; font-size: 0.78rem; }
/* Headers largos (ej. fiquis con norma ASTM) en 2 líneas: nombre + norma/unidad. */
/* El NOMBRE puede envolver (así la columna no se estira), pero SOLO entre
   palabras: sin keep-all el navegador partía "Rigidez Dieléctric/a" para
   encajarlo en la columna más angosta. */
.dg__table th.th-2line { white-space: normal; max-width: 120px; line-height: 1.18; vertical-align: bottom; word-break: keep-all; overflow-wrap: normal; }
/* Las líneas secundarias (método · condición · unidad) NO se parten: son datos
   cortos y quebrarlos daba cosas como "ASTM D1816 · 2 / mm". El NOMBRE (línea
   1) sí puede envolver, que es lo que mantiene angosta la columna. */
.dg__table th.th-2line .th-sub { display: block; font-weight: 400; font-size: 0.68rem; color: var(--color-text-muted, #6A6D70); margin-top: 2px; white-space: nowrap; }
/* Símbolo químico: mismo color que el nombre y en negrita (es el identificador
   real de la columna); la unidad queda como línea tenue debajo. */
.dg__table th.th-2line .th-sub--sym { font-weight: 700; font-size: 0.74rem; color: inherit; margin-top: 1px; }
/* Cabecera de 3 líneas (nombre · símbolo · unidad): cada línea entera. Sin esto
   el ancho de columna parte el nombre a media palabra ("Hidrógen-o"). La grilla
   ya tiene scroll horizontal (.dg__wrap), así que ensanchar es seguro. */
.dg__table th.th-3line { white-space: nowrap; max-width: none; }
/* Unidad (3ª línea de fiquis): igual de tenue que el método, con un respiro. */
.dg__table th.th-2line .th-sub--unit { margin-top: 1px; }
/* Cabecera pegada arriba al hacer scroll. El borde inferior va por box-shadow
   porque con border-collapse el borde de una celda sticky se pierde al scrollear. */
.dg__table thead th, .dg__table thead :slotted(th) {
    position: sticky; top: 0; z-index: 2;
    box-shadow: inset 0 -1px 0 var(--color-border, #eef0f2);
}
.dg__table .left { text-align: left; }
.dg__table th.sortable { cursor: pointer; user-select: none; }
.dg__table th.sortable:hover { color: #0A6ED1; }
.th-caret { font-style: normal; font-size: 0.7rem; margin-left: 4px; color: #0A6ED1; }
.dg__table.is-edit th, .dg__table.is-edit td { border: 1px solid var(--color-border, #eef0f2); }
.dg__table.is-edit th { border-bottom-width: 1px; }

/* Las columnas de diagnóstico (DP, Estado, IEEE…) llegan por slot, así que el
   CSS scoped normal no las alcanza: se igualan al resto con :slotted(). */
.dg__table :slotted(th), .dg__table :slotted(td) {
    padding: 8px 10px; text-align: center; white-space: nowrap;
    border-bottom: 1px solid var(--color-border, #eef0f2);
}
.dg__table :slotted(th) {
    background: var(--color-surface-alt, #f5f6f7);
    color: var(--color-text-strong, #32363a);
    font-weight: 600; font-size: 0.78rem;
}
.dg__table.is-edit :slotted(th), .dg__table.is-edit :slotted(td) {
    border: 1px solid var(--color-border, #eef0f2);
}
.dg__table tbody tr:hover { background: var(--color-surface-alt, #f7f9fb); }
.col-act { width: 76px; }
.row-acts { display: flex; gap: 2px; justify-content: center; }
.row-deleted { opacity: 0.45; text-decoration: line-through; }
.row-dirty td { background: rgba(233, 162, 59, 0.08); }

.cell { width: 100%; border: none; background: transparent; padding: 4px 6px; font: inherit; color: var(--color-text, #32363a); text-align: center; outline: none; border-radius: 4px; }
.cell--date { text-align: left; min-width: 130px; }
.cell--num { min-width: 56px; }
.cell:focus { background: var(--color-surface, #fff); box-shadow: inset 0 0 0 2px #0A6ED1; }
.cell:disabled { color: var(--color-text-muted, #9aa0a6); }
.cell--error { background: rgba(200, 40, 29, 0.10); box-shadow: inset 0 0 0 1px #C8281D; }
.cell--error::placeholder { color: #C8281D; }
.cell--error:focus { box-shadow: inset 0 0 0 2px #C8281D; }

.ic { border: none; background: transparent; cursor: pointer; color: #6A6D70; width: 26px; height: 26px; border-radius: 6px; }
.ic:hover { background: rgba(10,110,209,0.1); color: #0A6ED1; }
.ic--danger:hover { background: rgba(200,40,29,0.1); color: #C8281D; }

html[data-theme="dark"] .dg__table th { background: #2c3034; color: #e5e6e7; }
html[data-theme="dark"] .dg__table th, html[data-theme="dark"] .dg__table td { border-bottom-color: #3f4448; }
html[data-theme="dark"] .dg__table.is-edit th, html[data-theme="dark"] .dg__table.is-edit td { border-color: #3f4448; }
html[data-theme="dark"] .dg__table :slotted(th) { background: #2c3034; color: #e5e6e7; }
html[data-theme="dark"] .dg__table :slotted(th), html[data-theme="dark"] .dg__table :slotted(td) { border-bottom-color: #3f4448; }
html[data-theme="dark"] .dg__table.is-edit :slotted(th), html[data-theme="dark"] .dg__table.is-edit :slotted(td) { border-color: #3f4448; }
html[data-theme="dark"] .dg__table tbody tr:hover { background: #2c3034; }
html[data-theme="dark"] .cell:focus { background: #24282c; }
html[data-theme="dark"] .dg-guide__intro, html[data-theme="dark"] .dg-guide__steps { color: #e5e6e7; }
html[data-theme="dark"] .dg-guide__chip { background: rgba(77, 182, 232, 0.16); color: #6db8ec; }
</style>
