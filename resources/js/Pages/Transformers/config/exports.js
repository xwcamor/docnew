/**
 * Columnas exportables del módulo Transformers. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 *
 * `tenant` (workspace) SOLO se ofrece a super: el resto ve únicamente
 * transformadores de su propio tenant. El backend lo gatea igual (seguridad
 * real); esto es solo para no listarla en el ExportDialog.
 */
export const transformersExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'serial',           label: t('transformers.serial'),            default: true  },
    { key: 'tag',              label: t('transformers.tag'),               default: true  },
    { key: 'customer',         label: t('transformers.customer'),          default: true  },
    { key: 'substation',       label: t('transformers.substation'),        default: true  },
    { key: 'transformer_type', label: t('transformers.transformer_type'),  default: true  },
    { key: 'oil_type',         label: t('transformers.oil_type'),          default: true  },
    { key: 'brand',            label: t('transformers.brand'),             default: true  },
    { key: 'connection_type',  label: t('transformers.connection_type'),   default: true  },
    { key: 'tap_changer_type', label: t('transformers.tap_changer_type'),  default: true  },
    { key: 'preservation',     label: t('transformers.preservation'),      default: false },
    { key: 'voltage_kv',       label: t('transformers.voltage_kv'),        default: true  },
    { key: 'power_mva',        label: t('transformers.power_mva'),         default: true  },
    { key: 'manufacture_year', label: t('transformers.manufacture_year'),  default: true  },
    { key: 'phases',           label: t('transformers.phases'),            default: false },
    { key: 'paper_type',       label: t('transformers.paper_type'),        default: false },
    { key: 'health_index',     label: t('transformers.health_index'),      default: true  },
    { key: 'health_state',     label: t('transformers.health_state'),      default: true  },
    { key: 'created_at',       label: t('global.created_at'),              default: false },
    { key: 'updated_at',       label: t('global.updated_at'),              default: false },
    { key: 'creator',          label: t('global.created_by'),              default: false },
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
];

export const transformersExportEndpoints = () => ({
    excel: route('business_management.transformers.export_excel'),
    pdf:   route('business_management.transformers.export_pdf'),
    word:  route('business_management.transformers.export_word'),
    csv:   route('business_management.transformers.export_csv'),
});
