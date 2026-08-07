/**
 * Columnas exportables del módulo Automations. Las keys deben coincidir
 * exactamente con la allow-list del backend (AutomationController::buildExportOptions).
 */
export const automationsExportableColumns = (t) => [
    { key: 'name',           label: t('automations.name'),           default: true  },
    { key: 'description',    label: t('automations.description'),    default: false },
    { key: 'is_active',      label: t('automations.is_active'),      default: true  },
    { key: 'trigger',        label: t('automations.col_trigger'),    default: true  },
    { key: 'data_source',    label: t('automations.data_source'),    default: true  },
    { key: 'action_type',    label: t('automations.action_type'),    default: true  },
    { key: 'runs_count',     label: t('automations.col_runs'),       default: false },
    { key: 'failures_count', label: t('automations.col_failures'),   default: false },
    { key: 'last_run_at',    label: t('automations.col_last_run'),   default: false },
    { key: 'next_run_at',    label: t('automations.col_next_run'),   default: false },
    { key: 'created_at',     label: t('global.created_at'),          default: true  },
    { key: 'updated_at',     label: t('global.updated_at'),          default: false },
    { key: 'creator',        label: t('global.created_by'),          default: false },
];

/**
 * Endpoints reales — apuntan a las rutas implementadas en
 * `routes/automation_management.php` (export_csv / export_excel / export_pdf
 * / export_word). El ExportDialog usa este mapeo para POST al backend.
 */
export const automationsExportEndpoints = () => ({
    excel: route('automation_management.automations.export_excel'),
    pdf:   route('automation_management.automations.export_pdf'),
    word:  route('automation_management.automations.export_word'),
    csv:   route('automation_management.automations.export_csv'),
});
