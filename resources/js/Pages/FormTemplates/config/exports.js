/**
 * Columnas exportables del módulo Documentos. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 *
 * Sin ID (no se exporta). `tenant` (workspace) SOLO se ofrece a super: el resto
 * ve únicamente documentos de su propio tenant. El backend lo gatea igual
 * (seguridad real); esto es solo para no listarla en el ExportDialog.
 */
export const formTemplatesExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'name',       label: t('form_templates.name'),      default: true  },
    { key: 'code',       label: t('form_templates.code'),      default: true  },
    { key: 'status',     label: t('form_templates.status'),    default: true  },
    { key: 'kind',       label: t('form_templates.kind'),      default: false },
    { key: 'version',    label: t('form_templates.version'),   default: true  },
    { key: 'is_active',  label: t('form_templates.is_active'), default: true  },
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
    { key: 'created_at', label: t('global.created_at'),   default: true  },
    { key: 'updated_at', label: t('global.updated_at'),   default: false },
    { key: 'creator',    label: t('global.created_by'),   default: false },
];

export const formTemplatesExportEndpoints = () => ({
    excel: route('business_management.form_templates.export_excel'),
    pdf:   route('business_management.form_templates.export_pdf'),
    word:  route('business_management.form_templates.export_word'),
    csv:   route('business_management.form_templates.export_csv'),
});
