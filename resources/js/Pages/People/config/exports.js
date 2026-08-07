/**
 * Columnas exportables del módulo People. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 *
 * Sin ID (no se exporta). `tenant` (workspace) SOLO se ofrece a super: el resto
 * ve únicamente personas de su propio tenant. El backend lo gatea igual
 * (seguridad real); esto es solo para no listarla en el ExportDialog.
 */
export const peopleExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'lastname',        label: t('people.lastname'),   default: true  },
    { key: 'name',            label: t('people.name'),       default: true  },
    { key: 'doc_type',        label: t('people.doc_type'),   default: true  },
    { key: 'num_doc',         label: t('people.num_doc'),    default: true  },
    { key: 'country',         label: t('people.country'),    default: false },
    { key: 'roles',           label: t('people.roles'),      default: true  },
    { key: 'companies',       label: t('people.companies'),  default: true  },
    { key: 'companies_count', label: t('people.companies_count'), default: false },
    { key: 'has_biometric',   label: t('people.biometric'),  default: true  },
    { key: 'is_active',       label: t('people.is_active'),  default: true  },
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
    { key: 'created_at',      label: t('global.created_at'), default: true  },
    { key: 'updated_at',      label: t('global.updated_at'), default: false },
    { key: 'creator',         label: t('global.created_by'), default: false },
];

export const peopleExportEndpoints = () => ({
    excel: route('business_management.people.export_excel'),
    pdf:   route('business_management.people.export_pdf'),
    word:  route('business_management.people.export_word'),
    csv:   route('business_management.people.export_csv'),
});
