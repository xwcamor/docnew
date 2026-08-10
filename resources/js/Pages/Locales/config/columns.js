import { TagOutlined, TranslationOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Locales.
 */
export const localesTableColumns = (t, { isMobile = false } = {}) => [
    { title: '★',                    dataIndex: 'is_favorite', key: 'favorite',  width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('locales.name'),      dataIndex: 'name',        key: 'name',       sorter: true, ellipsis: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('locales.code'),      dataIndex: 'code',        key: 'code',       sorter: true, width: 110, mobile: { role: 'meta', icon: TagOutlined }, defaultHidden: true },
    { title: t('locales.language'),  dataIndex: 'language',    key: 'language',   sorter: true, width: 180, mobile: { role: 'meta', icon: TranslationOutlined } },
    { title: t('locales.is_active'), dataIndex: 'is_active',   key: 'status',     sorter: true, width: 130, mobile: { role: 'status' } },
    // En pantalla chica (tabla) las acciones se colapsan en un kebab → columna angosta.
    { title: t('global.actions'),    key: 'actions',           width: isMobile ? 56 : 100, fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
