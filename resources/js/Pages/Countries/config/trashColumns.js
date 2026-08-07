/**
 * Columnas de la tabla de Trash. Específicas: deleter + deleted_at +
 * deleted_description que no aparecen en la tabla principal.
 */
export const countriesTrashColumns = (t) => [
    { title: 'ID',                           dataIndex: 'id',                  key: 'id',          width: 80,  mobile: { role: 'meta' } },
    { title: t('countries.name'),              dataIndex: 'name',                key: 'name',        ellipsis: true, mobile: { role: 'title' } },
    { title: t('global.deleted_by'),         dataIndex: ['deleter', 'name'],   key: 'deleter',     width: 180, mobile: { role: 'meta' } },
    { title: t('global.deleted_at'),         dataIndex: 'deleted_at',          key: 'deleted_at',  width: 180, mobile: { role: 'meta' } },
    { title: t('global.delete_description'), dataIndex: 'deleted_description', key: 'reason',      ellipsis: true, mobile: { role: 'subtitle' } },
    { title: t('global.actions'),            key: 'actions',                   width: 140, fixed: 'right', mobile: { role: 'actions' } },
];
