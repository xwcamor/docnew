export const localesExportableColumns = (t) => [
    { key: 'name',       label: t('locales.name'),       default: true  },
    { key: 'code',       label: t('locales.code'),       default: true  },
    { key: 'language',   label: t('locales.language'),   default: true  },
    { key: 'is_active',  label: t('locales.is_active'),  default: true  },
    { key: 'created_at', label: t('global.created_at'),  default: true  },
    { key: 'updated_at', label: t('global.updated_at'),  default: false },
    { key: 'creator',    label: t('global.created_by'),  default: true  },
];

export const localesExportEndpoints = () => ({
    excel: route('system_management.locales.export_excel'),
    pdf:   route('system_management.locales.export_pdf'),
    word:  route('system_management.locales.export_word'),
    csv:   route('system_management.locales.export_csv'),
});
