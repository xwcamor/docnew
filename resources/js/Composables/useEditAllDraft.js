import { ref, computed, watch } from 'vue';

/**
 * Tracking de cambios pendientes para una tabla editable (patrón SAP "edit-all").
 * Mantiene snapshot original + draft mutable; deriva isDirty, dirtyChanges,
 * duplicateRows (intra-batch para campos únicos como `name`).
 *
 * @param {object} opts
 * @param {Array<object>} opts.source         - filas que vienen del server (props.regions.data)
 * @param {Array<string>} opts.editableFields - keys que el form deja editar (afectan isDirty)
 * @param {string|null}   opts.uniqueField    - key con unique constraint a verificar intra-batch (ej. 'name')
 */
export function useEditAllDraft({ source, editableFields, uniqueField = null }) {
    const cloneRows = (rows) => rows.map((r) => ({ ...r }));

    const original = ref(cloneRows(source.value ?? []));
    const draft    = ref(cloneRows(source.value ?? []));

    // Cuando cambia la página o el filtro, Inertia re-renderiza con nuevos props.
    // Re-inicializamos snapshots para descartar cualquier draft anterior.
    watch(source, (rows) => {
        original.value = cloneRows(rows ?? []);
        draft.value    = cloneRows(rows ?? []);
    });

    const isDirty = (idx) => {
        const o = original.value[idx];
        const d = draft.value[idx];
        if (!o || !d) return false;
        return editableFields.some((k) => o[k] !== d[k]);
    };

    const dirtyCount = computed(() =>
        draft.value.filter((_, i) => isDirty(i)).length
    );

    const dirtyChanges = computed(() => {
        const out = [];
        draft.value.forEach((d, i) => {
            if (!isDirty(i)) return;
            const change = { id: d.id };
            editableFields.forEach((k) => { change[k] = d[k]; });
            out.push(change);
        });
        return out;
    });

    // Detecta duplicados intra-batch sobre `uniqueField` (case-insensitive + sin
    // tildes). El backend chequea contra DB; esto es validación cliente para
    // dar feedback antes del submit.
    const normalize = (n) => {
        if (!n) return '';
        return String(n).toLowerCase().trim()
            .normalize('NFD').replace(/[̀-ͯ]/g, '');
    };

    const duplicateRows = computed(() => {
        if (!uniqueField) return new Set();
        const seen = new Map();
        const dupes = new Set();
        draft.value.forEach((r, i) => {
            const k = normalize(r[uniqueField]);
            if (!k) return;
            if (seen.has(k) && seen.get(k) !== i) {
                dupes.add(i);
                dupes.add(seen.get(k));
            } else {
                seen.set(k, i);
            }
        });
        return dupes;
    });

    const discardAll = () => {
        draft.value = cloneRows(original.value);
    };

    return { original, draft, isDirty, dirtyCount, dirtyChanges, duplicateRows, discardAll };
}
