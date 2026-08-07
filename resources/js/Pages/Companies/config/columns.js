import {
    ApartmentOutlined, CalendarOutlined, GlobalOutlined,
    TeamOutlined, ScheduleOutlined,
} from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Companies (empresas contratistas).
 *
 * La celda principal junta nombre corto + RUC: es como se pide una empresa en
 * obra ("la de tal RUC"). La razón social completa casi nunca hace falta a
 * simple vista, así que va oculta por defecto.
 *
 * `isSuper` agrega la columna Workspace (tenant): el super ve empresas de
 * varios workspaces. Para el admin sería siempre el mismo.
 */
export const companiesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                          dataIndex: 'is_favorite',   key: 'favorite', width: 52, align: 'center', alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('companies.name'),          dataIndex: 'name',          key: 'name',     sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('companies.num_doc'),       dataIndex: 'num_doc',       key: 'num_doc',  width: 160, sorter: true, mobile: { role: 'meta' } },
    { title: t('companies.country'),       key: 'country',             width: 190, sorter: true, mobile: { role: 'meta', icon: GlobalOutlined } },
    { title: t('companies.people_count'),  dataIndex: 'people_count',  key: 'people_count', width: 120, align: 'center', sorter: true, mobile: { role: 'meta', icon: TeamOutlined, hideWhenZero: true } },
    { title: t('companies.plans_count'),   dataIndex: 'work_plans_count', key: 'work_plans_count', width: 120, align: 'center', sorter: true, mobile: { role: 'meta', icon: ScheduleOutlined, hideWhenZero: true } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    { title: t('companies.complete_name'), dataIndex: 'complete_name', key: 'complete_name', width: 320, ellipsis: true, sorter: true, defaultHidden: true, mobile: { role: 'subtitle' } },
    { title: t('companies.is_active'),     dataIndex: 'is_active',     key: 'status',   width: 130, align: 'center', sorter: true, mobile: { role: 'status' } },
    { title: t('global.created_at'),       dataIndex: 'created_at',    key: 'created_at', width: 180, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: CalendarOutlined } },
    // En pantalla chica las acciones se colapsan en un kebab → columna angosta.
    { title: t('global.actions'),          key: 'actions',             width: isMobile ? 56 : 150, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
