import dayjs from 'dayjs';

/**
 * Schema de filtros del módulo WorkPlans.
 *
 * El campo libre se llama `search` y no `name` a propósito: un plan no tiene
 * nombre, se lo busca por código, orden de servicio o un pedazo de la
 * descripción del trabajo (el backend consulta las tres columnas).
 *
 * Las opciones de empresa / tipo de trabajo / sede las inyecta el controller.
 */
export const workPlansFilterFields = (t, {
    companyOptions = [], workTypeOptions = [], workLocationOptions = [],
} = {}) => [
    { key: 'search',           label: t('work_plans.filter_search'),  type: 'tags' },
    { key: 'company_id',       label: t('work_plans.company'),        type: 'multiselect', options: companyOptions },
    { key: 'work_type_id',     label: t('work_plans.work_type'),      type: 'multiselect', options: workTypeOptions },
    { key: 'work_location_id', label: t('work_plans.work_location'),  type: 'multiselect', options: workLocationOptions },
    { key: 'is_done',          label: t('work_plans.is_done'),        type: 'select', options: [
        { value: true,  label: t('work_plans.state_done')    },
        { value: false, label: t('work_plans.state_pending') },
    ]},
    // «Cerrado», no «Bloqueado»: es el `is_locked` de la v1, que significa que
    // el plan pasó a ser documento de archivo. El candado administrativo es
    // otra cosa y tiene su propia columna.
    { key: 'is_closed',        label: t('work_plans.is_closed'),      type: 'select', options: [
        { value: true,  label: t('work_plans.state_closed') },
        { value: false, label: t('work_plans.state_open')   },
    ]},
    // Reabiertos: los que alguien volvio a abrir para corregir algo. No es un
    // tercer estado —el plan sigue en curso— pero si el grupo que hay que poder
    // aislar: son los unicos cuyo documento cambio DESPUES de darse por
    // terminado, y eso es lo que hay que poder explicar.
    { key: 'reopened',         label: t('work_plans.filter_reopened'), type: 'select', options: [
        { value: true,  label: t('work_plans.state_reopened') },
        { value: false, label: t('work_plans.filter_never_reopened') },
    ]},
    { key: 'work_date',        label: t('work_plans.date_start'),     type: 'date_range' },
    { key: 'created_at',       label: t('global.created_at'),         type: 'date_range' },
    { key: 'only_favorites',   label: t('global.only_favorites'),     type: 'switch' },
];

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const workPlansEmptyFilters = () => ({
    search: [],
    company_id: [],
    work_type_id: [],
    work_location_id: [],
    is_done: null,
    is_closed: null,
    reopened: null,
    work_date: null,
    created_at: null,
    only_favorites: false,
});

/** Backend payload → form local (dates ISO → dayjs). */
export const hydrateWorkPlansFilters = (server) => ({
    search:           Array.isArray(server.search) ? server.search : [],
    company_id:       Array.isArray(server.company_id) ? server.company_id : [],
    work_type_id:     Array.isArray(server.work_type_id) ? server.work_type_id : [],
    work_location_id: Array.isArray(server.work_location_id) ? server.work_location_id : [],
    is_done:          server.is_done ?? null,
    is_closed:        server.is_closed ?? null,
    reopened:         server.reopened ?? null,
    work_date: (server.date_from && server.date_to)
        ? [dayjs(server.date_from), dayjs(server.date_to)]
        : null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
    only_favorites: server.only_favorites ?? false,
});

/** Form local → request params para Inertia reload. */
export const workPlansFiltersToQuery = (f) => ({
    search:           f.search?.length ? f.search : undefined,
    company_id:       f.company_id?.length ? f.company_id : undefined,
    work_type_id:     f.work_type_id?.length ? f.work_type_id : undefined,
    work_location_id: f.work_location_id?.length ? f.work_location_id : undefined,
    is_done:          f.is_done ?? undefined,
    is_closed:        f.is_closed ?? undefined,
    reopened:         f.reopened ?? undefined,
    date_from:        f.work_date?.[0]?.format('YYYY-MM-DD') ?? undefined,
    date_to:          f.work_date?.[1]?.format('YYYY-MM-DD') ?? undefined,
    created_from:     f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:       f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    only_favorites:   f.only_favorites ? 1 : undefined,
});

/** Resumen legible para la portada del export PDF/Word. */
export const workPlansFiltersSummary = (f, t) => {
    const parts = [];
    if (f.search?.length)           parts.push(`${t('work_plans.filter_search')}: ${f.search.join(', ')}`);
    if (f.company_id?.length)       parts.push(`${t('work_plans.company')}: ${f.company_id.length}`);
    if (f.work_type_id?.length)     parts.push(`${t('work_plans.work_type')}: ${f.work_type_id.length}`);
    if (f.work_location_id?.length) parts.push(`${t('work_plans.work_location')}: ${f.work_location_id.length}`);
    if (f.is_done !== null && f.is_done !== undefined) {
        parts.push(`${t('work_plans.is_done')}: ${f.is_done ? t('work_plans.state_done') : t('work_plans.state_pending')}`);
    }
    if (f.is_closed !== null && f.is_closed !== undefined) {
        parts.push(`${t('work_plans.is_closed')}: ${f.is_closed ? t('work_plans.state_closed') : t('work_plans.state_open')}`);
    }
    if (f.reopened !== null && f.reopened !== undefined) {
        parts.push(`${t('work_plans.filter_reopened')}: ${f.reopened ? t('work_plans.state_reopened') : t('work_plans.filter_never_reopened')}`);
    }
    if (f.work_date)  parts.push(`${t('work_plans.date_start')}: ${f.work_date[0]?.format('YYYY-MM-DD')} → ${f.work_date[1]?.format('YYYY-MM-DD')}`);
    if (f.created_at) parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

/**
 * Serialización de filtros para Saved Views (JSON-safe: dayjs → ISO strings).
 * Round-trip con `deserializeSavedFilters`.
 */
export const serializeSavedFilters = (f) => ({
    search:           f.search ?? [],
    company_id:       f.company_id ?? [],
    work_type_id:     f.work_type_id ?? [],
    work_location_id: f.work_location_id ?? [],
    is_done:          f.is_done ?? null,
    is_closed:        f.is_closed ?? null,
    reopened:         f.reopened ?? null,
    work_date:  f.work_date?.[0]
        ? [f.work_date[0].format('YYYY-MM-DD'), f.work_date[1]?.format('YYYY-MM-DD')]
        : null,
    created_at: f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    search:           Array.isArray(f.search) ? f.search : [],
    company_id:       Array.isArray(f.company_id) ? f.company_id : [],
    work_type_id:     Array.isArray(f.work_type_id) ? f.work_type_id : [],
    work_location_id: Array.isArray(f.work_location_id) ? f.work_location_id : [],
    is_done:          f.is_done ?? null,
    is_closed:        f.is_closed ?? null,
    reopened:         f.reopened ?? null,
    work_date:  f.work_date?.[0]  ? [dayjs(f.work_date[0]),  dayjs(f.work_date[1])]  : null,
    created_at: f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    only_favorites: f.only_favorites ?? false,
});
