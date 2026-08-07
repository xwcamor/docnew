/**
 * Columnas exportables del módulo WorkPlans. Independientes de las visibles
 * en pantalla — el usuario elige en el ExportDialog qué columnas exportar.
 *
 * Sin ID (no se exporta). `tenant` (workspace) SOLO se ofrece a super: el resto
 * ve únicamente planes de su propio tenant. El backend lo gatea igual
 * (seguridad real); esto es solo para no listarla en el ExportDialog.
 */
export const workPlansExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'code',          label: t('work_plans.code'),          default: true  },
    { key: 'num_os',        label: t('work_plans.num_os'),        default: true  },
    { key: 'company',       label: t('work_plans.company'),       default: true  },
    { key: 'work_type',     label: t('work_plans.work_type'),     default: true  },
    { key: 'work_location', label: t('work_plans.work_location'), default: true  },
    { key: 'work_area',     label: t('work_plans.work_area'),     default: false },
    { key: 'workstation',   label: t('work_plans.workstation'),   default: false },
    { key: 'description',   label: t('work_plans.description'),   default: true  },
    { key: 'date_start',    label: t('work_plans.date_start'),    default: true  },
    { key: 'date_end',      label: t('work_plans.date_end'),      default: false },
    { key: 'is_done',       label: t('work_plans.is_done'),       default: true  },
    { key: 'is_locked',     label: t('work_plans.is_locked'),     default: false },
    { key: 'people_count',  label: t('work_plans.people_count'),  default: false },
    { key: 'registered_by', label: t('work_plans.registered_by'), default: false },
    ...(isSuper ? [{ key: 'tenant', label: t('tenants.singular'), default: false }] : []),
    { key: 'created_at',    label: t('global.created_at'),        default: true  },
    { key: 'updated_at',    label: t('global.updated_at'),        default: false },
    { key: 'creator',       label: t('global.created_by'),        default: false },
];

export const workPlansExportEndpoints = () => ({
    excel: route('business_management.work_plans.export_excel'),
    pdf:   route('business_management.work_plans.export_pdf'),
    word:  route('business_management.work_plans.export_word'),
    csv:   route('business_management.work_plans.export_csv'),
});
