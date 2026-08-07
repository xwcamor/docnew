import dayjs from 'dayjs';

/**
 * Schema de filtros del módulo Users. Recibe `t` + `{ isSuper,
 * roleOptions, tenantOptions }` — el filtro `tenant_id` solo aplica a
 * super. Misma estructura que Regions: la config vive aquí, no
 * hardcodeada en Index.vue.
 */
export const usersFilterFields = (t, { isSuper = false, roleOptions = [], tenantOptions = [] } = {}) => {
    const fields = [
        { key: 'name',      label: t('users.filter_name'),   type: 'tags' },
        { key: 'email',     label: t('users.filter_email'),  type: 'text' },
        { key: 'is_active', label: t('users.filter_status'), type: 'select', options: [
            { value: true,  label: t('global.active')   },
            { value: false, label: t('global.inactive') },
        ]},
        { key: 'role_id',   label: t('users.role'), type: 'multiselect', options: roleOptions },
    ];
    if (isSuper) {
        fields.push({ key: 'tenant_id', label: t('users.tenant'), type: 'multiselect', options: tenantOptions });
    }
    fields.push(
        { key: 'created_at',     label: t('global.created_at'),     type: 'date_range' },
        { key: 'only_favorites', label: t('global.only_favorites'), type: 'switch' },
    );
    return fields;
};

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const usersEmptyFilters = () => ({
    name: [],
    email: '',
    is_active: null,
    role_id: [],
    tenant_id: [],
    created_at: null,
    only_favorites: false,
});

/** Backend payload → form local (dates ISO → dayjs). */
export const hydrateUsersFilters = (sf) => ({
    name:       Array.isArray(sf.name) ? sf.name : [],
    email:      sf.email || '',
    is_active:  sf.is_active ?? null,
    role_id:    Array.isArray(sf.role_id) ? sf.role_id : [],
    tenant_id:  Array.isArray(sf.tenant_id) ? sf.tenant_id : [],
    created_at: (sf.created_from && sf.created_to)
        ? [dayjs(sf.created_from), dayjs(sf.created_to)]
        : null,
    only_favorites: sf.only_favorites ?? false,
});

/**
 * Form local → request params para Inertia reload. Incluye sort/direction/
 * per_page del serverFilters para preservarlos al cambiar un filtro.
 */
export const usersFiltersToQuery = (f, sf = {}) => ({
    name:           f.name?.length ? f.name : undefined,
    email:          f.email || undefined,
    is_active:      f.is_active ?? undefined,
    role_id:        f.role_id?.length ? f.role_id : undefined,
    tenant_id:      f.tenant_id?.length ? f.tenant_id : undefined,
    created_from:   f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:     f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    only_favorites: f.only_favorites ? 1 : undefined,
    sort:           sf.sort,
    direction:      sf.direction,
    per_page:       sf.per_page,
});

/** Resumen legible para la portada del export PDF/Word. */
export const usersFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)      parts.push(`${t('users.filter_name')}: ${f.name.join(', ')}`);
    if (f.email)             parts.push(`${t('users.filter_email')}: ${f.email}`);
    if (f.is_active !== null && f.is_active !== undefined) {
        parts.push(`${t('users.filter_status')}: ${f.is_active ? t('global.active') : t('global.inactive')}`);
    }
    if (f.role_id?.length)   parts.push(`${t('users.role')}: ${f.role_id.length}`);
    if (f.tenant_id?.length) parts.push(`${t('users.tenant')}: ${f.tenant_id.length}`);
    if (f.created_at)        parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

/** Serialización de filtros para Saved Views (JSON-safe). Round-trip con deserialize. */
export const serializeSavedFilters = (f) => ({
    name:       f.name ?? [],
    email:      f.email ?? '',
    is_active:  f.is_active ?? null,
    role_id:    f.role_id ?? [],
    tenant_id:  f.tenant_id ?? [],
    created_at: f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:       Array.isArray(f.name) ? f.name : [],
    email:      f.email ?? '',
    is_active:  f.is_active ?? null,
    role_id:    Array.isArray(f.role_id) ? f.role_id : [],
    tenant_id:  Array.isArray(f.tenant_id) ? f.tenant_id : [],
    created_at: f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    only_favorites: f.only_favorites ?? false,
});
