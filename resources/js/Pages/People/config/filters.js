import dayjs from 'dayjs';

/**
 * Schema de filtros del módulo People.
 *
 * El campo `name` busca a la vez en nombre y apellido: nadie recuerda en qué
 * orden se cargó a alguien. Las opciones de país, tipo de documento, empresa y
 * rol las inyecta el controller.
 */
export const peopleFilterFields = (t, {
    countryOptions = [], docTypeOptions = [], companyOptions = [], roleOptions = [],
} = {}) => [
    { key: 'name',           label: t('people.filter_name'),  type: 'tags' },
    { key: 'num_doc',        label: t('people.num_doc'),      type: 'text' },
    { key: 'doc_type',       label: t('people.doc_type'),     type: 'select', options: docTypeOptions },
    { key: 'company_id',     label: t('people.companies'),    type: 'multiselect', options: companyOptions },
    { key: 'role',           label: t('people.roles'),        type: 'multiselect', options: roleOptions },
    { key: 'has_biometric',  label: t('people.biometric'),    type: 'select', options: [
        { value: true,  label: t('people.biometric_yes') },
        { value: false, label: t('people.biometric_no')  },
    ]},
    { key: 'country_id',     label: t('people.country'),      type: 'multiselect', options: countryOptions },
    { key: 'is_active',      label: t('people.is_active'),    type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'created_at',     label: t('global.created_at'),     type: 'date_range' },
    { key: 'only_favorites', label: t('global.only_favorites'), type: 'switch' },
];

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const peopleEmptyFilters = () => ({
    name: [],
    num_doc: '',
    doc_type: null,
    company_id: [],
    role: [],
    has_biometric: null,
    country_id: [],
    is_active: null,
    created_at: null,
    only_favorites: false,
});

/** Backend payload → form local (dates ISO → dayjs). */
export const hydratePeopleFilters = (server) => ({
    name:          Array.isArray(server.name) ? server.name : [],
    num_doc:       server.num_doc || '',
    doc_type:      server.doc_type || null,
    company_id:    Array.isArray(server.company_id) ? server.company_id : [],
    role:          Array.isArray(server.role) ? server.role : [],
    has_biometric: server.has_biometric ?? null,
    country_id:    Array.isArray(server.country_id) ? server.country_id : [],
    is_active:     server.is_active ?? null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
    only_favorites: server.only_favorites ?? false,
});

/** Form local → request params para Inertia reload. */
export const peopleFiltersToQuery = (f) => ({
    name:           f.name?.length ? f.name : undefined,
    num_doc:        f.num_doc || undefined,
    doc_type:       f.doc_type || undefined,
    company_id:     f.company_id?.length ? f.company_id : undefined,
    role:           f.role?.length ? f.role : undefined,
    has_biometric:  f.has_biometric ?? undefined,
    country_id:     f.country_id?.length ? f.country_id : undefined,
    is_active:      f.is_active ?? undefined,
    created_from:   f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:     f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    only_favorites: f.only_favorites ? 1 : undefined,
});

/** Resumen legible para la portada del export PDF/Word. */
export const peopleFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)       parts.push(`${t('people.filter_name')}: ${f.name.join(', ')}`);
    if (f.num_doc)            parts.push(`${t('people.num_doc')}: ${f.num_doc}`);
    if (f.doc_type)           parts.push(`${t('people.doc_type')}: ${f.doc_type}`);
    if (f.company_id?.length) parts.push(`${t('people.companies')}: ${f.company_id.length}`);
    if (f.role?.length)       parts.push(`${t('people.roles')}: ${f.role.join(', ')}`);
    if (f.has_biometric !== null && f.has_biometric !== undefined) {
        parts.push(`${t('people.biometric')}: ${f.has_biometric ? t('people.biometric_yes') : t('people.biometric_no')}`);
    }
    if (f.country_id?.length) parts.push(`${t('people.country')}: ${f.country_id.length}`);
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('people.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
    }
    if (f.created_at)         parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

/**
 * Serialización de filtros para Saved Views (JSON-safe: dayjs → ISO strings).
 * Round-trip con `deserializeSavedFilters`.
 */
export const serializeSavedFilters = (f) => ({
    name:          f.name ?? [],
    num_doc:       f.num_doc ?? '',
    doc_type:      f.doc_type ?? null,
    company_id:    f.company_id ?? [],
    role:          f.role ?? [],
    has_biometric: f.has_biometric ?? null,
    country_id:    f.country_id ?? [],
    is_active:     f.is_active ?? null,
    created_at:    f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:          Array.isArray(f.name) ? f.name : [],
    num_doc:       f.num_doc ?? '',
    doc_type:      f.doc_type ?? null,
    company_id:    Array.isArray(f.company_id) ? f.company_id : [],
    role:          Array.isArray(f.role) ? f.role : [],
    has_biometric: f.has_biometric ?? null,
    country_id:    Array.isArray(f.country_id) ? f.country_id : [],
    is_active:     f.is_active ?? null,
    created_at:    f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    only_favorites: f.only_favorites ?? false,
});
