import dayjs from 'dayjs';
import { dateRangeFromISO, dateRangeToISO } from '@/Composables/useModuleFilters';

/**
 * Schema de filtros del módulo Countries. Toma `t` + options dinámicos del
 * backend (regiones, locales) y devuelve los fields que FilterBar/Drawer
 * consumen. `visible: false` mantiene el filtro en el pool (accesible vía
 * "Adaptar filtros") sin renderizarlo por default.
 */
export const countriesFilterFields = (t, opts = {}) => {
    const regionOptions = opts.regionOptions ?? [];
    const localeOptions = opts.localeOptions ?? [];

    return [
        { key: 'name',              label: t('countries.name'),      type: 'tags' },
        { key: 'iso_code',          label: t('countries.iso_code'),  type: 'tags' },
        { key: 'currency',          label: t('countries.currency'),  type: 'tags', visible: false },
        { key: 'region_id',         label: t('countries.region'),    type: 'multiselect', options: regionOptions },
        { key: 'default_locale_id', label: t('countries.default_locale'), type: 'multiselect', options: localeOptions, visible: false },
        { key: 'is_active',         label: t('countries.is_active'), type: 'select', options: [
            { value: true,  label: t('global.active')   },
            { value: false, label: t('global.inactive') },
        ]},
        { key: 'only_favorites',    label: t('global.only_favorites'), type: 'switch' },
        { key: 'created_at',        label: t('global.created_at'),  type: 'date_range',   visible: false },
        { key: 'updated_at',        label: t('global.updated_at'),  type: 'date_range',   visible: false },
        { key: 'id_range',          label: 'ID',                    type: 'number_range', visible: false },
    ];
};

export const countriesEmptyFilters = () => ({
    name: [],
    iso_code: [],
    currency: [],
    region_id: [],
    default_locale_id: [],
    is_active: null,
    created_at: null,
    updated_at: null,
    id_range: null,
    only_favorites: false,
});

export const hydrateCountriesFilters = (sf) => ({
    name:               Array.isArray(sf.name) ? sf.name : [],
    iso_code:           Array.isArray(sf.iso_code) ? sf.iso_code : (sf.iso_code ? [sf.iso_code] : []),
    currency:           Array.isArray(sf.currency) ? sf.currency : (sf.currency ? [sf.currency] : []),
    region_id:          Array.isArray(sf.region_id) ? sf.region_id.map(Number) : (sf.region_id ? [Number(sf.region_id)] : []),
    default_locale_id:  Array.isArray(sf.default_locale_id) ? sf.default_locale_id.map(Number) : (sf.default_locale_id ? [Number(sf.default_locale_id)] : []),
    is_active:          sf.is_active ?? null,
    created_at:         dateRangeFromISO(sf.created_from, sf.created_to),
    updated_at:         dateRangeFromISO(sf.updated_from, sf.updated_to),
    id_range:           (sf.id_from || sf.id_to) ? [sf.id_from || null, sf.id_to || null] : null,
    only_favorites:     sf.only_favorites ?? false,
});

export const countriesFiltersToQuery = (f, sf) => ({
    name:               f.name?.length ? f.name : undefined,
    iso_code:           f.iso_code?.length ? f.iso_code : undefined,
    currency:           f.currency?.length ? f.currency : undefined,
    region_id:          f.region_id?.length ? f.region_id : undefined,
    default_locale_id:  f.default_locale_id?.length ? f.default_locale_id : undefined,
    is_active:          f.is_active ?? undefined,
    created_from:       f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:         f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    updated_from:       f.updated_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    updated_to:         f.updated_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    id_from:            f.id_range?.[0] ?? undefined,
    id_to:              f.id_range?.[1] ?? undefined,
    only_favorites:     f.only_favorites ? 1 : undefined,
    sort:               sf.sort,
    direction:          sf.direction,
    per_page:           sf.per_page,
});

export const countriesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)              parts.push(`${t('countries.name')}: ${f.name.join(', ')}`);
    if (f.iso_code?.length)          parts.push(`${t('countries.iso_code')}: ${f.iso_code.join(', ')}`);
    if (f.currency?.length)          parts.push(`${t('countries.currency')}: ${f.currency.join(', ')}`);
    if (f.region_id?.length)         parts.push(`${t('countries.region')}: ${f.region_id.length} ${t('global.selected') ?? ''}`);
    if (f.default_locale_id?.length) parts.push(`${t('countries.default_locale')}: ${f.default_locale_id.length} ${t('global.selected') ?? ''}`);
    if (f.is_active === true)        parts.push(`${t('countries.is_active')}: ${t('global.active')}`);
    if (f.is_active === false)       parts.push(`${t('countries.is_active')}: ${t('global.inactive')}`);
    if (f.created_at?.[0])           parts.push(`${t('global.created_at')}: ${f.created_at[0].format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD') ?? ''}`);
    if (f.updated_at?.[0])           parts.push(`${t('global.updated_at')}: ${f.updated_at[0].format('YYYY-MM-DD')} → ${f.updated_at[1]?.format('YYYY-MM-DD') ?? ''}`);
    if (f.id_range?.[0] != null || f.id_range?.[1] != null) {
        parts.push(`${t('global.filter_summary_id')}: ${f.id_range[0] ?? ''} – ${f.id_range[1] ?? ''}`);
    }
    return parts.join(' · ');
};

export const serializeSavedFilters = (f) => ({
    name:              f.name ?? [],
    iso_code:          f.iso_code ?? [],
    currency:          f.currency ?? [],
    region_id:         f.region_id ?? [],
    default_locale_id: f.default_locale_id ?? [],
    is_active:         f.is_active ?? null,
    created_at:        dateRangeToISO(f.created_at),
    updated_at:        dateRangeToISO(f.updated_at),
    id_range:          f.id_range ?? null,
    only_favorites:    !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:              Array.isArray(f.name) ? f.name : [],
    iso_code:          Array.isArray(f.iso_code) ? f.iso_code : [],
    currency:          Array.isArray(f.currency) ? f.currency : [],
    region_id:         Array.isArray(f.region_id) ? f.region_id.map(Number) : [],
    default_locale_id: Array.isArray(f.default_locale_id) ? f.default_locale_id.map(Number) : [],
    is_active:         f.is_active ?? null,
    created_at:        f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    updated_at:        f.updated_at?.[0] ? [dayjs(f.updated_at[0]), dayjs(f.updated_at[1])] : null,
    id_range:          f.id_range ?? null,
    only_favorites:    f.only_favorites ?? false,
});
