import { ref, computed } from 'vue';

/**
 * useColumnPreferences — encapsula el patrón de visibilidad + orden de
 * columnas que usan los Index pages junto con ColumnSelector.
 *
 * Uso:
 *   const allColumns = computed(() => regionsTableColumns(t));
 *   const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);
 *
 *   // En el template:
 *   <ColumnSelector :columns="allColumns" v-model="visibleColumnKeys" storage-key="regions" />
 *   <ResponsiveTable :columns="columns" ... />
 *
 * El composable garantiza:
 *   - Visibilidad inicial: todas las columnas excepto las marcadas con
 *     `defaultHidden: true` (se activan desde el selector).
 *   - Orden: el computed `columns` respeta el orden de `visibleColumnKeys`
 *     (no el de allColumns) — necesario para que el drag-reorder del
 *     ColumnSelector se refleje en el render.
 *   - Filtra columnas que ya no existen en allColumns (defense in depth
 *     cuando cambian definiciones entre deploys y el localStorage queda viejo).
 *
 * @param {ComputedRef<Array>|Ref<Array>} allColumnsRef
 * @returns {{
 *   visibleColumnKeys: Ref<string[]>,
 *   columns: ComputedRef<Array>,
 * }}
 */
export function useColumnPreferences(allColumnsRef) {
    const allColumns = computed(() => allColumnsRef.value ?? []);

    const initialVisible = allColumns.value
        .filter(c => !c.defaultHidden)
        .map(c => c.key);

    const visibleColumnKeys = ref(initialVisible);

    const columns = computed(() =>
        visibleColumnKeys.value
            .map(key => allColumns.value.find(c => c.key === key))
            .filter(Boolean)
    );

    return { visibleColumnKeys, columns };
}
