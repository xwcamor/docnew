import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * usePlanFeatures — chequea features del plan del tenant del user actual.
 *
 * Las features vienen de auth.user.plan_features (shared por
 * HandleInertiaRequests). super tiene `__all__: true` como sentinela
 * para indicar "puede todo, sin gating".
 *
 * Uso:
 *   const { canUse } = usePlanFeatures();
 *   if (canUse('automations')) { ... }
 *
 *   <SidebarItem v-if="canUse('automations')" ... />
 */
export function usePlanFeatures() {
    const page = usePage();

    const features = computed(() =>
        page.props.auth?.user?.plan_features ?? {}
    );

    const canUse = (key) => {
        const f = features.value;
        return !!(f.__all__ || f[key]);
    };

    return { canUse, features };
}
