import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * useRecentRowHighlight — flash visual de 2s en rows recientemente
 * creados/actualizados/restaurados. Lee el ID del registro de los flash
 * de session (los controllers ya los flushean en `with('success', ...)`).
 *
 * Uso:
 *   <tr :class="{ 'row-highlight': isHighlighted(row.id) }">
 *
 * El CSS de .row-highlight vive en Pages/Regions/Index.vue (scoped) o en
 * global.css si se reusa. Patrón:
 *   .row-highlight { animation: row-pulse 2s ease-out; }
 *   @keyframes row-pulse { from { background: rgba(82, 196, 26, 0.18); } }
 */
export function useRecentRowHighlight() {
    const recentIds = ref(new Set());
    const page = usePage();

    // Cualquier flash success/recentDelete trae un ID que destacamos por 2s.
    watch(() => page.props.flash, (flash) => {
        if (!flash) return;

        const ids = [];
        // Inertia controllers pueden flashear recent_id / restored_ids / created_id.
        if (flash.recent_id)    ids.push(flash.recent_id);
        if (flash.created_id)   ids.push(flash.created_id);
        if (Array.isArray(flash.restored_ids)) ids.push(...flash.restored_ids);

        if (ids.length === 0) return;

        for (const id of ids) recentIds.value.add(id);

        setTimeout(() => {
            for (const id of ids) recentIds.value.delete(id);
            recentIds.value = new Set(recentIds.value); // trigger reactivity
        }, 2000);
    }, { immediate: true, deep: true });

    const isHighlighted = (id) => recentIds.value.has(id);

    return { isHighlighted };
}
