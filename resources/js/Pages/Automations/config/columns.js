import { ApartmentOutlined, ThunderboltOutlined, SendOutlined, FieldTimeOutlined, ReloadOutlined, CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Automations. `mobile.role` determina cómo
 * cada columna se renderiza en card-view. `alwaysVisible` excluye del
 * ColumnSelector. `defaultHidden` arranca apagada (el usuario la habilita
 * desde "Adaptar columnas").
 *
 * `isSuper` se pasa para inyectar la columna `workspace` solo cuando aplica
 * — el admin ve un workspace unico y no le sirve esa columna; al super sí
 * porque ve automatizaciones cross-tenant.
 */
export const automationsTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                          dataIndex: 'is_favorite', key: 'favorite',   width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('automations.col_name'),    dataIndex: 'name',        key: 'name',       sorter: true, ellipsis: true, alwaysVisible: true, mobile: { role: 'title' } },
    ...(isSuper ? [
        { title: t('automations.col_workspace'), key: 'workspace', dataIndex: ['tenant', 'name'], sorter: true, width: 160, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    { title: t('automations.col_trigger'), key: 'trigger',                              mobile: { role: 'meta', icon: ThunderboltOutlined } },
    { title: t('automations.col_action'),  dataIndex: 'action_type', key: 'action',     width: 160, mobile: { role: 'meta', icon: SendOutlined } },
    { title: t('automations.col_status'),  dataIndex: 'is_active',   key: 'status',     sorter: true, width: 110, align: 'center', mobile: { role: 'status' } },
    { title: t('automations.col_next_run'),dataIndex: 'next_run_at', key: 'next_run',   sorter: true, width: 180, mobile: { role: 'meta', icon: FieldTimeOutlined } },
    { title: t('automations.col_runs'),    key: 'runs', dataIndex: 'runs_count', sorter: true, width: 110, align: 'center', mobile: { role: 'meta', icon: ReloadOutlined }, defaultHidden: true },
    { title: t('global.created_at'),       dataIndex: 'created_at',  key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'),          key: 'actions',           width: isMobile ? 56 : 220, fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
