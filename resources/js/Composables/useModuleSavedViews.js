import { computed } from 'vue';

/**
 * Cableado de Saved Views para un módulo: serializa el state actual (filters
 * + visibleColumns + sort + direction + perPage) y aplica state guardado.
 * Genérico — depende solo de los refs del módulo y dos serializadores.
 *
 * @param {object} opts
 * @param {Ref<object>} opts.filters             - ref del form de filtros local
 * @param {Ref<string[]>} opts.visibleColumnKeys - keys de columnas visibles
 * @param {ComputedRef<object[]>} opts.allColumns - lista total de columnas (para defaults)
 * @param {object} opts.serverFilters            - props.filters (sort/dir/per_page actuales)
 * @param {Function} opts.serialize              - (filters) => JSON-safe object
 * @param {Function} opts.deserialize            - (state.filters) => filters local
 * @param {Function} opts.clearFilters           - reset al estado vacío
 * @param {Function} opts.reload                 - reload de Inertia con extra params
 * @param {object} [opts.defaults]               - sort/direction/perPage default
 */
export function useModuleSavedViews({
    filters,
    visibleColumnKeys,
    allColumns,
    serverFilters,
    serialize,
    deserialize,
    clearFilters,
    reload,
    advancedWhere = null,        // Ref opcional: condiciones del builder inline (árbol de reglas)
    advancedConjunction = null,  // Ref opcional: conector raíz del builder ('and'|'or')
    applyWithAdvanced = null,    // (clauses, {sort,direction,perPage}) => void — navega con advanced_where
    suspendReload = null,        // opcional: muta `filters` sin disparar el watch (evita doble navegación)
    defaults = { sort: 'id', direction: 'desc', perPage: 10 },
}) {
    // Si el módulo pasó suspendReload, mutamos filtros sin que el watch lance un
    // reload paralelo; la navegación la hace SOLO applyWithAdvanced/reload de aquí.
    const mutate = (fn) => (suspendReload ? suspendReload(fn) : fn());
    const currentViewState = computed(() => ({
        filters:        serialize(filters.value),
        visibleColumns: visibleColumnKeys.value,
        sort:           serverFilters.sort      ?? defaults.sort,
        direction:      serverFilters.direction ?? defaults.direction,
        perPage:        serverFilters.per_page  ?? defaults.perPage,
        // Solo si el módulo usa filtros avanzados (builder inline).
        ...(advancedWhere ? { advancedWhere: advancedWhere.value ?? [] } : {}),
        // El conector raíz (Y/O) también se persiste — sin esto, una vista guardada
        // con "O" volvía a "Y" al reabrirla (bug del save).
        ...(advancedConjunction ? { advancedConjunction: advancedConjunction.value ?? 'and' } : {}),
    }));

    const applySavedState = (state) => {
        if (!state) {
            // "Vista por defecto": limpia filtros, columnas visibles del default,
            // sort/per_page al inicio.
            mutate(() => clearFilters());
            if (advancedWhere) advancedWhere.value = [];
            if (advancedConjunction) advancedConjunction.value = 'and';
            visibleColumnKeys.value = allColumns.value
                .filter((c) => !c.defaultHidden)
                .map((c) => c.key);
            const meta = { sort: defaults.sort, direction: defaults.direction, perPage: defaults.perPage };
            if (advancedWhere && applyWithAdvanced) applyWithAdvanced([], meta);
            else reload({ sort: meta.sort, direction: meta.direction, per_page: meta.perPage });
            return;
        }

        mutate(() => { filters.value = deserialize(state.filters); });

        if (Array.isArray(state.visibleColumns) && state.visibleColumns.length) {
            visibleColumnKeys.value = state.visibleColumns.filter(
                (k) => allColumns.value.some((c) => c.key === k),
            );
        }

        const meta = {
            sort:      state.sort      ?? defaults.sort,
            direction: state.direction ?? defaults.direction,
            perPage:   state.perPage   ?? defaults.perPage,
        };

        // Si el módulo tiene builder avanzado, restaurar sus condiciones y navegar
        // con advanced_where en UNA sola request (incluye también los simples).
        if (advancedWhere && applyWithAdvanced) {
            const adv = Array.isArray(state.advancedWhere) ? state.advancedWhere : [];
            advancedWhere.value = adv;
            // Restaurar el conector ANTES de navegar (applyWithAdvanced lo lee).
            if (advancedConjunction) advancedConjunction.value = state.advancedConjunction === 'or' ? 'or' : 'and';
            applyWithAdvanced(adv, meta);
            return;
        }

        reload({ sort: meta.sort, direction: meta.direction, per_page: meta.perPage });
    };

    return { currentViewState, applySavedState };
}
