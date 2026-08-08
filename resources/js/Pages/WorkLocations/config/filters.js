import dayjs from 'dayjs';

/**
 * Filtros del catálogo de sedes.
 *
 * `name` es el buscador general de la barra: el backend lo hace valer contra
 * «Nombre», que es el único campo que el usuario reconoce de esta tabla.
 */
export const workLocationsFilterFields = (t) => [
    { key: 'name',      label: t('work_locations.filter_name'), type: 'tags' },
    { key: 'is_active', label: t('work_locations.is_active'),   type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'created_at', label: t('global.created_at'), type: 'date_range' },
];

/** Estado vacío del formulario de filtros (lo usa también «limpiar»). */
export const workLocationsEmptyFilters = () => ({
    name: [],
    is_active: null,
    created_at: null,
});

/** Lo que devuelve el backend → formulario local (fechas ISO → dayjs). */
export const hydrateWorkLocationsFilters = (server) => ({
    name:       Array.isArray(server.name) ? server.name : [],
    is_active:  server.is_active ?? null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
});

/** Formulario local → parámetros de la petición. */
export const workLocationsFiltersToQuery = (f) => ({
    name:         f.name?.length ? f.name : undefined,
    is_active:    f.is_active ?? undefined,
    created_from: f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:   f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
});

/** Resumen legible de los filtros puestos. */
export const workLocationsFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length) parts.push(`${t('work_locations.filter_name')}: ${f.name.join(', ')}`);
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('work_locations.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
    }
    if (f.created_at) parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

/** Serialización para Vistas guardadas: JSON plano, sin objetos dayjs. */
export const serializeSavedFilters = (f) => ({
    name:       f.name ?? [],
    is_active:  f.is_active ?? null,
    created_at: f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:       Array.isArray(f.name) ? f.name : [],
    is_active:  f.is_active ?? null,
    created_at: f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
});
