import {
    GlobalOutlined, ApartmentOutlined, EnvironmentOutlined,
    BlockOutlined, ClusterOutlined, ThunderboltOutlined, CalendarOutlined,
} from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Customers.
 *
 * `isSuper` agrega la columna Workspace (tenant): cruz-tenant, super ve customers
 * de varios workspaces. Admin solo ve los suyos, la columna seria redundante.
 *
 * `mobile.icon`: ícono del chip en vista lista/tarjetas (estilo trafos: ícono +
 * valor, sin etiqueta de texto; el nombre del campo va en el tooltip).
 */
export const customersTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                      dataIndex: 'is_favorite', key: 'favorite',   width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('customers.name'),      dataIndex: 'name',        key: 'name',       sorter: (a, b) => a.name.localeCompare(b.name), alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('customers.country'),   key: 'country',           width: 200, sorter: true, mobile: { role: 'meta', icon: GlobalOutlined } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    { title: t('customers.locations'),    dataIndex: 'locations_count',    key: 'locations_count',    width: 120, align: 'center', sorter: true, mobile: { role: 'meta', icon: EnvironmentOutlined, hideWhenZero: true } },
    { title: t('customers.areas'),        dataIndex: 'areas_count',        key: 'areas_count',        width: 100, align: 'center', sorter: true, mobile: { role: 'meta', icon: BlockOutlined, hideWhenZero: true } },
    { title: t('customers.substations'),  dataIndex: 'substations_count',  key: 'substations_count',  width: 130, align: 'center', sorter: true, mobile: { role: 'meta', icon: ClusterOutlined, hideWhenZero: true } },
    { title: t('customers.transformers'), dataIndex: 'transformers_count', key: 'transformers_count', width: 140, align: 'center', sorter: true, mobile: { role: 'meta', icon: ThunderboltOutlined, hideWhenZero: true } },
    { title: t('customers.is_active'), dataIndex: 'is_active',   key: 'status',     width: 110, align: 'center', sorter: (a, b) => (a.is_active ? 1 : 0) - (b.is_active ? 1 : 0), mobile: { role: 'status' } },
    { title: t('global.created_at'),   dataIndex: 'created_at',  key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    // En pantalla chica (tabla) las acciones se colapsan en un kebab → columna angosta.
    { title: t('global.actions'),      key: 'actions',           width: isMobile ? 56 : 200, fixed: 'right', align: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
