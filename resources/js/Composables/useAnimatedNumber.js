import { ref, watch } from 'vue';

/**
 * useAnimatedNumber(reactiveTarget, durationMs = 350)
 *
 * Devuelve un ref que sigue al target con animación tween easeOut. Pensado
 * para el counter "X de Y" — cuando cambia el total filtrado, el número
 * cuenta hacia arriba/abajo en ~350ms en lugar de saltar de golpe.
 *
 * Uso:
 *   const totalAnim = useAnimatedNumber(computed(() => props.regions.total));
 *   {{ totalAnim }} de {{ totalUnfilteredAnim }}
 */
export function useAnimatedNumber(targetRef, duration = 350) {
    const current = ref(Number(targetRef.value) || 0);

    watch(targetRef, (next, prev) => {
        const start = Number(prev) || 0;
        const end   = Number(next) || 0;
        if (start === end) { current.value = end; return; }

        const startTs = performance.now();
        const step = (now) => {
            const elapsed = now - startTs;
            const t = Math.min(elapsed / duration, 1);
            // easeOutCubic
            const eased = 1 - Math.pow(1 - t, 3);
            current.value = Math.round(start + (end - start) * eased);
            if (t < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    });

    return current;
}
