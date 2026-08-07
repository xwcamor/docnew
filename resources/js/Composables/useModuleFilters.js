import { ref, computed, watch, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import dayjs from 'dayjs';

/**
 * Genérico para listados con filtros + paginación + sort + saved-views.
 *
 * Cada módulo declara:
 *   - `serverFilters`: props.filters (lo que vino del backend)
 *   - `hydrate`:  (serverFilters) => filtersLocal  — backend ⇒ form local
 *   - `toQuery`:  (filtersLocal, serverFilters) => queryParams — form local ⇒ request
 *   - `summary`:  (filtersLocal, t) => 'human readable' — para PDF/Word
 *   - `empty`:    () => filtersLocal default — para clearFilters / "vista por defecto"
 *   - `only`:     keys del partial reload (ej. ['regions', 'filters'])
 *
 * Acepta un t opcional para el summary (i18n).
 */
export function useModuleFilters({ serverFilters, hydrate, toQuery, summary, empty, only, t }) {
    const filters = ref(hydrate(serverFilters));

    // Flag de carga: true mientras el request del listado está en vuelo. Lo
    // consume el `:loading` de ResponsiveTable para el overlay "buscando".
    const isFetching = ref(false);

    const reload = (extra = {}) => {
        router.reload({
            only,
            data: { ...toQuery(filters.value, serverFilters), page: 1, ...extra },
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => { isFetching.value = true; },
            onFinish: () => { isFetching.value = false; },
        });
    };

    // Auto-reload cada vez que cambia un filtro (deep), con debounce: el buscador
    // inline escribe letra por letra y sin esto dispararía un request por tecla.
    // 300ms agrupa la ráfaga en un solo request (sort/paginate van por reload()
    // directo en onTableChange, sin pasar por aquí → siguen instantáneos).
    //
    // `suspended` permite mutar `filters` sin disparar el watch (lo usan las
    // vistas guardadas: aplican filtros + navegan en UNA sola request; sin esto
    // el watch lanzaba un segundo reload que competía y revertía la vista).
    let debounceTimer = null;
    let suspended = false;
    const suspendReload = (mutator) => {
        suspended = true;
        mutator();
        nextTick(() => { suspended = false; });
    };
    watch(filters, () => {
        if (suspended) return;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => reload(), 300);
    }, { deep: true });

    // Genérico: "filtro activo" = valor no nulo, no array vacío, no false (toggles).
    const hasActiveFilters = computed(() => {
        const f = filters.value;
        return Object.values(f).some((v) => {
            if (v === null || v === undefined || v === '' || v === false) return false;
            if (Array.isArray(v)) return v.some(x => x !== null && x !== undefined);
            return true;
        });
    });

    const clearFilters = () => { filters.value = empty(); };

    const filtersSummary = computed(() => summary ? summary(filters.value, t) : '');

    return {
        filters,
        reload,
        isFetching,
        suspendReload,
        hasActiveFilters,
        clearFilters,
        filtersSummary,
        // Helpers para que el caller serialice el state actual a request params.
        // Incluye el sort/direction actual (de serverFilters) como base para que
        // el EXPORT respete el orden de la vista; la navegación (onTableChange)
        // pasa su propio sort en `extra` y lo sobreescribe.
        buildQueryData: (extra = {}) => ({
            ...toQuery(filters.value, serverFilters),
            page: 1,
            ...(serverFilters?.sort ? { sort: serverFilters.sort, direction: serverFilters.direction ?? 'asc' } : {}),
            ...extra,
        }),
    };
}

/**
 * Helper para serializar/deserializar rangos de fechas (dayjs ↔ ISO string)
 * usado tanto en hydrate como en saved-views.
 */
export const dateRangeFromISO = (from, to) =>
    (from && to) ? [dayjs(from), dayjs(to)] : null;

export const dateRangeToISO = (range) =>
    range?.[0] ? [range[0].format('YYYY-MM-DD'), range[1]?.format('YYYY-MM-DD')] : null;
