import dayjs from 'dayjs';

/**
 * Filtros de los tipos de trabajo.
 *
 * El filtro que de verdad importa es `has_forms` en «Sin ningún formato»: un
 * tipo así deja salir el trabajo sin documentación, y es lo primero que hay que
 * encontrar al entrar al módulo.
 *
 * `options` llega del backend (países y formatos) porque son catálogos y no
 * constantes: el selector no puede estar escrito a mano en el frontend.
 */
export const workTypesFilterFields = (t, options = {}) => [
    { key: 'name',       label: t('work_types.filter_name'), type: 'tags' },
    { key: 'country_id', label: t('work_types.country'), type: 'select', options: options.countries ?? [] },
    { key: 'form_template_id', label: t('work_types.filter_form_template'), type: 'select', options: options.form_templates ?? [] },
    { key: 'has_forms', label: t('work_types.filter_has_forms'), type: 'select', options: [
        { value: true,  label: t('work_types.filter_has_forms_yes') },
        { value: false, label: t('work_types.filter_has_forms_no')  },
    ]},
    { key: 'is_active', label: t('work_types.is_active'), type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'created_at', label: t('global.created_at'), type: 'date_range' },
];

export const workTypesEmptyFilters = () => ({
    name: [],
    country_id: null,
    form_template_id: null,
    has_forms: null,
    is_active: null,
    created_at: null,
});

export const hydrateWorkTypesFilters = (server) => ({
    name:             Array.isArray(server.name) ? server.name : [],
    country_id:       server.country_id ?? null,
    form_template_id: server.form_template_id ?? null,
    has_forms:        server.has_forms ?? null,
    is_active:        server.is_active ?? null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
});

export const workTypesFiltersToQuery = (f) => ({
    name:             f.name?.length ? f.name : undefined,
    country_id:       f.country_id ?? undefined,
    form_template_id: f.form_template_id ?? undefined,
    has_forms:        f.has_forms ?? undefined,
    is_active:        f.is_active ?? undefined,
    created_from:     f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:       f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
});

export const workTypesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length) parts.push(`${t('work_types.filter_name')}: ${f.name.join(', ')}`);
    if (f.has_forms !== null && f.has_forms !== undefined) {
        parts.push(`${t('work_types.filter_has_forms')}: ${f.has_forms ? t('work_types.filter_has_forms_yes') : t('work_types.filter_has_forms_no')}`);
    }
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('work_types.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
    }
    if (f.created_at) parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

export const serializeSavedFilters = (f) => ({
    name:             f.name ?? [],
    country_id:       f.country_id ?? null,
    form_template_id: f.form_template_id ?? null,
    has_forms:        f.has_forms ?? null,
    is_active:        f.is_active ?? null,
    created_at:       f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:             Array.isArray(f.name) ? f.name : [],
    country_id:       f.country_id ?? null,
    form_template_id: f.form_template_id ?? null,
    has_forms:        f.has_forms ?? null,
    is_active:        f.is_active ?? null,
    created_at:       f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
});
