import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Modal } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * Bulk actions reusables (select, set-active, delete con motivo) cross-módulo.
 *
 * @param {object} opts
 * @param {string} opts.bulkSetActiveRoute - route name del bulk_set_active
 * @param {string} opts.bulkDeleteRoute    - route name del bulk_delete
 * @param {string} opts.resourceLabel      - label plural del recurso (p.ej. t('regions.records'))
 */
export function useModuleBulkActions({ bulkSetActiveRoute, bulkDeleteRoute, resourceLabel, rowDisabled = null }) {
    const { t } = useI18n();

    const selectedRowKeys = ref([]);
    const bulkOpen        = ref(false);
    const bulkReason      = ref('');
    const bulkSubmitting  = ref(false);
    const bulkError       = ref('');
    const bulkActivating  = ref(false);

    const clearSelection = () => { selectedRowKeys.value = []; };

    const rowSelection = computed(() => ({
        selectedRowKeys: selectedRowKeys.value,
        onChange: (keys) => { selectedRowKeys.value = keys; },
        // Permite deshabilitar el checkbox de filas no gestionables (p.ej. los
        // registros globales para un admin: solo el super los toca).
        ...(rowDisabled ? { getCheckboxProps: (record) => ({ disabled: !!rowDisabled(record) }) } : {}),
    }));

    const openBulkDelete = () => {
        if (selectedRowKeys.value.length === 0) return;
        bulkReason.value = '';
        bulkError.value = '';
        bulkOpen.value = true;
    };

    const bulkSetActive = (isActive) => {
        if (selectedRowKeys.value.length === 0) return;
        const count = selectedRowKeys.value.length;
        Modal.confirm({
            title: t(isActive ? 'global.bulk_activate_confirm' : 'global.bulk_deactivate_confirm', {
                count,
                resource: resourceLabel,
            }),
            okText: isActive ? t('global.bulk_activate') : t('global.bulk_deactivate'),
            cancelText: t('global.cancel'),
            onOk: () => new Promise((resolve, reject) => {
                bulkActivating.value = true;
                router.post(
                    route(bulkSetActiveRoute),
                    { ids: selectedRowKeys.value, is_active: isActive },
                    {
                        preserveScroll: true,
                        onSuccess: () => { clearSelection(); resolve(); },
                        onError: () => reject(),
                        onFinish: () => { bulkActivating.value = false; },
                    },
                );
            }),
        });
    };

    const confirmBulkDelete = () => {
        if (!bulkReason.value.trim() || bulkReason.value.trim().length < 3) {
            bulkError.value = t('global.delete_reason_min_3');
            return;
        }
        bulkSubmitting.value = true;
        router.post(
            route(bulkDeleteRoute),
            { ids: selectedRowKeys.value, deleted_description: bulkReason.value.trim() },
            {
                preserveScroll: true,
                onSuccess: () => { bulkOpen.value = false; clearSelection(); },
                onError: (errs) => { bulkError.value = errs.deleted_description || errs.ids || t('global.bulk_delete_failed'); },
                onFinish: () => { bulkSubmitting.value = false; },
            },
        );
    };

    return {
        selectedRowKeys, rowSelection, clearSelection,
        bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
        openBulkDelete, bulkSetActive, confirmBulkDelete,
    };
}
