import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

/**
 * Tracks window.innerWidth y expone `isMobile` (< breakpoint). Reusable cross-módulo
 * para drawers full-width en mobile, layout switches, etc.
 */
export function useViewport(breakpoint = 768) {
    const width = ref(typeof window !== 'undefined' ? window.innerWidth : 1024);
    const onResize = () => { width.value = window.innerWidth; };

    onMounted(() => window.addEventListener('resize', onResize));
    onBeforeUnmount(() => window.removeEventListener('resize', onResize));

    const isMobile = computed(() => width.value < breakpoint);
    // `width` queda como local — los callers solo usan `isMobile`. Si en el
    // futuro un componente necesita el width exacto, se exporta puntual.
    return { isMobile };
}
