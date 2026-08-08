/**
 * Columnas exportables. Independientes de las visibles en pantalla: el usuario
 * elige en el diálogo qué se lleva.
 *
 * `tenant` solo se le ofrece al super — el backend lo vuelve a comprobar, esto
 * es solo para no listar una columna que le van a rechazar.
 */
export const approverRolesExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'code',       label: t('approver_roles.code'),       default: true  },
    { key: 'name_es',    label: t('approver_roles.name_es'),    default: true  },
    { key: 'name_en',    label: t('approver_roles.name_en'),    default: true  },
    { key: 'sort_order', label: t('approver_roles.sort_order'), default: true  },
    { key: 'is_active',  label: t('approver_roles.is_active'),  default: true  },
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
    { key: 'created_at', label: t('global.created_at'), default: false },
    { key: 'updated_at', label: t('global.updated_at'), default: false },
];

export const approverRolesExportEndpoints = () => ({
    excel: route('business_management.approver_roles.export_excel'),
    pdf:   route('business_management.approver_roles.export_pdf'),
    word:  route('business_management.approver_roles.export_word'),
    csv:   route('business_management.approver_roles.export_csv'),
});
