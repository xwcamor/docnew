import dayjs from 'dayjs';

/**
 * Filtros del catálogo de roles aprobadores.
 *
 * El campo `name` es el buscador general: el backend lo hace valer contra el
 * código y los dos nombres a la vez, porque quien busca «HSE» no tiene por qué
 * saber en qué columna estaba.
 */
export const approverRolesFilterFields = (t) => [
    { key: 'name',      label: t('approver_roles.filter_name'), type: 'tags' },
    { key: 'code',      label: t('approver_roles.code'),        type: 'text' },
    { key: 'is_active', label: t('approver_roles.is_active'),   type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'created_at', label: t('global.created_at'), type: 'date_range' },
];

/** Estado vacío del formulario de filtros (lo usa también «limpiar»). */
export const approverRolesEmptyFilters = () => ({
    name: [],
    code: '',
    is_active: null,
    created_at: null,
});

/** Lo que devuelve el backend → formulario local (fechas ISO → dayjs). */
export const hydrateApproverRolesFilters = (server) => ({
    name:       Array.isArray(server.name) ? server.name : [],
    code:       server.code || '',
    is_active:  server.is_active ?? null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
});

/** Formulario local → parámetros de la petición. */
export const approverRolesFiltersToQuery = (f) => ({
    name:         f.name?.length ? f.name : undefined,
    code:         f.code || undefined,
    is_active:    f.is_active ?? undefined,
    created_from: f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:   f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
});

/** Resumen legible para la portada del PDF/Word exportado. */
export const approverRolesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length) parts.push(`${t('approver_roles.filter_name')}: ${f.name.join(', ')}`);
    if (f.code)         parts.push(`${t('approver_roles.code')}: ${f.code}`);
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('approver_roles.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
    }
    if (f.created_at) parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

/** Serialización para Vistas guardadas: JSON plano, sin objetos dayjs. */
export const serializeSavedFilters = (f) => ({
    name:       f.name ?? [],
    code:       f.code ?? '',
    is_active:  f.is_active ?? null,
    created_at: f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:       Array.isArray(f.name) ? f.name : [],
    code:       f.code ?? '',
    is_active:  f.is_active ?? null,
    created_at: f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
});
