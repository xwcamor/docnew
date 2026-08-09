import dayjs from 'dayjs';

/**
 * Filtros del catálogo de tipos de documento.
 *
 * Ojo con el nombre: `name` aquí es el buscador general de la barra —se llama
 * igual en los quince módulos—, no la columna `name` de esta tabla. El backend
 * lo hace valer contra la sigla Y el nombre largo, porque quien escribe
 * «extranjería» espera encontrar el CE igual que quien escribe «CE».
 */
export const document_typesFilterFields = (t) => [
    { key: 'name',      label: t('document_types.filter_name'), type: 'tags' },
    { key: 'is_active', label: t('document_types.is_active'),   type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'created_at', label: t('global.created_at'), type: 'date_range' },
];

/** Estado vacío del formulario de filtros (lo usa también «limpiar»). */
export const document_typesEmptyFilters = () => ({
    name: [],
    is_active: null,
    created_at: null,
});

/** Lo que devuelve el backend → formulario local (fechas ISO → dayjs). */
export const hydrateDocumentTypesFilters = (server) => ({
    name:       Array.isArray(server.name) ? server.name : [],
    is_active:  server.is_active ?? null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
});

/** Formulario local → parámetros de la petición. */
export const document_typesFiltersToQuery = (f) => ({
    name:         f.name?.length ? f.name : undefined,
    is_active:    f.is_active ?? undefined,
    created_from: f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:   f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
});

/** Resumen legible de los filtros puestos. */
export const document_typesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length) parts.push(`${t('document_types.filter_name')}: ${f.name.join(', ')}`);
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('document_types.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
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
