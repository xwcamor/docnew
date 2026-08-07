import { CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de SystemModules. `mobile.role` determina cómo
 * cada columna se renderiza en card-view. `alwaysVisible` excluye del
 * ColumnSelector. `defaultHidden` arranca apagada (el usuario la habilita
 * desde "Adaptar columnas").
 */
export const system_modulesTableColumns = (t, { isMobile = false } = {}) => [
    { title: '★',                    dataIndex: 'is_favorite', key: 'favorite',  width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('system_modules.name'),      dataIndex: 'name',        key: 'name',       sorter: true, ellipsis: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('system_modules.is_active'), dataIndex: 'is_active',   key: 'status',     sorter: true, width: 130, mobile: { role: 'status' } },
    { title: t('global.created_at'), dataIndex: 'created_at',  key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'),    key: 'actions',           width: isMobile ? 56 : 100, fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
