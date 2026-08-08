/**
 * Columnas de la papelera: quién borró, cuándo y por qué.
 */
export const workTypesTrashColumns = (t) => [
    { title: t('work_types.code'),           dataIndex: 'code',                key: 'code',       width: 200, mobile: { role: 'title' } },
    { title: t('work_types.country'),        dataIndex: ['country', 'name'],   key: 'country',    width: 170, mobile: { role: 'subtitle' } },
    { title: t('global.deleted_by'),         dataIndex: 'deleter_name',        key: 'deleter',    width: 180, mobile: { role: 'meta' } },
    { title: t('global.deleted_at'),         dataIndex: 'deleted_at',          key: 'deleted_at', width: 180, mobile: { role: 'meta' } },
    { title: t('global.delete_description'), dataIndex: 'deleted_description', key: 'reason',     ellipsis: true, mobile: { role: 'subtitle' } },
    { title: t('global.actions'),            key: 'actions',                   width: 160, fixed: 'right', mobile: { role: 'actions' } },
];
