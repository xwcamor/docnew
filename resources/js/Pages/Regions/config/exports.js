/**
 * Columnas exportables del módulo Regions. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 * `default: false` arranca desmarcada para no bombardear el output.
 */
export const regionsExportableColumns = (t) => [
    { key: 'name',       label: t('regions.name'),       default: true  },
    { key: 'is_active',  label: t('regions.is_active'),  default: true  },
    { key: 'created_at', label: t('global.created_at'),  default: true  },
    { key: 'updated_at', label: t('global.updated_at'),  default: false },
    { key: 'creator',    label: t('global.created_by'),  default: true  },
];

export const regionsExportEndpoints = () => ({
    excel: route('system_management.regions.export_excel'),
    pdf:   route('system_management.regions.export_pdf'),
    word:  route('system_management.regions.export_word'),
    csv:   route('system_management.regions.export_csv'),
});
