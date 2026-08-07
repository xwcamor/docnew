import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Modal } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * Restore (individual + bulk) para módulos con soft-delete trash.
 *
 * @param {object} opts
 * @param {string} opts.restoreRouteName     - route name del restore individual (recibe el slug)
 * @param {string} opts.bulkRestoreRouteName - route name del bulk_restore (recibe ids[])
 */
export function useModuleRestore({ restoreRouteName, bulkRestoreRouteName }) {
    const { t } = useI18n();

    const restoring = ref(null);
    const restore = (record) => {
        restoring.value = record.id;
        router.post(route(restoreRouteName, record.slug), {}, {
            preserveScroll: true,
            onFinish: () => { restoring.value = null; },
        });
    };

    const selectedRowKeys = ref([]);
    const rowSelection = computed(() => ({
        selectedRowKeys: selectedRowKeys.value,
        onChange: (keys) => { selectedRowKeys.value = keys; },
    }));
    const clearSelection = () => { selectedRowKeys.value = []; };

    const bulkRestoring = ref(false);
    const bulkRestore = () => {
        if (selectedRowKeys.value.length === 0) return;
        Modal.confirm({
            title: `${t('global.bulk_restore')} (${selectedRowKeys.value.length})?`,
            okText: t('global.restore'),
            cancelText: t('global.cancel'),
            onOk: () => new Promise((resolve, reject) => {
                bulkRestoring.value = true;
                router.post(route(bulkRestoreRouteName), { ids: selectedRowKeys.value }, {
                    preserveScroll: true,
                    onSuccess: () => { clearSelection(); resolve(); },
                    onError:   () => reject(),
                    onFinish:  () => { bulkRestoring.value = false; },
                });
            }),
        });
    };

    return {
        restoring, restore,
        selectedRowKeys, rowSelection, clearSelection,
        bulkRestoring, bulkRestore,
    };
}
