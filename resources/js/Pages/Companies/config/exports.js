/**
 * Columnas exportables del módulo Companies. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 *
 * Sin ID (no se exporta). `tenant` (workspace) SOLO se ofrece a super: el resto
 * ve únicamente marcas de su propio tenant. El backend lo gatea igual
 * (seguridad real); esto es solo para no listarla en el ExportDialog.
 */
export const companiesExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'name',       label: t('companies.name'),      default: true  },
    { key: 'num_doc',       label: t('companies.num_doc'),      default: true  },
    { key: 'complete_name', label: t('companies.complete_name'), default: true  },
    { key: 'is_active',  label: t('companies.is_active'), default: true  },
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
    { key: 'created_at', label: t('global.created_at'),   default: true  },
    { key: 'updated_at', label: t('global.updated_at'),   default: false },
    { key: 'creator',    label: t('global.created_by'),   default: false },
];

export const companiesExportEndpoints = () => ({
    excel: route('business_management.companies.export_excel'),
    pdf:   route('business_management.companies.export_pdf'),
    word:  route('business_management.companies.export_word'),
    csv:   route('business_management.companies.export_csv'),
});
