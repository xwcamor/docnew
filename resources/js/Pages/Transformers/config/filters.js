import dayjs from 'dayjs';

/**
 * Schema de filtros del módulo Transformers. Además del serial, expone los
 * campos de dominio: tag, cliente, tipo de trafo, aceite, fases, estado de
 * salud, y rangos de tensión / potencia / año.
 *
 * Las opciones de los multiselects (cliente/tipo/aceite) vienen del controller.
 */
const phasesOptions = (t) => [
    { value: 'single', label: t('transformers.phases_single') },
    { value: 'two',    label: t('transformers.phases_two') },
    { value: 'three',  label: t('transformers.phases_three') },
];

// value = rating ENTERO guardado en transformers.health_rating · label = traducida.
// 4 = Muy Bueno … 0 = Muy Malo (canónico neutro de idioma + ordenable).
const healthRatingOptions = (t) => [
    { value: 4, label: t('diagnostics.cond_muy_bueno') },
    { value: 3, label: t('diagnostics.cond_bueno') },
    { value: 2, label: t('diagnostics.cond_medio') },
    { value: 1, label: t('diagnostics.cond_malo') },
    { value: 0, label: t('diagnostics.cond_muy_malo') },
];

// Shim: saved views viejos guardaban el texto ('Muy Bueno'…) → rating.
const HEALTH_TEXT_TO_RATING = { 'Muy Bueno': 4, 'Bueno': 3, 'Medio': 2, 'Malo': 1, 'Muy Malo': 0 };

export const transformersFilterFields = (t, {
    customerOptions = [], transformerTypeOptions = [], oilTypeOptions = [],
    substationOptions = [], tapChangerTypeOptions = [], connectionTypeOptions = [],
    preservationOptions = [], brandOptions = [],
} = {}) => [
    // Orden alineado con las columnas del index. Salen todos al entrar EXCEPTO
    // preservación, año de fabricación y fecha de creación (visible:false), que
    // se activan desde "Configurar".
    { key: 'serial',              label: t('transformers.filter_serial'),      type: 'tags' },
    { key: 'tag',                 label: t('transformers.tag'),                type: 'text' },
    { key: 'customer_id',         label: t('transformers.customer'),           type: 'multiselect', options: customerOptions },
    { key: 'customer_substation_id', label: t('transformers.substation'),      type: 'multiselect', options: substationOptions },
    { key: 'transformer_type_id', label: t('transformers.transformer_type'),   type: 'multiselect', options: transformerTypeOptions },
    { key: 'oil_type_id',         label: t('transformers.oil_type'),           type: 'multiselect', options: oilTypeOptions },
    { key: 'brand_id',            label: t('transformers.brand'),              type: 'multiselect', options: brandOptions, visible: false },
    { key: 'voltage',             label: t('transformers.voltage_kv'),         type: 'number_range' },
    { key: 'power',               label: t('transformers.power_mva'),          type: 'number_range' },
    { key: 'phases',              label: t('transformers.phases'),             type: 'multiselect', options: phasesOptions(t) },
    { key: 'tap_changer_type_id', label: t('transformers.tap_changer_type'),   type: 'multiselect', options: tapChangerTypeOptions },
    { key: 'connection_type_id',  label: t('transformers.connection_type'),    type: 'multiselect', options: connectionTypeOptions },
    { key: 'transformer_preservation_id', label: t('transformers.preservation'), type: 'multiselect', options: preservationOptions, visible: false },
    { key: 'year',                label: t('transformers.manufacture_year'),   type: 'number_range', visible: false },
    { key: 'health_rating',      label: t('transformers.health_state'),       type: 'multiselect', options: healthRatingOptions(t) },
    { key: 'created_at',          label: t('global.created_at'),               type: 'date_range', visible: false },
    { key: 'only_favorites',      label: t('global.only_favorites'),           type: 'switch' },
];

const numOrNull = (v) => (v === '' || v === null || v === undefined ? null : Number(v));
const rangeOrNull = (a, b) => (numOrNull(a) !== null || numOrNull(b) !== null ? [numOrNull(a), numOrNull(b)] : null);

/** Estado vacío del form de filtros (también usado por clearFilters). */
export const transformersEmptyFilters = () => ({
    q: '',
    serial: [], tag: '', customer_id: [], customer_substation_id: [], transformer_type_id: [],
    oil_type_id: [], brand_id: [], connection_type_id: [], tap_changer_type_id: [], transformer_preservation_id: [],
    phases: [], health_rating: [], voltage: null, power: null, year: null,
    created_at: null, only_favorites: false, health_band: null,
});

/** Backend payload → form local (dates ISO → dayjs; rangos → [min,max]). */
export const hydrateTransformersFilters = (server) => ({
    q:                   server.q || '',
    serial:              Array.isArray(server.serial) ? server.serial : [],
    tag:                 Array.isArray(server.tag) ? (server.tag[0] ?? '') : (server.tag || ''),
    customer_id:         Array.isArray(server.customer_id) ? server.customer_id : [],
    customer_substation_id:      Array.isArray(server.customer_substation_id) ? server.customer_substation_id : [],
    transformer_type_id: Array.isArray(server.transformer_type_id) ? server.transformer_type_id : [],
    oil_type_id:         Array.isArray(server.oil_type_id) ? server.oil_type_id : [],
    brand_id:            Array.isArray(server.brand_id) ? server.brand_id : [],
    connection_type_id:  Array.isArray(server.connection_type_id) ? server.connection_type_id : [],
    tap_changer_type_id: Array.isArray(server.tap_changer_type_id) ? server.tap_changer_type_id : [],
    transformer_preservation_id: Array.isArray(server.transformer_preservation_id) ? server.transformer_preservation_id : [],
    phases:              Array.isArray(server.phases) ? server.phases : [],
    health_rating:       Array.isArray(server.health_rating) ? server.health_rating : [],
    voltage:             rangeOrNull(server.voltage_min, server.voltage_max),
    power:               rangeOrNull(server.power_min, server.power_max),
    year:                rangeOrNull(server.year_min, server.year_max),
    created_at: (server.created_from && server.created_to)
        ? [dayjs(server.created_from), dayjs(server.created_to)]
        : null,
    only_favorites: server.only_favorites ?? false,
    health_band: server.health_band ?? null,
});

/** Form local → request params para Inertia reload. */
export const transformersFiltersToQuery = (f) => ({
    q:                   f.q || undefined,
    serial:              f.serial?.length ? f.serial : undefined,
    tag:                 f.tag || undefined,
    customer_id:         f.customer_id?.length ? f.customer_id : undefined,
    customer_substation_id:      f.customer_substation_id?.length ? f.customer_substation_id : undefined,
    transformer_type_id: f.transformer_type_id?.length ? f.transformer_type_id : undefined,
    oil_type_id:         f.oil_type_id?.length ? f.oil_type_id : undefined,
    brand_id:            f.brand_id?.length ? f.brand_id : undefined,
    connection_type_id:  f.connection_type_id?.length ? f.connection_type_id : undefined,
    tap_changer_type_id: f.tap_changer_type_id?.length ? f.tap_changer_type_id : undefined,
    transformer_preservation_id: f.transformer_preservation_id?.length ? f.transformer_preservation_id : undefined,
    phases:              f.phases?.length ? f.phases : undefined,
    health_rating:       f.health_rating?.length ? f.health_rating : undefined,
    health_band:         f.health_band || undefined,
    voltage_min:         f.voltage?.[0] ?? undefined,
    voltage_max:         f.voltage?.[1] ?? undefined,
    power_min:           f.power?.[0] ?? undefined,
    power_max:           f.power?.[1] ?? undefined,
    year_min:            f.year?.[0] ?? undefined,
    year_max:            f.year?.[1] ?? undefined,
    created_from:        f.created_at?.[0]?.format('YYYY-MM-DD') ?? undefined,
    created_to:          f.created_at?.[1]?.format('YYYY-MM-DD') ?? undefined,
    only_favorites:      f.only_favorites ? 1 : undefined,
});

/** Resumen legible para la portada del export PDF/Word. */
export const transformersFiltersSummary = (f, t) => {
    const parts = [];
    if (f.q)                           parts.push(`${t('global.search')}: ${f.q}`);
    if (f.serial?.length)              parts.push(`${t('transformers.filter_serial')}: ${f.serial.join(', ')}`);
    if (f.tag)                         parts.push(`${t('transformers.tag')}: ${f.tag}`);
    if (f.customer_id?.length)         parts.push(`${t('transformers.customer')}: ${f.customer_id.length}`);
    if (f.customer_substation_id?.length) parts.push(`${t('transformers.substation')}: ${f.customer_substation_id.length}`);
    if (f.transformer_type_id?.length) parts.push(`${t('transformers.transformer_type')}: ${f.transformer_type_id.length}`);
    if (f.oil_type_id?.length)         parts.push(`${t('transformers.oil_type')}: ${f.oil_type_id.length}`);
    if (f.brand_id?.length)            parts.push(`${t('transformers.brand')}: ${f.brand_id.length}`);
    if (f.connection_type_id?.length)  parts.push(`${t('transformers.connection_type')}: ${f.connection_type_id.length}`);
    if (f.tap_changer_type_id?.length) parts.push(`${t('transformers.tap_changer_type')}: ${f.tap_changer_type_id.length}`);
    if (f.transformer_preservation_id?.length) parts.push(`${t('transformers.preservation')}: ${f.transformer_preservation_id.length}`);
    if (f.phases?.length)              parts.push(`${t('transformers.phases')}: ${f.phases.length}`);
    if (f.health_rating?.length)       parts.push(`${t('transformers.health_state')}: ${f.health_rating.length}`);
    if (f.voltage)                     parts.push(`${t('transformers.voltage_kv')}: ${f.voltage[0] ?? '∞'}–${f.voltage[1] ?? '∞'}`);
    if (f.power)                       parts.push(`${t('transformers.power_mva')}: ${f.power[0] ?? '∞'}–${f.power[1] ?? '∞'}`);
    if (f.year)                        parts.push(`${t('transformers.manufacture_year')}: ${f.year[0] ?? '∞'}–${f.year[1] ?? '∞'}`);
    if (f.created_at) parts.push(`${t('global.created_at')}: ${f.created_at[0]?.format('YYYY-MM-DD')} → ${f.created_at[1]?.format('YYYY-MM-DD')}`);
    return parts.join(' · ');
};

/**
 * Serialización de filtros para Saved Views (JSON-safe: dayjs → ISO strings).
 * Round-trip con `deserializeSavedFilters`.
 */
export const serializeSavedFilters = (f) => ({
    q:                   f.q ?? '',
    serial:              f.serial ?? [],
    tag:                 f.tag ?? '',
    customer_id:         f.customer_id ?? [],
    customer_substation_id:      f.customer_substation_id ?? [],
    transformer_type_id: f.transformer_type_id ?? [],
    oil_type_id:         f.oil_type_id ?? [],
    brand_id:            f.brand_id ?? [],
    connection_type_id:  f.connection_type_id ?? [],
    tap_changer_type_id: f.tap_changer_type_id ?? [],
    transformer_preservation_id: f.transformer_preservation_id ?? [],
    phases:              f.phases ?? [],
    health_rating:       f.health_rating ?? [],
    voltage:             f.voltage ?? null,
    power:               f.power ?? null,
    year:                f.year ?? null,
    created_at:          f.created_at?.[0]
        ? [f.created_at[0].format('YYYY-MM-DD'), f.created_at[1]?.format('YYYY-MM-DD')]
        : null,
    only_favorites: !!f.only_favorites,
});

export const deserializeSavedFilters = (f = {}) => ({
    q:                   f.q ?? '',
    serial:              Array.isArray(f.serial) ? f.serial : [],
    tag:                 f.tag ?? '',
    customer_id:         Array.isArray(f.customer_id) ? f.customer_id : [],
    customer_substation_id:      Array.isArray(f.customer_substation_id) ? f.customer_substation_id : [],
    transformer_type_id: Array.isArray(f.transformer_type_id) ? f.transformer_type_id : [],
    oil_type_id:         Array.isArray(f.oil_type_id) ? f.oil_type_id : [],
    brand_id:            Array.isArray(f.brand_id) ? f.brand_id : [],
    connection_type_id:  Array.isArray(f.connection_type_id) ? f.connection_type_id : [],
    tap_changer_type_id: Array.isArray(f.tap_changer_type_id) ? f.tap_changer_type_id : [],
    transformer_preservation_id: Array.isArray(f.transformer_preservation_id) ? f.transformer_preservation_id : [],
    phases:              Array.isArray(f.phases) ? f.phases : [],
    // Nuevo: health_rating numérico. Viejo (compat): health_state texto → rating.
    health_rating:       Array.isArray(f.health_rating) ? f.health_rating
        : (Array.isArray(f.health_state)
            ? f.health_state.map((s) => HEALTH_TEXT_TO_RATING[s]).filter((r) => r !== undefined)
            : []),
    voltage:             Array.isArray(f.voltage) ? f.voltage : null,
    power:               Array.isArray(f.power) ? f.power : null,
    year:                Array.isArray(f.year) ? f.year : null,
    created_at:          f.created_at?.[0] ? [dayjs(f.created_at[0]), dayjs(f.created_at[1])] : null,
    only_favorites:      f.only_favorites ?? false,
    health_band:         f.health_band ?? null,
});
