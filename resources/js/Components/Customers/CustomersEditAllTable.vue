<script setup>
/**
 * Tabla editable in-line del flujo Edit-All de Customers. Recibe `draft` por
 * v-model y predicados isDirty/isDuplicate del composable useEditAllDraft.
 */
import { Input, Switch } from 'ant-design-vue';

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
                <th class="col-id">ID</th>
                <th class="col-name">{{ $t('customers.table_headers.editable_name') }}</th>
                <th class="col-cod">{{ $t('customers.cod') }}</th>
                <th class="col-status">{{ $t('customers.table_headers.editable_status') }}</th>
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
                <td class="col-id">{{ row.id }}</td>
                <td class="col-name">
                    <Input
                        v-model:value="row.name"
                        :status="duplicateRows.has(i) ? 'error' : (props.isDirty(i) ? 'warning' : '')"
                        size="small"
                    />
                </td>
                <td class="col-cod">
                    <code v-if="row.cod">{{ row.cod }}</code>
                    <span v-else class="muted">—</span>
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
        {{ $t('customers.edit_all_no_results') }}
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
.edit-table .col-id     { width: 80px;  color: var(--color-text-muted); }
.edit-table .col-cod    { width: 150px; font-family: ui-monospace, Consolas, monospace; font-size: 0.8125rem; }
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

@media (max-width: 768px) {
    .edit-table .col-id, .edit-table .col-cod { display: none; }
    .edit-table thead th:first-child,
    .edit-table tbody td:first-child { display: none; }
}
</style>
