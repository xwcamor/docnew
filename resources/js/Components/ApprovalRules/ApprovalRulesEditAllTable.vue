<script setup>
/**
 * Tabla editable en línea. El rol y el tipo van como texto: identifican la fila,
 * no se editan aquí. Lo editable es el nivel (el orden de firma) y los dos
 * interruptores.
 */
import { InputNumber, Switch } from 'ant-design-vue';

const props = defineProps({
    isDirty:       { type: Function, required: true },
    duplicateRows: { type: Set,      required: true },
});

const draft = defineModel('draft', { type: Array, required: true });
</script>

<template>
    <table v-if="draft.length > 0" class="edit-table">
        <thead>
            <tr>
                <th class="col-cod">{{ $t('approval_rules.approver_role') }}</th>
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
                }"
            >
                <td class="col-cod">
                    <strong>{{ row.approver_role_label }}</strong>
                    <br><code class="muted">{{ row.approver_role }}</code>
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
                        :status="props.isDirty(i) ? 'warning' : ''"
                        size="large"
                        style="width: 100px"
                    />
                </td>
                <td class="col-req">
                    <Switch
                        v-model:checked="row.is_required"
                        :checked-children="$t('approval_rules.required')"
                        :un-checked-children="$t('approval_rules.optional')"
                    />
                </td>
                <td class="col-status">
                    <Switch
                        v-model:checked="row.is_active"
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
.edit-table .col-cod    { width: 220px; }
.edit-table .col-type   { width: 160px; }
.edit-table .col-level  { width: 140px; }
.edit-table .col-req    { width: 170px; }
.edit-table .col-status { width: 160px; }
.edit-table tbody tr.is-dirty     { background: var(--tint-dirty); }
.edit-table tbody tr.is-duplicate { background: var(--tint-duplicate); }
.muted { color: var(--color-text-muted); }

.empty {
    padding: 48px 16px;
    text-align: center;
    color: var(--color-text-muted);
    font-size: 0.9rem;
}

/* Tablet: se oculta el tipo de trabajo antes que nada — el rol y el nivel son
   lo que se está reordenando. */
@media (max-width: 1024px) {
    .edit-table .col-type { display: none; }
    .edit-table thead th:nth-child(2),
    .edit-table tbody td:nth-child(2) { display: none; }
}
</style>
