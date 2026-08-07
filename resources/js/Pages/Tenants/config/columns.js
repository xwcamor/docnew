import { CrownOutlined, TeamOutlined, CalendarOutlined } from '@ant-design/icons-vue';
/**
 * Columnas de la tabla principal de Tenants.
 */
export const tenantsTableColumns = (t, { isMobile = false } = {}) => [
    { title: '★',                    dataIndex: 'is_favorite', key: 'favorite',   width: 44,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('tenants.name'),      dataIndex: 'name',        key: 'name',       sorter: true, ellipsis: true, alwaysVisible: true, mobile: { role: 'title' } },
    // plan: no sortable — se deriva de la suscripción vigente, no es columna SQL (A2).
    { title: t('tenants.plan'),      dataIndex: 'plan',        key: 'plan',       sorter: false, width: 95,  mobile: { role: 'meta', icon: CrownOutlined } },
    { title: t('tenants.users_count'), dataIndex: 'users_count', key: 'users_count', sorter: false, width: 80, mobile: { role: 'meta', icon: TeamOutlined }, defaultHidden: false },
    { title: t('tenants.is_active'), dataIndex: 'is_active',   key: 'status',     sorter: true, width: 100, mobile: { role: 'status' } },
    { title: t('global.created_at'), dataIndex: 'created_at',  key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'),    key: 'actions',           width: isMobile ? 56 : 100, fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
