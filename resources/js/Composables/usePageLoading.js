import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Watch de Inertia router para mostrar loading state mientras dura un visit
 * a una URL que matchea `pathMatch`. Reusable cross-módulo.
 *
 * `propKey` (opcional): si se especifica, el loading SOLO se activa cuando
 * el visit es a esta misma URL Y o es full reload (sin `only`) o el `only`
 * incluye este prop. Sin esto, el polling de otros shared props (ej. inbox
 * cada 4s en AppLayout) gatilla el loading aunque no esté re-fetcheando el
 * dataset principal de la página.
 *
 * @param {string} pathMatch - substring de URL.pathname para matchear
 * @param {string|null} propKey - prop específico que se está refrescando
 */
export function usePageLoading(pathMatch, propKey = null) {
    const loading = ref(false);

    router.on('start', (event) => {
        const visit = event.detail.visit;
        if (!visit.url.pathname.includes(pathMatch)) return;

        // Si especificamos propKey y el visit es partial (`only` set) a otros
        // props, no activamos loading — es un refresh de algo ajeno (inbox,
        // notifications, etc.).
        if (propKey && Array.isArray(visit.only) && visit.only.length > 0) {
            if (!visit.only.includes(propKey)) return;
        }

        loading.value = true;
    });

    router.on('finish', (event) => {
        // Mismo filtro al cerrar — solo bajamos loading si este finish
        // corresponde a un visit que SÍ activamos. Como Inertia procesa
        // visits secuencialmente y cancela los anteriores, el último finish
        // siempre baja loading. Pero filtramos por consistencia.
        const visit = event.detail.visit;
        if (!visit.url.pathname.includes(pathMatch)) return;
        if (propKey && Array.isArray(visit.only) && visit.only.length > 0) {
            if (!visit.only.includes(propKey)) return;
        }
        loading.value = false;
    });

    return { loading };
}
