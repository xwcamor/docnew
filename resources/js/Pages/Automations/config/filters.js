import dayjs from 'dayjs';
import { dateRangeFromISO, dateRangeToISO } from '@/Composables/useModuleFilters';

/**
 * Schema de filtros del módulo Automations. Toma `t` y devuelve los fields
 * que FilterBar/FilterChips/Drawer consumen. Patrón Regions: `visible: false`
 * mantiene el filtro accesible vía "Adaptar filtros" sin renderizarlo
 * por default.
 *
 * Los catálogos de data_source y action_type se inyectan desde el backend
 * (catalog.data_sources y catalog.actions) — el caller llena las options.
 */
export const automationsFilterFields = (t, catalog = { data_sources: [], actions: [] }) => [
    { key: 'name',      label: t('automations.name'),      type: 'tags' },
    { key: 'is_active', label: t('automations.is_active'), type: 'select', options: [
        { value: true,  label: t('global.active')   },
        { value: false, label: t('global.inactive') },
    ]},
    { key: 'data_source', label: t('automations.data_source'), type: 'multi', options:
        (catalog.data_sources ?? []).map(s => ({ value: s.key, label: s.label })),
    },
    { key: 'action_type', label: t('automations.action_type'), type: 'multi', options:
        (catalog.actions ?? []).map(a => ({ value: a.key, label: a.label })),
    },
    { key: 'only_favorites', label: t('global.only_favorites'), type: 'switch' },
    { key: 'created_at',     label: t('global.created_at'),    type: 'date_range', visible: false },
];

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const automationsEmptyFilters = () => ({
    name: [],
    is_active: null,
    data_source: [],
    action_type: [],
    created_at: null,
    only_favorites: false,
});

/** Backend payload → form local (dates ISO → dayjs, etc). */
export const hydrateAutomationsFilters = (sf) => ({
    name:        Array.isArray(sf.name) ? sf.name : [],
    is_active:   sf.is_active ?? null,
    data_source: Array.isArray(sf.data_source) ? sf.data_source : [],
    action_type: Array.isArray(sf.action_type) ? sf.action_type : [],
    created_at:  dateRangeFromISO(sf.created_from, sf.created_to),
    only_favorites: sf.only_favorites ?? false,
});

/** Form local → request params para Inertia reload. */
export const automationsFiltersToQuery = (f, sf) => ({
    name:           f.name?.length ? f.name : undefined,
    is_active:      f.is_active ?? undefined,
    data_source:    f.data_source?.length ? f.data_source : undefined,
    action_type:    f.action_type?.length ? f.action_type : undefined,
    created_from:   f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:     f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    only_favorites: f.only_favorites ? 1 : undefined,
    sort:           sf.sort,
    direction:      sf.direction,
    per_page:       sf.per_page,
});

/** Resumen legible para incluir en portada del export PDF/Word. */
export const automationsFiltersSummary = (f, t) => {
    const parts = [];
    if (f.name?.length)        parts.push(`${t('automations.name')}: ${f.name.join(', ')}`);
    if (f.is_active === true)  parts.push(`${t('automations.is_active')}: ${t('global.active')}`);
    if (f.is_active === false) parts.push(`${t('automations.is_active')}: ${t('global.inactive')}`);
    if (f.data_source?.length) parts.push(`${t('automations.data_source')}: ${f.data_source.join(', ')}`);
    if (f.action_type?.length) parts.push(`${t('automations.action_type')}: ${f.action_type.join(', ')}`);
    if (f.created_at?.[0])     parts.push(`${t('global.created_at')}: ${f.created_at[0].format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD') ?? ''}`);
    return parts.join(' · ');
};

/**
 * Serialización de filtros para Saved Views (JSON-safe: dayjs → ISO strings).
 * Round-trip con `deserializeSavedFilters`.
 */
export const serializeSavedFilters = (f) => ({
    name:        f.name ?? [],
    is_active:   f.is_active ?? null,
    data_source: f.data_source ?? [],
    action_type: f.action_type ?? [],
    created_at:  dateRangeToISO(f.created_at),
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    name:        Array.isArray(f.name) ? f.name : [],
    is_active:   f.is_active ?? null,
    data_source: Array.isArray(f.data_source) ? f.data_source : [],
    action_type: Array.isArray(f.action_type) ? f.action_type : [],
    created_at:  f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    only_favorites: f.only_favorites ?? false,
});
