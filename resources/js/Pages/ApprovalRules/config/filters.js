import dayjs from 'dayjs';

/**
 * Filtros de las reglas del flujo.
 *
 * El tipo de trabajo tiene un valor especial, `none`: «solo las reglas
 * generales», las que no acotan ningún tipo. Es el filtro que se quiere cuando
 * uno intenta entender por qué un tipo hereda lo que hereda.
 *
 * `options` llega del backend (países, tipos y roles) porque son catálogos y no
 * constantes: el selector no puede estar escrito a mano en el frontend.
 */
export const approvalRulesFilterFields = (t, options = {}) => [
    { key: 'name',       label: t('approval_rules.filter_name'), type: 'tags' },
    { key: 'country_id', label: t('approval_rules.country'), type: 'select', options: options.countries ?? [] },
    { key: 'work_type_id', label: t('approval_rules.work_type'), type: 'select', options: [
        { value: 'none', label: t('approval_rules.all_work_types') },
        ...(options.work_types ?? []),
    ]},
    { key: 'approver_role', label: t('approval_rules.approver_role'), type: 'select', options: options.approver_roles ?? [] },
    { key: 'is_required', label: t('approval_rules.is_required'), type: 'select', options: [
        { value: true,  label: t('approval_rules.required') },
        { value: false, label: t('approval_rules.optional') },
    ]},
    { key: 'is_active', label: t('approval_rules.is_active'), type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'created_at', label: t('global.created_at'), type: 'date_range' },
];

export const approvalRulesEmptyFilters = () => ({
    name: [],
    country_id: null,
    work_type_id: null,
    approver_role: null,
    is_required: null,
    is_active: null,
    created_at: null,
});

export const hydrateApprovalRulesFilters = (server) => ({
    name:          Array.isArray(server.name) ? server.name : [],
    country_id:    server.country_id ?? null,
    work_type_id:  server.work_type_id ?? null,
    approver_role: server.approver_role ?? null,
    is_required:   server.is_required ?? null,
    is_active:     server.is_active ?? null,
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
});

export const approvalRulesFiltersToQuery = (f) => ({
    name:          f.name?.length ? f.name : undefined,
    country_id:    f.country_id ?? undefined,
    work_type_id:  f.work_type_id ?? undefined,
    approver_role: f.approver_role ?? undefined,
    is_required:   f.is_required ?? undefined,
    is_active:     f.is_active ?? undefined,
    created_from:  f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:    f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
});

export const approvalRulesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)    parts.push(`${t('approval_rules.filter_name')}: ${f.name.join(', ')}`);
    if (f.approver_role)   parts.push(`${t('approval_rules.approver_role')}: ${f.approver_role}`);
    if (f.work_type_id === 'none') parts.push(`${t('approval_rules.work_type')}: ${t('approval_rules.all_work_types')}`);
    if (f.is_required !== null && f.is_required !== undefined) {
        parts.push(`${t('approval_rules.is_required')}: ${f.is_required ? t('approval_rules.required') : t('approval_rules.optional')}`);
    }
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('approval_rules.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
    }
    if (f.created_at) parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

export const serializeSavedFilters = (f) => ({
    name:          f.name ?? [],
    country_id:    f.country_id ?? null,
    work_type_id:  f.work_type_id ?? null,
    approver_role: f.approver_role ?? null,
    is_required:   f.is_required ?? null,
    is_active:     f.is_active ?? null,
    created_at:    f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:          Array.isArray(f.name) ? f.name : [],
    country_id:    f.country_id ?? null,
    work_type_id:  f.work_type_id ?? null,
    approver_role: f.approver_role ?? null,
    is_required:   f.is_required ?? null,
    is_active:     f.is_active ?? null,
    created_at:    f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
});
