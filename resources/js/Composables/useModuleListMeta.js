import { computed } from 'vue';
import { useRecentRowHighlight } from './useRecentRowHighlight';
import { useAnimatedNumber } from './useAnimatedNumber';

/**
 * useModuleListMeta — agrupa los dos efectos de UX del Index de cada módulo:
 *   1. row highlight verde de 2s en filas recién creadas/restauradas
 *   2. counter animado "X de Y" con tween easeOutCubic
 *
 * Cualquier cambio al patrón (animación, mensaje, fallback) vive aquí.
 *
 * Uso:
 *   const { isHighlighted, counterLabel } = useModuleListMeta({
 *       pagination: computed(() => props.regions),
 *       hasActiveFilters,
 *       t,
 *   });
 *
 *   <ResponsiveTable :row-class-name="(r) => isHighlighted(r.id) ? 'row-highlight' : ''" />
 *   <PageHeader :counter-label="counterLabel" />
 */
export function useModuleListMeta({ pagination, hasActiveFilters, t }) {
    const { isHighlighted } = useRecentRowHighlight();

    const animatedTotal      = useAnimatedNumber(computed(() => pagination.value?.total ?? 0));
    const animatedUnfiltered = useAnimatedNumber(computed(() => pagination.value?.total_unfiltered ?? pagination.value?.total ?? 0));

    const counterLabel = computed(() => {
        const realTotal      = pagination.value?.total ?? 0;
        const realUnfiltered = pagination.value?.total_unfiltered ?? realTotal;
        const word           = realTotal === 1 ? t('global.record') : t('global.records');
        // Separador de miles: "1,905 de 2,424" se lee; "1905 de 2424" no.
        const fmt = (v) => Number(v).toLocaleString();
        return (hasActiveFilters.value && realTotal !== realUnfiltered)
            ? `${fmt(animatedTotal.value)} ${t('global.of')} ${fmt(animatedUnfiltered.value)} ${word}`
            : `${fmt(animatedTotal.value)} ${word}`;
    });

    return { isHighlighted, counterLabel };
}
