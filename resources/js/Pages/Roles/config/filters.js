import dayjs from 'dayjs';

/**
 * Schema de filtros del módulo Roles. El filtro `scope` (system/tenant) solo
 * aplica a super — por eso `customersFilterFields` recibe `{ isSuper }`.
 * Misma estructura que Regions: la config vive aquí, no hardcodeada en Index.vue.
 */
export const rolesFilterFields = (t, { isSuper = false } = {}) => {
    const fields = [
        { key: 'name',      label: t('roles.name'),      type: 'tags' },
        { key: 'is_active', label: t('roles.is_active'), type: 'select', options: [
            { value: true,  label: t('global.active')   },
            { value: false, label: t('global.inactive') },
        ]},
    ];
    if (isSuper) {
        fields.push({ key: 'scope', label: t('roles.scope'), type: 'multiselect', options: [
            { value: 'system', label: t('roles.tag_system') },
            { value: 'tenant', label: 'Tenant' },
        ]});
    }
    fields.push(
        { key: 'created_at',     label: t('global.created_at'),     type: 'date_range' },
        { key: 'only_favorites', label: t('global.only_favorites'), type: 'switch' },
    );
    return fields;
};

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const rolesEmptyFilters = () => ({
    name: [],
    is_active: null,
    scope: [],
    created_at: null,
    only_favorites: false,
});

/** Backend payload → form local (dates ISO → dayjs). */
export const hydrateRolesFilters = (sf) => ({
    name:       Array.isArray(sf.name) ? sf.name : [],
    is_active:  sf.is_active ?? null,
    scope:      Array.isArray(sf.scope) ? sf.scope : [],
    created_at: (sf.created_from && sf.created_to)
        ? [dayjs(sf.created_from), dayjs(sf.created_to)]
        : null,
    only_favorites: sf.only_favorites ?? false,
});

/** Form local → request params para Inertia reload. */
export const rolesFiltersToQuery = (f) => ({
    name:           f.name?.length ? f.name : undefined,
    is_active:      f.is_active ?? undefined,
    scope:          f.scope?.length ? f.scope : undefined,
    created_from:   f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:     f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    only_favorites: f.only_favorites ? 1 : undefined,
});

/** Resumen legible para la portada del export PDF/Word. */
export const rolesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)  parts.push(`${t('roles.name')}: ${f.name.join(', ')}`);
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('roles.is_active')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
    }
    if (f.scope?.length) parts.push(`${t('roles.scope')}: ${f.scope.join(', ')}`);
    if (f.created_at)    parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

/** Serialización de filtros para Saved Views (JSON-safe). Round-trip con deserialize. */
export const serializeSavedFilters = (f) => ({
    name:           f.name ?? [],
    is_active:      f.is_active ?? null,
    scope:          f.scope ?? [],
    created_at:     f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:           Array.isArray(f.name) ? f.name : [],
    is_active:      f.is_active ?? null,
    scope:          Array.isArray(f.scope) ? f.scope : [],
    created_at:     f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    only_favorites: f.only_favorites ?? false,
});
