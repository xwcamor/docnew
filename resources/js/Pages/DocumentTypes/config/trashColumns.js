/**
 * Columnas de la papelera: quién borró, cuándo y por qué — que es justo lo que
 * no se ve en el listado principal.
 *
 * La sigla sola («CE») no siempre basta para reconocer lo que uno borró, así
 * que el nombre largo va al lado.
 */
export const document_typesTrashColumns = (t) => [
    { title: t('document_types.code'),       dataIndex: 'code',                key: 'code',       width: 130, mobile: { role: 'title' } },
    { title: t('document_types.name'),       dataIndex: 'name',                key: 'name',       ellipsis: true, mobile: { role: 'subtitle' } },
    { title: t('global.deleted_by'),         dataIndex: 'deleter_name',        key: 'deleter',    width: 180, mobile: { role: 'meta' } },
    { title: t('global.deleted_at'),         dataIndex: 'deleted_at',          key: 'deleted_at', width: 180, mobile: { role: 'meta' } },
    { title: t('global.delete_description'), dataIndex: 'deleted_description', key: 'reason',     ellipsis: true, mobile: { role: 'meta' } },
    { title: t('global.actions'),            key: 'actions',                   width: 140, fixed: 'right', mobile: { role: 'actions' } },
];
