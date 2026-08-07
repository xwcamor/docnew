import dayjs from 'dayjs';

/**
 * Schema de filtros del módulo Customers. Toma `t` y `{ countryOptions }`
 * (las opciones del multiselect País vienen del controller). Mismo patrón
 * que Regions/Tenants — la config vive aquí, no hardcodeada en Index.vue.
 */
export const customersFilterFields = (t, { countryOptions = [] } = {}) => [
    { key: 'name',           label: t('customers.filter_name'), type: 'tags' },
    { key: 'cod',            label: t('customers.cod'),         type: 'text' },
    { key: 'country_id',     label: t('customers.country'),     type: 'multiselect', options: countryOptions },
    { key: 'is_active',      label: t('customers.is_active'),   type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'created_at',     label: t('global.created_at'),     type: 'date_range' },
    { key: 'only_favorites', label: t('global.only_favorites'), type: 'switch' },
];

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const customersEmptyFilters = () => ({
    name: [],
    cod: '',
    country_id: [],
    is_active: null,
    created_at: null,
    only_favorites: false,
    customer_group: null,
});

/** Backend payload → form local (dates ISO → dayjs). */
export const hydrateCustomersFilters = (server) => ({
    name:       Array.isArray(server.name) ? server.name : [],
    cod:        server.cod || '',
    country_id: Array.isArray(server.country_id) ? server.country_id : [],
    is_active:  server.is_active ?? null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
    only_favorites: server.only_favorites ?? false,
    customer_group: server.customer_group ?? null,
});

/** Form local → request params para Inertia reload. */
export const customersFiltersToQuery = (f) => ({
    name:           f.name?.length ? f.name : undefined,
    cod:            f.cod || undefined,
    country_id:     f.country_id?.length ? f.country_id : undefined,
    is_active:      f.is_active ?? undefined,
    created_from:   f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:     f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    only_favorites: f.only_favorites ? 1 : undefined,
    customer_group: f.customer_group || undefined,
});

/** Resumen legible para la portada del export PDF/Word. */
export const customersFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)        parts.push(`${t('customers.filter_name')}: ${f.name.join(', ')}`);
    if (f.cod)                 parts.push(`${t('customers.cod')}: ${f.cod}`);
    if (f.country_id?.length)  parts.push(`${t('customers.country')}: ${f.country_id.length}`);
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('customers.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
    }
    if (f.created_at)          parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

/**
 * Serialización de filtros para Saved Views (JSON-safe: dayjs → ISO strings).
 * Round-trip con `deserializeSavedFilters`.
 */
export const serializeSavedFilters = (f) => ({
    name:           f.name ?? [],
    cod:            f.cod ?? '',
    country_id:     f.country_id ?? [],
    is_active:      f.is_active ?? null,
    created_at:     f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:           Array.isArray(f.name) ? f.name : [],
    cod:            f.cod ?? '',
    country_id:     Array.isArray(f.country_id) ? f.country_id : [],
    is_active:      f.is_active ?? null,
    created_at:     f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    only_favorites: f.only_favorites ?? false,
    customer_group: f.customer_group ?? null,
});
