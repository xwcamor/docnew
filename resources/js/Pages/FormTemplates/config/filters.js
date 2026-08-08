import dayjs from 'dayjs';

/**
 * Schema de filtros del módulo Documentos.
 *
 * Campos de dominio: nombre + código + publicación + estado. «Publicación» es
 * el filtro que faltaba, y es la pregunta que se hace de verdad en este
 * listado: cuáles puede usar ya un plan y cuáles siguen en borrador.
 */
export const formTemplatesFilterFields = (t) => [
    { key: 'name',           label: t('form_templates.filter_name'),   type: 'tags' },
    { key: 'code',           label: t('form_templates.code'),          type: 'text' },
    { key: 'status',         label: t('form_templates.filter_status'), type: 'select', options: [
        { value: 'draft',     label: t('form_templates.status_draft')     },
        { value: 'published', label: t('form_templates.status_published') },
        { value: 'archived',  label: t('form_templates.status_archived')  },
    ]},
    { key: 'is_active',      label: t('form_templates.is_active'),   type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'created_at',     label: t('global.created_at'),     type: 'date_range' },
    { key: 'only_favorites', label: t('global.only_favorites'), type: 'switch' },
];

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const formTemplatesEmptyFilters = () => ({
    name: [],
    code: '',
    status: null,
    is_active: null,
    created_at: null,
    only_favorites: false,
});

/** Backend payload → form local (dates ISO → dayjs). */
export const hydrateFormTemplatesFilters = (server) => ({
    name:       Array.isArray(server.name) ? server.name : [],
    code:       server.code || '',
    status:     server.status ?? null,
    is_active:  server.is_active ?? null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
    only_favorites: server.only_favorites ?? false,
});

/** Form local → request params para Inertia reload. */
export const formTemplatesFiltersToQuery = (f) => ({
    name:           f.name?.length ? f.name : undefined,
    code:           f.code || undefined,
    status:         f.status || undefined,
    is_active:      f.is_active ?? undefined,
    created_from:   f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:     f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    only_favorites: f.only_favorites ? 1 : undefined,
});

/** Resumen legible para la portada del export PDF/Word. */
export const formTemplatesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)        parts.push(`${t('form_templates.filter_name')}: ${f.name.join(', ')}`);
    if (f.code)                parts.push(`${t('form_templates.code')}: ${f.code}`);
    if (f.status)              parts.push(`${t('form_templates.filter_status')}: ${t(`form_templates.status_${f.status}`)}`);
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('form_templates.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
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
    code:           f.code ?? '',
    status:         f.status ?? null,
    is_active:      f.is_active ?? null,
    created_at:     f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:           Array.isArray(f.name) ? f.name : [],
    code:           f.code ?? '',
    status:         f.status ?? null,
    is_active:      f.is_active ?? null,
    created_at:     f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    only_favorites: f.only_favorites ?? false,
});
