/**
 * Columnas exportables del módulo Customers. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 *
 * Sin ID (no se exporta). `tenant` (workspace) SOLO se ofrece a super: el resto
 * ve únicamente clientes de su propio tenant. El backend lo gatea igual
 * (seguridad real); esto es solo para no listarla en el ExportDialog.
 */
export const customersExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'name',               label: t('customers.name'),         default: true  },
    { key: 'cod',                label: t('customers.cod'),          default: true  },
    { key: 'country',            label: t('customers.country'),      default: true  },
    { key: 'address',            label: t('customers.address'),      default: true  },
    { key: 'is_active',          label: t('customers.is_active'),    default: true  },
    { key: 'locations_count',    label: t('customers.locations'),    default: true  },
    { key: 'areas_count',        label: t('customers.areas'),        default: true  },
    { key: 'substations_count',  label: t('customers.substations'),  default: true  },
    { key: 'transformers_count', label: t('customers.transformers'), default: true  },
    { key: 'created_at',         label: t('global.created_at'),      default: true  },
    { key: 'updated_at',         label: t('global.updated_at'),      default: false },
    { key: 'creator',            label: t('global.created_by'),      default: false },
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
];

export const customersExportEndpoints = () => ({
    excel: route('business_management.customers.export_excel'),
    pdf:   route('business_management.customers.export_pdf'),
    word:  route('business_management.customers.export_word'),
    csv:   route('business_management.customers.export_csv'),
});
