import { ApartmentOutlined, CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas del listado de roles aprobadores.
 *
 * El orden por defecto es `sort_order`, no la fecha de alta: el catálogo se
 * lee como una lista ordenada — es el orden en que los roles se ofrecen al
 * armar un flujo.
 *
 * `isSuper` añade la columna Workspace, porque el super ve el catálogo global
 * y el de cada workspace mezclados y necesita distinguirlos.
 */
export const approverRolesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: t('approver_roles.sort_order'), dataIndex: 'sort_order', key: 'sort_order', width: 90, align: 'center', sorter: true, mobile: { role: 'meta' } },
    // Celda principal: nombre en español con el inglés como subtítulo.
    { title: t('approver_roles.name_es'), dataIndex: 'name_es', key: 'name_es', sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('approver_roles.code'), dataIndex: 'code', key: 'code', width: 200, sorter: true, mobile: { role: 'subtitle' } },
    { title: t('approver_roles.rules_count'), dataIndex: 'rules_count', key: 'rules_count', width: 150, align: 'center', mobile: { role: 'meta' } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    { title: t('approver_roles.is_active'), dataIndex: 'is_active', key: 'status', width: 130, sorter: true, mobile: { role: 'status' } },
    { title: t('global.created_at'), dataIndex: 'created_at', key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'), key: 'actions', width: isMobile ? 56 : 120, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
