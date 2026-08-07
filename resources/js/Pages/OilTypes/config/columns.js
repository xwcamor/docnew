import { TagOutlined, CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de OilTypes.
 *
 * El ID y el slug NO se muestran en el listado (datos técnicos): solo el super
 * los ve, y únicamente en el drawer de detalle y en el Show.
 */
export const oilTypesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                      dataIndex: 'is_favorite', key: 'favorite',   width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('oil_types.name'),     dataIndex: 'name',        key: 'name',       sorter: (a, b) => a.name.localeCompare(b.name), alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('oil_types.code'),     dataIndex: 'code',        key: 'code',       sorter: true, width: 160, mobile: { role: 'meta', icon: TagOutlined }, defaultHidden: true },
    { title: t('oil_types.is_active'), dataIndex: 'is_active',   key: 'status',     sorter: true, width: 110, align: 'center', mobile: { role: 'status' } },
    { title: t('oil_types.rules'),    dataIndex: 'has_rules',   key: 'rules',      width: 140, align: 'center', mobile: { role: 'meta', icon: TagOutlined } },
    { title: t('global.created_at'),   dataIndex: 'created_at',  key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'),      key: 'actions',           width: isMobile ? 56 : 200, fixed: 'right', align: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
