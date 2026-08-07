/**
 * Columnas exportables del módulo WorkPlans. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 *
 * Sin ID (no se exporta). `tenant` (workspace) SOLO se ofrece a super: el resto
 * ve únicamente marcas de su propio tenant. El backend lo gatea igual
 * (seguridad real); esto es solo para no listarla en el ExportDialog.
 */
export const workPlansExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'name',       label: t('work_plans.name'),      default: true  },
    { key: 'code',       label: t('work_plans.code'),      default: true  },
    { key: 'num_os', label: t('work_plans.num_os'), default: true  },
    { key: 'is_active',  label: t('work_plans.is_active'), default: true  },
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
    { key: 'created_at', label: t('global.created_at'),   default: true  },
    { key: 'updated_at', label: t('global.updated_at'),   default: false },
    { key: 'creator',    label: t('global.created_by'),   default: false },
];

export const workPlansExportEndpoints = () => ({
    excel: route('business_management.work_plans.export_excel'),
    pdf:   route('business_management.work_plans.export_pdf'),
    word:  route('business_management.work_plans.export_word'),
    csv:   route('business_management.work_plans.export_csv'),
});
