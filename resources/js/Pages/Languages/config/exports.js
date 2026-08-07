/**
 * Columnas exportables del módulo Languages. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 * `default: false` arranca desmarcada para no bombardear el output.
 */
export const languagesExportableColumns = (t) => [
    { key: 'name',       label: t('languages.name'),        default: true  },
    { key: 'iso_code',   label: t('languages.iso_code'),    default: true  },
    { key: 'is_active',  label: t('languages.is_active'),   default: true  },
    { key: 'created_at', label: t('global.created_at'),     default: true  },
    { key: 'updated_at', label: t('global.updated_at'),     default: false },
    { key: 'creator',    label: t('global.created_by'),     default: true  },
];

export const languagesExportEndpoints = () => ({
    excel: route('system_management.languages.export_excel'),
    pdf:   route('system_management.languages.export_pdf'),
    word:  route('system_management.languages.export_word'),
    csv:   route('system_management.languages.export_csv'),
});
