/**
 * Columnas exportables del módulo Settings. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 * `default: false` arranca desmarcada para no bombardear el output.
 */
export const settingsExportableColumns = (t) => [
    { key: 'name',       label: t('settings.name'),       default: true  },
    { key: 'is_active',  label: t('settings.is_active'),  default: true  },
    { key: 'created_at', label: t('global.created_at'),  default: true  },
    { key: 'updated_at', label: t('global.updated_at'),  default: false },
    { key: 'creator',    label: t('global.created_by'),  default: true  },
];

export const settingsExportEndpoints = () => ({
    excel: route('system_management.settings.export_excel'),
    pdf:   route('system_management.settings.export_pdf'),
    word:  route('system_management.settings.export_word'),
    csv:   route('system_management.settings.export_csv'),
});
