<script setup>
/**
 * Tabla editable en línea. El nombre de la firma, el rol y el tipo van como
 * texto: identifican la fila, no se editan aquí. Lo editable es el nivel (el
 * orden de firma) y los dos interruptores.
 *
 * Una fila BLOQUEADA (Lockable) sale deshabilitada y con el candado a la vista.
 * El servidor ya la aparta; dejar los controles vivos solo servía para que el
 * usuario reordenara tres reglas y el guardado le contestara que no. Lo mismo
 * con una regla GLOBAL (workspace = Plataforma) vista por quien no es super.
 */
import { Tag, Tooltip, InputNumber, Switch } from 'ant-design-vue';
import { LockOutlined } from '@ant-design/icons-vue';

const props = defineProps({
    isDirty:       { type: Function, required: true },
    duplicateRows: { type: Set,      required: true },
    isSuper:       { type: Boolean,  default: false },
});

const draft = defineModel('draft', { type: Array, required: true });

const isLocked = (row) => !!row.locked_at;
const isGlobal = (row) => row.tenant_id === null || row.tenant_id === undefined;
const bloqueada = (row) => isLocked(row) || (!props.isSuper && isGlobal(row));
</script>

<template>
    <table v-if="draft.length > 0" class="edit-table">
        <thead>
            <tr>
                <th class="col-cod">{{ $t('approval_rules.name') }}</th>
                <th class="col-type">{{ $t('approval_rules.work_type') }}</th>
                <th class="col-level">{{ $t('approval_rules.table_headers.editable_level') }}</th>
                <th class="col-req">{{ $t('approval_rules.table_headers.editable_required') }}</th>
                <th class="col-status">{{ $t('approval_rules.table_headers.editable_status') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="(row, i) in draft"
                :key="row.id"
                :class="{
                    'is-dirty':     props.isDirty(i),
                    'is-duplicate': duplicateRows.has(i),
                    'is-locked':    bloqueada(row),
                }"
            >
                <td class="col-cod">
                    <div class="cell-name">
                        <strong>{{ row.display_name }}</strong>
                        <Tooltip v-if="isLocked(row)" :title="$t('locks.locked_hint')">
                            <Tag color="gold" :bordered="false"><LockOutlined /> {{ $t('locks.locked_tag') }}</Tag>
                        </Tooltip>
                        <!-- Apagada por ser de la Plataforma: se dice cuál de
                             las dos razones es, no solo que está apagada. -->
                        <Tag v-else-if="bloqueada(row)" color="purple" :bordered="false">{{ $t('global.platform') }}</Tag>
                    </div>
                    <span class="muted">{{ row.approver_role_label }}</span>
                </td>
                <td class="col-type">
                    <span v-if="row.work_type">{{ row.work_type.code }}</span>
                    <span v-else class="muted">{{ $t('approval_rules.all_work_types') }}</span>
                </td>
                <td class="col-level">
                    <InputNumber
                        v-model:value="row.priority_level"
                        :min="1"
                        :max="20"
                        :disabled="bloqueada(row)"
                        :status="(duplicateRows.has(i) || props.isDirty(i)) ? 'warning' : ''"
                        size="large"
                        style="width: 100px"
                    />
                </td>
                <td class="col-req">
                    <Switch
                        v-model:checked="row.is_required"
                        :disabled="bloqueada(row)"
                        :checked-children="$t('approval_rules.required')"
                        :un-checked-children="$t('approval_rules.optional')"
                    />
                </td>
                <td class="col-status">
                    <Switch
                        v-model:checked="row.is_active"
                        :disabled="bloqueada(row)"
                        :checked-children="$t('global.active')"
                        :un-checked-children="$t('global.inactive')"
                    />
                </td>
            </tr>
        </tbody>
    </table>

    <div v-else class="empty">
        {{ $t('approval_rules.edit_all_no_results') }}
    </div>
</template>

<style scoped>
.edit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.edit-table thead th {
    background: var(--color-surface-alt);
    color: var(--color-text-strong);
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    text-align: left;
    padding: 12px 14px;
    border-bottom: 1px solid var(--color-border);
}
.edit-table tbody td {
    padding: 8px 14px;
    border-bottom: 1px solid var(--color-border-soft);
    vertical-align: middle;
}
.edit-table tbody tr:last-child td { border-bottom: 0; }
.edit-table .col-cod    { width: 280px; }
.edit-table .col-type   { width: 160px; }
.edit-table .col-level  { width: 140px; }
.edit-table .col-req    { width: 170px; }
.edit-table .col-status { width: 160px; }
.edit-table tbody tr.is-dirty     { background: var(--tint-dirty); }
.edit-table tbody tr.is-duplicate { background: var(--tint-duplicate); }
.edit-table tbody tr.is-locked    { opacity: 0.72; }
.cell-name { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.muted { color: var(--color-text-muted); }

.empty {
    padding: 48px 16px;
    text-align: center;
    color: var(--color-text-muted);
    font-size: 0.9rem;
}

/* Tablet: se oculta el tipo de trabajo antes que nada — el nombre y el nivel
   son lo que se está reordenando. */
@media (max-width: 1024px) {
    .edit-table .col-type { display: none; }
    .edit-table thead th:nth-child(2),
    .edit-table tbody td:nth-child(2) { display: none; }
}
</style>
