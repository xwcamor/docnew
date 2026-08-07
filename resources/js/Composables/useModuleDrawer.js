import { ref } from 'vue';
import axios from 'axios';

/**
 * Drawer de detalle reusable: estado open/selected + tracking de "recent views"
 * fire-and-forget al abrirlo. Cross-módulo.
 *
 * @param {object} opts
 * @param {string} opts.module - identificador del módulo para recent_views.track (ej. 'regions')
 */
export function useModuleDrawer({ module }) {
    const open     = ref(false);
    const selected = ref(null);

    const openDetails = (record) => {
        selected.value = record;
        open.value = true;

        // Track recent view fire-and-forget: el drawer es la forma principal de
        // "ver" un registro; sin esto el dropdown "Recientes" del avatar solo
        // captura visitas a /{module}/{slug}/show.
        axios.post(route('user_prefs.recent_views.track'), {
            module,
            id: record.id,
        }).catch(() => {});
    };

    return { open, selected, openDetails };
}
