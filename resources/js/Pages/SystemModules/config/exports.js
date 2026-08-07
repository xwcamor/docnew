/**
 * Columnas exportables del módulo SystemModules. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 * `default: false` arranca desmarcada para no bombardear el output.
 */
export const system_modulesExportableColumns = (t) => [
    { key: 'name',       label: t('system_modules.name'),       default: true  },
    { key: 'is_active',  label: t('system_modules.is_active'),  default: true  },
    { key: 'created_at', label: t('global.created_at'),  default: true  },
    { key: 'updated_at', label: t('global.updated_at'),  default: false },
    { key: 'creator',    label: t('global.created_by'),  default: true  },
];

export const system_modulesExportEndpoints = () => ({
    excel: route('system_management.system_modules.export_excel'),
    pdf:   route('system_management.system_modules.export_pdf'),
    word:  route('system_management.system_modules.export_word'),
    csv:   route('system_management.system_modules.export_csv'),
});
