/**
 * Columnas exportables del módulo TapChangerTypes. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 */
export const tapChangerTypesExportableColumns = (t) => [
    { key: 'name',       label: t('tap_changer_types.name'),      default: true  },
    { key: 'code',       label: t('tap_changer_types.code'),      default: true  },
    { key: 'sort_order', label: t('tap_changer_types.sort_order'), default: true  },
    { key: 'is_active',  label: t('tap_changer_types.is_active'), default: true  },
    { key: 'created_at', label: t('global.created_at'),   default: true  },
    { key: 'updated_at', label: t('global.updated_at'),   default: false },
    { key: 'creator',    label: t('global.created_by'),   default: false },
];

export const tapChangerTypesExportEndpoints = () => ({
    excel: route('business_management.tap_changer_types.export_excel'),
    pdf:   route('business_management.tap_changer_types.export_pdf'),
    word:  route('business_management.tap_changer_types.export_word'),
    csv:   route('business_management.tap_changer_types.export_csv'),
});
