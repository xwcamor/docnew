<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Modal, Button, Radio, RadioGroup, Upload, Tag, Alert, Table,
    Statistic, Space, Tooltip,
} from 'ant-design-vue';
import {
    InboxOutlined, DownloadOutlined, CloudUploadOutlined,
    FileExcelOutlined, CheckCircleFilled, CloseCircleFilled,
    PlusCircleFilled, EditFilled, MinusCircleFilled, ReloadOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

/**
 * ImportDialog — diálogo SAP-style para importar registros desde XLSX/CSV.
 *
 * Flujo en dos fases:
 *   1. El usuario sube un archivo y elige el modo (crear / actualizar-o-crear).
 *      El dialog hace POST con dry_run=true → backend valida y rollback,
 *      devolviendo un summary con totales y errores fila-por-fila.
 *   2. El usuario revisa el preview y confirma → el dialog re-postea el MISMO
 *      archivo con dry_run=false para commit real. Tras éxito, recarga la lista.
 *
 * Props:
 *   - endpoint:         POST URL para importar (mismo para preview y commit)
 *   - templateUrl:      GET URL para descargar plantilla
 *   - resourceLabel:    'regiones' | 'idiomas' | etc. — usado en mensajes
 *   - reloadOnSuccess:  recargar Inertia tras commit (default true)
 */

const props = defineProps({
    open:            { type: Boolean, default: false },
    endpoint:        { type: String,  required: true },
    templateUrl:     { type: String,  required: true },
    resourceLabel:   { type: String,  default: 'registros' },
    reloadOnSuccess: { type: Boolean, default: true },
    // Columnas extra a mostrar entre `name` y `is_active` (ej: iso_code para
    // Languages, currency para Countries, etc.). Cada item igual a la struct
    // de antd Table columns. Si no se pasa, se usa el default (sin extras).
    extraPreviewColumns: { type: Array,  default: () => [] },
});

const emit = defineEmits(['update:open', 'success']);

// ─── Estado ────────────────────────────────────────────────────────────────
const file       = ref(null);            // File object del navegador
const fileName   = ref('');
const mode       = ref('update_or_create');
const submitting = ref(false);
const summary    = ref(null);            // resultado del dry_run
const errorMsg   = ref('');

// Reset al abrir
watch(() => props.open, (val) => {
    if (val) {
        file.value      = null;
        fileName.value  = '';
        summary.value   = null;
        errorMsg.value  = '';
        submitting.value = false;
        mode.value      = 'update_or_create';
    }
});

// ─── Upload (manual — interceptamos para no autoenviar) ────────────────────
const beforeUpload = (f) => {
    file.value     = f;
    fileName.value = f.name;
    summary.value  = null;
    errorMsg.value = '';
    return false;  // false = no upload automático
};

const removeFile = () => {
    file.value     = null;
    fileName.value = '';
    summary.value  = null;
    errorMsg.value = '';
};

// ─── POST con axios (necesitamos JSON response, no Inertia visit) ──────────
const post = async (dryRun) => {
    if (!file.value) return null;

    const formData = new FormData();
    formData.append('file',    file.value);
    formData.append('mode',    mode.value);
    formData.append('dry_run', dryRun ? 1 : 0);

    submitting.value = true;
    errorMsg.value   = '';

    try {
        const response = await window.axios.post(props.endpoint, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return response.data;
    } catch (err) {
        const message = err.response?.data?.message
            || err.response?.data?.errors?.file?.[0]
            || t('imports.process_failed');
        errorMsg.value = message;
        return null;
    } finally {
        submitting.value = false;
    }
};

const runPreview = async () => {
    const data = await post(true);
    if (data?.ok) summary.value = data.summary;
};

const runCommit = async () => {
    const data = await post(false);
    if (data?.ok) {
        emit('success', data.summary);
        emit('update:open', false);
        if (props.reloadOnSuccess) {
            // Recarga la página actual para refrescar la tabla.
            router.reload({ preserveScroll: true });
        }
    }
};

const close = () => { if (!submitting.value) emit('update:open', false); };

// ─── Tabla de preview ──────────────────────────────────────────────────────
const previewColumns = computed(() => [
    { title: t('imports.col_row'),    dataIndex: 'row',       key: 'row',       width: 72 },
    { title: t('imports.col_name'),   dataIndex: 'name',      key: 'name',      ellipsis: true },
    ...props.extraPreviewColumns,
    { title: t('imports.col_active'), dataIndex: 'is_active', key: 'is_active', width: 90 },
    { title: t('imports.col_action'), dataIndex: 'action',    key: 'action',    width: 130 },
]);

const errorColumns = computed(() => [
    { title: t('imports.col_row'),   dataIndex: 'row',     key: 'row',     width: 72 },
    { title: t('imports.col_value'), dataIndex: 'value',   key: 'value',   ellipsis: true },
    { title: t('imports.col_error'), dataIndex: 'message', key: 'message', ellipsis: true },
]);

const actionTag = (action) => {
    switch (action) {
        case 'created': return { color: 'green',   icon: PlusCircleFilled,  label: t('imports.action_create') };
        case 'updated': return { color: 'blue',    icon: EditFilled,        label: t('imports.action_update') };
        case 'skipped': return { color: 'default', icon: MinusCircleFilled, label: t('imports.action_skip')   };
        default:        return { color: 'default', icon: MinusCircleFilled, label: action };
    }
};

const summaryHasChanges = computed(() => {
    if (!summary.value) return false;
    return (summary.value.created + summary.value.updated) > 0;
});
</script>

<template>
    <Modal
        :open="open"
        @update:open="emit('update:open', $event)"
        :title="$t('imports.title')"
        :footer="null"
        width="100%"
        :mask-closable="!submitting"
        :closable="!submitting"
        wrap-class-name="import-dialog import-dialog--fullscreen"
    >
        <!-- ── Archivo ────────────────────────────────────────────────── -->
        <section class="imp-section">
            <div class="imp-section__head">
                <h4 class="imp-section__title">{{ $t('imports.file') }}</h4>
                <a :href="templateUrl" class="template-link">
                    <DownloadOutlined /> {{ $t('imports.download_template') }}
                </a>
            </div>

            <Upload.Dragger
                :before-upload="beforeUpload"
                :show-upload-list="false"
                accept=".xlsx,.xls,.csv"
                :disabled="submitting"
                class="imp-dragger"
            >
                <p class="imp-dragger__icon"><InboxOutlined /></p>
                <p class="imp-dragger__text">
                    <strong>{{ $t('imports.drag_file_strong') }}</strong> {{ $t('imports.drag_or_click') }}
                </p>
                <p class="imp-dragger__hint">
                    {{ $t('imports.formats_hint') }}
                </p>
            </Upload.Dragger>

            <div v-if="fileName" class="file-pill">
                <FileExcelOutlined class="file-pill__icon" />
                <span class="file-pill__name">{{ fileName }}</span>
                <Button type="text" size="small" @click="removeFile" :disabled="submitting">
                    {{ $t('global.remove') }}
                </Button>
            </div>
        </section>

        <!-- ── Modo ───────────────────────────────────────────────────── -->
        <section v-if="!summary" class="imp-section">
            <h4 class="imp-section__title">{{ $t('imports.mode') }}</h4>
            <RadioGroup v-model:value="mode" class="mode-group">
                <Radio value="update_or_create" class="mode-radio">
                    <span class="mode-radio__label">{{ $t('imports.mode_update_or_create') }}</span>
                    <p class="mode-radio__hint">
                        {{ $t('imports.mode_update_or_create_hint') }}
                    </p>
                </Radio>
                <Radio value="create_only" class="mode-radio">
                    <span class="mode-radio__label">{{ $t('imports.mode_create_only') }}</span>
                    <p class="mode-radio__hint">
                        {{ $t('imports.mode_create_only_hint') }}
                    </p>
                </Radio>
            </RadioGroup>
        </section>

        <!-- ── Error global ───────────────────────────────────────────── -->
        <Alert
            v-if="errorMsg"
            type="error"
            :message="errorMsg"
            show-icon
            closable
            class="imp-alert"
            @close="errorMsg = ''"
        />

        <!-- ── Preview tras dry_run ───────────────────────────────────── -->
        <section v-if="summary" class="imp-section">
            <h4 class="imp-section__title">{{ $t('imports.preview_result') }}</h4>

            <div class="stats-grid">
                <div class="stat stat--ok">
                    <PlusCircleFilled />
                    <div>
                        <div class="stat__num">{{ summary.created }}</div>
                        <div class="stat__lbl">{{ $t('imports.stat_create') }}</div>
                    </div>
                </div>
                <div class="stat stat--info">
                    <EditFilled />
                    <div>
                        <div class="stat__num">{{ summary.updated }}</div>
                        <div class="stat__lbl">{{ $t('imports.stat_update') }}</div>
                    </div>
                </div>
                <div class="stat stat--mute">
                    <MinusCircleFilled />
                    <div>
                        <div class="stat__num">{{ summary.skipped }}</div>
                        <div class="stat__lbl">{{ $t('imports.stat_skip') }}</div>
                    </div>
                </div>
                <div class="stat" :class="summary.error_count ? 'stat--err' : 'stat--mute'">
                    <CloseCircleFilled />
                    <div>
                        <div class="stat__num">{{ summary.error_count }}</div>
                        <div class="stat__lbl">{{ $t('imports.stat_errors') }}</div>
                    </div>
                </div>
            </div>

            <Alert
                v-if="!summaryHasChanges && summary.error_count === 0"
                type="info"
                show-icon
                :message="$t('imports.no_changes')"
                :description="$t('imports.no_changes_desc')"
                class="imp-alert"
            />

            <Alert
                v-if="summary.error_count > 0"
                type="warning"
                show-icon
                :message="$t(summary.error_count === 1 ? 'imports.rows_with_problems' : 'imports.rows_with_problems_plural', { count: summary.error_count })"
                :description="$t('imports.rows_with_problems_desc')"
                class="imp-alert"
            />

            <!-- Errors table -->
            <div v-if="summary.errors?.length" class="preview-block">
                <h5 class="preview-block__title">
                    <CloseCircleFilled style="color: #C8281D" /> {{ $t('imports.errors') }}
                </h5>
                <Table
                    :columns="errorColumns"
                    :data-source="summary.errors"
                    :pagination="false"
                    size="small"
                    row-key="row"
                    :scroll="{ y: 180 }"
                />
            </div>

            <!-- Preview table -->
            <div v-if="summary.preview?.length" class="preview-block">
                <h5 class="preview-block__title">
                    <CheckCircleFilled style="color: #1D7044" /> {{ $t('imports.preview_changes') }}
                </h5>
                <Table
                    :columns="previewColumns"
                    :data-source="summary.preview"
                    :pagination="false"
                    size="small"
                    row-key="row"
                    :scroll="{ y: 240 }"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'is_active'">
                            <Tag :color="record.is_active ? 'success' : 'default'" :bordered="false">
                                {{ record.is_active ? $t('global.yes') : $t('global.no') }}
                            </Tag>
                        </template>
                        <template v-else-if="column.key === 'action'">
                            <Tag
                                :color="actionTag(record.action).color"
                                :bordered="false"
                            >
                                <component :is="actionTag(record.action).icon" />
                                {{ actionTag(record.action).label }}
                            </Tag>
                        </template>
                    </template>
                </Table>
            </div>
        </section>

        <!-- ── Footer ─────────────────────────────────────────────────── -->
        <div class="imp-footer">
            <Button :disabled="submitting" @click="close">{{ $t('global.cancel') }}</Button>

            <!-- Estado 1: sin preview todavía → mostrar "Previsualizar" -->
            <Button
                v-if="!summary"
                type="primary"
                :loading="submitting"
                :disabled="!file"
                @click="runPreview"
            >
                <CloudUploadOutlined />
                {{ $t('imports.preview_import') }}
            </Button>

            <!-- Estado 2: con preview → "Volver" + "Confirmar" -->
            <template v-else>
                <Button :disabled="submitting" @click="summary = null">
                    <ReloadOutlined /> {{ $t('global.retry') }}
                </Button>
                <Button
                    type="primary"
                    :loading="submitting"
                    :disabled="!summaryHasChanges"
                    @click="runCommit"
                >
                    <CheckCircleFilled />
                    {{ $t('imports.confirm_import', { count: summary.created + summary.updated }) }}
                </Button>
            </template>
        </div>
    </Modal>
</template>

<style scoped>
.imp-section { margin-bottom: 22px; }
.imp-section__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.imp-section__title {
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #6A6D70;
    margin: 0 0 10px 0;
}

.template-link {
    color: #0A6ED1;
    font-size: 0.8125rem;
    text-decoration: none;
    font-weight: 500;
}
.template-link:hover { text-decoration: underline; }

/* Drag-and-drop area */
.imp-dragger {
    border: 2px dashed #d4d8dd !important;
    background: #f8fafc !important;
    border-radius: 8px !important;
    padding: 20px 12px !important;
}
.imp-dragger:hover { border-color: #0A6ED1 !important; }
.imp-dragger__icon {
    font-size: 2.4rem;
    color: #0A6ED1;
    margin: 0 0 8px 0;
}
.imp-dragger__text {
    font-size: 0.95rem;
    color: #1f2937;
    margin: 0 0 4px 0;
}
.imp-dragger__hint {
    font-size: 0.78rem;
    color: #6A6D70;
    margin: 0;
}

/* Selected file pill */
.file-pill {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #E6F1FB;
    border: 1px solid #B5D7F4;
    border-radius: 4px;
    padding: 8px 12px;
    margin-top: 10px;
    font-size: 0.875rem;
}
.file-pill__icon { color: #1D7044; font-size: 1.1rem; }
.file-pill__name { flex: 1; font-weight: 500; color: #1f2937; }

/* Mode radios */
.mode-group { display: flex; flex-direction: column; gap: 6px; width: 100%; }
.mode-radio {
    display: flex !important;
    align-items: flex-start;
    width: 100%;
    padding: 8px 10px;
    border-radius: 4px;
    transition: background 0.12s ease;
}
.mode-radio:hover { background: #f8fafc; }
.mode-radio__label { font-size: 0.875rem; color: #1f2937; font-weight: 500; }
.mode-radio__hint {
    margin: 4px 0 0 0;
    font-size: 0.78rem;
    color: #6A6D70;
    line-height: 1.4;
}

.imp-alert { margin-bottom: 16px; }

/* Stats grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 14px;
}
.stat {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border-radius: 6px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
}
.stat :deep(.anticon) { font-size: 1.4rem; flex-shrink: 0; }
.stat__num { font-size: 1.15rem; font-weight: 700; color: #1f2937; line-height: 1; }
.stat__lbl { font-size: 0.72rem; color: #6A6D70; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.04em; }
.stat--ok   :deep(.anticon) { color: #1D7044; }
.stat--info :deep(.anticon) { color: #0A6ED1; }
.stat--mute :deep(.anticon) { color: #94a3b8; }
.stat--err  :deep(.anticon) { color: #C8281D; }
.stat--err  { background: #FFE5E2; border-color: #FFB5AD; }
.stat--err  .stat__num { color: #C8281D; }

/* Preview tables */
.preview-block { margin-top: 14px; }
.preview-block__title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #32363A;
    margin: 0 0 8px 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Footer */
.imp-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

@media (max-width: 600px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<style>
/* Dark mode (no scoped: el modal portea fuera) */
html[data-theme="dark"] .import-dialog .imp-section__title { color: #a8aaae; }
html[data-theme="dark"] .import-dialog .imp-dragger {
    background: #2c3034 !important;
    border-color: #3f4448 !important;
}
html[data-theme="dark"] .import-dialog .imp-dragger:hover { border-color: #4db6e8 !important; }
html[data-theme="dark"] .import-dialog .imp-dragger__text { color: #e5e6e7; }
html[data-theme="dark"] .import-dialog .imp-dragger__hint { color: #a8aaae; }
html[data-theme="dark"] .import-dialog .imp-dragger__icon { color: #4db6e8; }
html[data-theme="dark"] .import-dialog .file-pill {
    background: rgba(77, 182, 232, 0.10);
    border-color: #4db6e8;
}
html[data-theme="dark"] .import-dialog .file-pill__name { color: #e5e6e7; }
html[data-theme="dark"] .import-dialog .mode-radio:hover { background: #313a44; }
html[data-theme="dark"] .import-dialog .mode-radio__label { color: #e5e6e7; }
html[data-theme="dark"] .import-dialog .mode-radio__hint  { color: #a8aaae; }
html[data-theme="dark"] .import-dialog .stat {
    background: #2c3034;
    border-color: #3f4448;
}
html[data-theme="dark"] .import-dialog .stat__num { color: #e5e6e7; }
html[data-theme="dark"] .import-dialog .preview-block__title { color: #e5e6e7; }
html[data-theme="dark"] .import-dialog .imp-footer { border-top-color: #3f4448; }
</style>
