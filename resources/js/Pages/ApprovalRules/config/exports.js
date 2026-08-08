/**
 * Columnas exportables. El CSV sale con las mismas columnas que espera la
 * plantilla de importación, para que exportar y volver a importar funcione.
 */
export const approvalRulesExportableColumns = (t, { isSuper = false } = {}) => [
    { key: 'country',        label: t('approval_rules.country'),        default: true  },
    { key: 'work_type',      label: t('approval_rules.work_type'),      default: true  },
    { key: 'approver_role',  label: t('approval_rules.approver_role'),  default: true  },
    { key: 'priority_level', label: t('approval_rules.priority_level'), default: true  },
    { key: 'is_required',    label: t('approval_rules.is_required'),    default: true  },
    { key: 'is_active',      label: t('approval_rules.is_active'),      default: true  },
    { key: 'created_at', label: t('global.created_at'), default: false },
    { key: 'updated_at', label: t('global.updated_at'), default: false },
];

export const approvalRulesExportEndpoints = () => ({
    excel: route('business_management.approval_rules.export_excel'),
    pdf:   route('business_management.approval_rules.export_pdf'),
    word:  route('business_management.approval_rules.export_word'),
    csv:   route('business_management.approval_rules.export_csv'),
});
