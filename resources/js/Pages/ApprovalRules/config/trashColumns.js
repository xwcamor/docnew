/**
 * Columnas de la papelera: quién borró, cuándo y por qué.
 */
export const approvalRulesTrashColumns = (t) => [
    // Una regla borrada se reconoce por cómo se llamaba, no por el código de
    // su rol. El código sigue a la vista porque es lo que hay que teclear para
    // el borrado definitivo.
    { title: t('approval_rules.name'),           dataIndex: 'display_name',        key: 'display_name',   width: 240, mobile: { role: 'title' } },
    { title: t('approval_rules.approver_role'),  dataIndex: 'approver_role',       key: 'approver_role',  width: 170, mobile: { role: 'subtitle' } },
    { title: t('approval_rules.priority_level'), dataIndex: 'priority_level',      key: 'priority_level', width: 90, align: 'center', mobile: { role: 'meta' } },
    { title: t('approval_rules.work_type'),      dataIndex: ['work_type', 'code'], key: 'work_type',      width: 160, mobile: { role: 'meta' } },
    { title: t('global.deleted_by'),             dataIndex: 'deleter_name',        key: 'deleter',        width: 180, mobile: { role: 'meta' } },
    { title: t('global.deleted_at'),             dataIndex: 'deleted_at',          key: 'deleted_at',     width: 180, mobile: { role: 'meta' } },
    { title: t('global.delete_description'),     dataIndex: 'deleted_description', key: 'reason',         ellipsis: true, mobile: { role: 'subtitle' } },
    { title: t('global.actions'),                key: 'actions',                   width: 160, fixed: 'right', mobile: { role: 'actions' } },
];
