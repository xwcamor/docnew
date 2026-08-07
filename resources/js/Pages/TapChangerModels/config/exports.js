/**
 * Columnas exportables del módulo TapChangerModels. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 */
export const tapChangerModelsExportableColumns = (t, { isSuper = false } = {}) => [
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
    { key: 'name',       label: t('tap_changer_models.name'),      default: true  },
    { key: 'code',       label: t('tap_changer_models.code'),      default: true  },
    { key: 'sort_order', label: t('tap_changer_models.sort_order'), default: true  },
    { key: 'is_active',  label: t('tap_changer_models.is_active'), default: true  },
    { key: 'created_at', label: t('global.created_at'),   default: true  },
    { key: 'updated_at', label: t('global.updated_at'),   default: false },
    { key: 'creator',    label: t('global.created_by'),   default: false },
];

export const tapChangerModelsExportEndpoints = () => ({
    excel: route('business_management.tap_changer_models.export_excel'),
    pdf:   route('business_management.tap_changer_models.export_pdf'),
    word:  route('business_management.tap_changer_models.export_word'),
    csv:   route('business_management.tap_changer_models.export_csv'),
});
