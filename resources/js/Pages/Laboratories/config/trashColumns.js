/**
 * Columnas de la tabla de Trash. Especificas: deleter + deleted_at +
 * deleted_description que no aparecen en la tabla principal.
 */
export const laboratoriesTrashColumns = (t) => [
    { title: 'ID',                           dataIndex: 'id',                  key: 'id',          width: 80,  mobile: { role: 'meta' } },
    { title: t('laboratories.name'),            dataIndex: 'name',                key: 'name',        ellipsis: true, mobile: { role: 'title' } },
    { title: t('laboratories.code'),            dataIndex: 'code',                key: 'code',        width: 140, mobile: { role: 'meta' } },
    { title: t('global.deleted_by'),         dataIndex: ['deleter', 'name'],   key: 'deleter',     width: 180, mobile: { role: 'meta' } },
    { title: t('global.deleted_at'),         dataIndex: 'deleted_at',          key: 'deleted_at',  width: 180, mobile: { role: 'meta' } },
    { title: t('global.delete_description'), dataIndex: 'deleted_description', key: 'reason',      ellipsis: true, mobile: { role: 'subtitle' } },
    { title: t('global.actions'),            key: 'actions',                   width: 140, fixed: 'right', mobile: { role: 'actions' } },
];
