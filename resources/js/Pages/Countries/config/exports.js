/**
 * Columnas exportables del módulo Countries. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 * `default: false` arranca desmarcada para no bombardear el output.
 */
export const countriesExportableColumns = (t) => [
    { key: 'name',           label: t('countries.name'),             default: true  },
    { key: 'iso_code',       label: t('countries.iso_code'),         default: true  },
    { key: 'currency',       label: t('countries.currency'),         default: true  },
    { key: 'timezone',       label: t('countries.timezone'),         default: false },
    { key: 'region',         label: t('countries.region'),           default: true  },
    { key: 'default_locale', label: t('countries.default_locale'),   default: false },
    { key: 'is_active',      label: t('countries.is_active'),        default: true  },
    { key: 'created_at',     label: t('global.created_at'),          default: true  },
    { key: 'updated_at',     label: t('global.updated_at'),          default: false },
    { key: 'creator',        label: t('global.created_by'),          default: true  },
];

export const countriesExportEndpoints = () => ({
    excel: route('system_management.countries.export_excel'),
    pdf:   route('system_management.countries.export_pdf'),
    word:  route('system_management.countries.export_word'),
    csv:   route('system_management.countries.export_csv'),
});
