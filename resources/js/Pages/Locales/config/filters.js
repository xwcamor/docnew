import dayjs from 'dayjs';
import { dateRangeFromISO, dateRangeToISO } from '@/Composables/useModuleFilters';

export const localesFilterFields = (t, opts = {}) => {
    const languageOptions = opts.languageOptions ?? [];
    return [
        { key: 'name',        label: t('locales.name'),     type: 'tags' },
        { key: 'code',        label: t('locales.code'),     type: 'tags' },
        { key: 'language_id', label: t('locales.language'), type: 'multiselect', options: languageOptions },
        { key: 'is_active',   label: t('locales.is_active'), type: 'select', options: [
            { value: true,  label: t('global.active')   },
            { value: false, label: t('global.inactive') },
        ]},
        { key: 'only_favorites', label: t('global.only_favorites'), type: 'switch' },
        { key: 'created_at',  label: t('global.created_at'), type: 'date_range',   visible: false },
        { key: 'updated_at',  label: t('global.updated_at'), type: 'date_range',   visible: false },
        { key: 'id_range',    label: 'ID',                   type: 'number_range', visible: false },
    ];
};

export const localesEmptyFilters = () => ({
    name: [],
    code: [],
    language_id: [],
    is_active: null,
    created_at: null,
    updated_at: null,
    id_range: null,
    only_favorites: false,
});

export const hydrateLocalesFilters = (sf) => ({
    name:        Array.isArray(sf.name) ? sf.name : [],
    code:        Array.isArray(sf.code) ? sf.code : (sf.code ? [sf.code] : []),
    language_id: Array.isArray(sf.language_id) ? sf.language_id.map(Number) : (sf.language_id ? [Number(sf.language_id)] : []),
    is_active:   sf.is_active ?? null,
    created_at:  dateRangeFromISO(sf.created_from, sf.created_to),
    updated_at:  dateRangeFromISO(sf.updated_from, sf.updated_to),
    id_range:    (sf.id_from || sf.id_to) ? [sf.id_from || null, sf.id_to || null] : null,
    only_favorites: sf.only_favorites ?? false,
});

export const localesFiltersToQuery = (f, sf) => ({
    name:           f.name?.length ? f.name : undefined,
    code:           f.code?.length ? f.code : undefined,
    language_id:    f.language_id?.length ? f.language_id : undefined,
    is_active:      f.is_active ?? undefined,
    created_from:   f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:     f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    updated_from:   f.updated_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    updated_to:     f.updated_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    id_from:        f.id_range?.[0] ?? undefined,
    id_to:          f.id_range?.[1] ?? undefined,
    only_favorites: f.only_favorites ? 1 : undefined,
    sort:           sf.sort,
    direction:      sf.direction,
    per_page:       sf.per_page,
});

export const localesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)        parts.push(`${t('locales.name')}: ${f.name.join(', ')}`);
    if (f.code?.length)        parts.push(`${t('locales.code')}: ${f.code.join(', ')}`);
    if (f.language_id?.length) parts.push(`${t('locales.language')}: ${f.language_id.length}`);
    if (f.is_active === true)  parts.push(`${t('locales.is_active')}: ${t('global.active')}`);
    if (f.is_active === false) parts.push(`${t('locales.is_active')}: ${t('global.inactive')}`);
    if (f.created_at?.[0])     parts.push(`${t('global.created_at')}: ${f.created_at[0].format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD') ?? ''}`);
    if (f.updated_at?.[0])     parts.push(`${t('global.updated_at')}: ${f.updated_at[0].format('YYYY-MM-DD')} → ${f.updated_at[1]?.format('YYYY-MM-DD') ?? ''}`);
    if (f.id_range?.[0] != null || f.id_range?.[1] != null) {
        parts.push(`${t('global.filter_summary_id')}: ${f.id_range[0] ?? ''} – ${f.id_range[1] ?? ''}`);
    }
    return parts.join(' · ');
};

export const serializeSavedFilters = (f) => ({
    name:        f.name ?? [],
    code:        f.code ?? [],
    language_id: f.language_id ?? [],
    is_active:   f.is_active ?? null,
    created_at:  dateRangeToISO(f.created_at),
    updated_at:  dateRangeToISO(f.updated_at),
    id_range:    f.id_range ?? null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:        Array.isArray(f.name) ? f.name : [],
    code:        Array.isArray(f.code) ? f.code : [],
    language_id: Array.isArray(f.language_id) ? f.language_id.map(Number) : [],
    is_active:   f.is_active ?? null,
    created_at:  f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    updated_at:  f.updated_at?.[0] ? [dayjs(f.updated_at[0]), dayjs(f.updated_at[1])] : null,
    id_range:    f.id_range ?? null,
    only_favorites: f.only_favorites ?? false,
});
