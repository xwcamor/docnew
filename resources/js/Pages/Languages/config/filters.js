import dayjs from 'dayjs';
import { dateRangeFromISO, dateRangeToISO } from '@/Composables/useModuleFilters';

/**
 * Schema de filtros del módulo Languages. Toma `t` y devuelve los fields
 * que FilterBar/FilterChips/Drawer consumen. `visible: false` mantiene el
 * filtro en el pool (accesible vía "Adaptar filtros") sin renderizarlo
 * por default.
 */
export const languagesFilterFields = (t) => [
    { key: 'name',       label: t('languages.name'),       type: 'tags' },
    { key: 'iso_code',   label: t('languages.iso_code'),   type: 'tags' },
    { key: 'is_active',  label: t('languages.is_active'),  type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'only_favorites', label: t('global.only_favorites'), type: 'switch' },
    { key: 'created_at', label: t('global.created_at'),  type: 'date_range',   visible: false },
    { key: 'updated_at', label: t('global.updated_at'),  type: 'date_range',   visible: false },
    { key: 'id_range',   label: 'ID',                    type: 'number_range', visible: false },
];

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const languagesEmptyFilters = () => ({
    name: [],
    iso_code: [],
    is_active: null,
    created_at: null,
    updated_at: null,
    id_range: null,
    only_favorites: false,
});

/** Backend payload → form local (dates ISO → dayjs, etc). */
export const hydrateLanguagesFilters = (sf) => ({
    name:       Array.isArray(sf.name) ? sf.name : [],
    iso_code:   Array.isArray(sf.iso_code) ? sf.iso_code : (sf.iso_code ? [sf.iso_code] : []),
    is_active:  sf.is_active ?? null,
    created_at: dateRangeFromISO(sf.created_from, sf.created_to),
    updated_at: dateRangeFromISO(sf.updated_from, sf.updated_to),
    id_range:   (sf.id_from || sf.id_to) ? [sf.id_from || null, sf.id_to || null] : null,
    only_favorites: sf.only_favorites ?? false,
});

/** Form local → request params para Inertia reload. */
export const languagesFiltersToQuery = (f, sf) => ({
    name:           f.name?.length ? f.name : undefined,
    iso_code:       f.iso_code?.length ? f.iso_code : undefined,
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

/** Resumen legible para incluir en portada del export PDF/Word. */
export const languagesFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)        parts.push(`${t('languages.name')}: ${f.name.join(', ')}`);
    if (f.iso_code?.length)    parts.push(`${t('languages.iso_code')}: ${f.iso_code.join(', ')}`);
    if (f.is_active === true)  parts.push(`${t('languages.is_active')}: ${t('global.active')}`);
    if (f.is_active === false) parts.push(`${t('languages.is_active')}: ${t('global.inactive')}`);
    if (f.created_at?.[0])     parts.push(`${t('global.created_at')}: ${f.created_at[0].format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD') ?? ''}`);
    if (f.updated_at?.[0])     parts.push(`${t('global.updated_at')}: ${f.updated_at[0].format('YYYY-MM-DD')} → ${f.updated_at[1]?.format('YYYY-MM-DD') ?? ''}`);
    if (f.id_range?.[0] != null || f.id_range?.[1] != null) {
        parts.push(`${t('global.filter_summary_id')}: ${f.id_range[0] ?? ''} – ${f.id_range[1] ?? ''}`);
    }
    return parts.join(' · ');
};

/**
 * Serialización de filtros para Saved Views (JSON-safe: dayjs → ISO strings).
 * Round-trip con `deserializeSavedFilters`.
 */
export const serializeSavedFilters = (f) => ({
    name:       f.name ?? [],
    iso_code:   f.iso_code ?? [],
    is_active:  f.is_active ?? null,
    created_at: dateRangeToISO(f.created_at),
    updated_at: dateRangeToISO(f.updated_at),
    id_range:   f.id_range ?? null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:       Array.isArray(f.name) ? f.name : [],
    iso_code:   Array.isArray(f.iso_code) ? f.iso_code : (f.iso_code ? [f.iso_code] : []),
    is_active:  f.is_active ?? null,
    created_at: f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    updated_at: f.updated_at?.[0] ? [dayjs(f.updated_at[0]), dayjs(f.updated_at[1])] : null,
    id_range:   f.id_range ?? null,
    only_favorites: f.only_favorites ?? false,
});
