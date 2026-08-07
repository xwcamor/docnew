import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

/**
 * useModuleFavorites — toggle de favoritos polimórfico para cualquier módulo.
 *
 * Patrón optimista: mutamos `record.is_favorite` localmente, hacemos POST al
 * backend, y si falla revertimos. Tras éxito recargamos la lista para que
 * el orden (favoritos primero) se aplique visualmente.
 *
 * IMPORTANTE: `submitting` se libera SOLO cuando el reload termina (no al
 * terminar el POST). Si lo libera antes, el usuario puede clickear el mismo
 * record otra vez mientras el reload anterior está en flight, generando
 * race conditions ("a veces funciona, a veces no").
 *
 * @param {string} module - slug del módulo (regions, languages, patients, etc.)
 * @param {string} reloadKey - prop de Inertia a recargar (ej. 'regions')
 * @returns { submitting (ref|null), toggle (fn) }
 */
export function useModuleFavorites(module, reloadKey) {
    const submitting = ref(null);

    const toggle = async (record) => {
        if (submitting.value === record.id) return;
        submitting.value = record.id;
        const previous = record.is_favorite;
        record.is_favorite = !previous;
        try {
            await axios.post(route('user_prefs.favorites.toggle'), {
                module,
                id: record.id,
            });
            // Esperamos a que el reload termine también — sin esto, submitting
            // se libera mientras el reload aún corre y un segundo click rápido
            // race-conditiona con el reload en flight.
            await new Promise((resolve) => {
                router.reload({
                    preserveScroll: true,
                    only: [reloadKey],
                    onFinish: resolve,
                });
            });
        } catch (_) {
            record.is_favorite = previous;
        } finally {
            submitting.value = null;
        }
    };

    return { submitting, toggle };
}
