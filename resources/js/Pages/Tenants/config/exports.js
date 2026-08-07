export const tenantsExportableColumns = (t) => [
    { key: 'name',       label: t('tenants.name'),       default: true  },
    { key: 'plan',       label: t('tenants.plan'),       default: true  },
    { key: 'is_active',  label: t('tenants.is_active'),  default: true  },
    { key: 'created_at', label: t('global.created_at'),  default: true  },
    { key: 'updated_at', label: t('global.updated_at'),  default: false },
    { key: 'creator',    label: t('global.created_by'),  default: true  },
];

export const tenantsExportEndpoints = () => ({
    excel: route('system_management.tenants.export_excel'),
    pdf:   route('system_management.tenants.export_pdf'),
    word:  route('system_management.tenants.export_word'),
    csv:   route('system_management.tenants.export_csv'),
});
