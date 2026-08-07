import { onMounted, onBeforeUnmount } from 'vue';

/**
 * useKeyboardShortcuts — composable reusable para atajos de teclado.
 *
 * Usage:
 *   useKeyboardShortcuts({
 *       'ctrl+n': () => router.visit(route('regions.create')),
 *       'esc':    () => modalOpen.value = false,
 *       'ctrl+k': () => searchInput.value?.focus(),
 *   });
 *
 * Detalles:
 *   - Soporta combos: 'ctrl', 'shift', 'alt', 'meta' (Cmd en Mac)
 *   - 'ctrl+...' matchea TANTO Ctrl en Win/Linux COMO Cmd en Mac (UX consistente)
 *   - Skip cuando el foco está en un input editable (text/textarea/select/contenteditable)
 *     EXCEPTO para Esc (esc siempre dispara, incluso desde inputs — útil para cerrar modales)
 *   - Cleanup automático en onBeforeUnmount
 */

export function useKeyboardShortcuts(map) {
    const handler = (event) => {
        const combo = comboFromEvent(event);
        if (!combo) return;

        const fn = map[combo];
        if (!fn) return;

        // Skip si el foco está en input editable, excepto para Esc.
        if (combo !== 'esc' && isEditableTarget(event.target)) return;

        event.preventDefault();
        fn(event);
    };

    onMounted(() => window.addEventListener('keydown', handler));
    onBeforeUnmount(() => window.removeEventListener('keydown', handler));
}

/** Convierte un KeyboardEvent en un string normalizado tipo "ctrl+n", "esc", etc. */
function comboFromEvent(event) {
    const parts = [];
    // Ctrl o Cmd cuentan como 'ctrl' (cross-platform UX).
    if (event.ctrlKey || event.metaKey) parts.push('ctrl');
    if (event.shiftKey) parts.push('shift');
    if (event.altKey)   parts.push('alt');

    let key = (event.key || '').toLowerCase();
    if (key === ' ') key = 'space';
    if (key === 'escape') key = 'esc';
    if (key.length === 0 || ['control', 'meta', 'shift', 'alt'].includes(key)) return null;

    parts.push(key);
    return parts.join('+');
}

/** ¿El target es un input editable? Si sí, no atrapamos el atajo. */
function isEditableTarget(el) {
    if (!el) return false;
    const tag = el.tagName?.toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
    if (el.isContentEditable) return true;
    return false;
}
