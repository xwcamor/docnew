import { ref, watch, h } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { notification } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * Toast "Deshacer (Ns)" reusable. Watch del flash.recentDelete del controller;
 * dispara undo route con preserveScroll. Reusable cross-módulo.
 *
 * @param {string} undoRouteName - route name del undo (ej. 'system_management.regions.undo_last_delete')
 */
export function useModuleUndoToast(undoRouteName) {
    const page = usePage();
    const { t } = useI18n();
    const submitting = ref(false);

    const trigger = (notifKey) => {
        if (submitting.value) return;
        submitting.value = true;
        router.post(route(undoRouteName), {}, {
            preserveScroll: true,
            onSuccess: () => { notification.close(notifKey); },
            onFinish:  () => { submitting.value = false; },
        });
    };

    watch(
        () => page.props.flash?.recentDelete,
        (rd) => {
            if (!rd || !rd.count) return;
            const key = `module-undo-${Date.now()}`;
            notification.info({
                key,
                message: t('global.deleted_undoable', { seconds: rd.seconds }),
                description: '',
                placement: 'bottomRight',
                duration: rd.seconds,
                btn: () => h('button', {
                    onClick: () => trigger(key),
                    style: 'background:var(--color-primary);color:var(--color-text-on-dark);border:0;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:500;font-size:0.8rem;',
                }, t('global.undo')),
            });
        },
        { immediate: true },
    );

    return { submitting };
}
