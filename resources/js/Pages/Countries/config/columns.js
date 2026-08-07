import { TagOutlined, DollarOutlined, GlobalOutlined, TranslationOutlined, ClockCircleOutlined, CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Countries. `mobile.role` determina cómo
 * cada columna se renderiza en card-view. `alwaysVisible` excluye del
 * ColumnSelector. `defaultHidden` arranca apagada (el usuario la habilita
 * desde "Adaptar columnas").
 */
export const countriesTableColumns = (t, { isMobile = false } = {}) => [
    { title: '★',                       dataIndex: 'is_favorite',  key: 'favorite',     width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('countries.name'),       dataIndex: 'name',         key: 'name',         sorter: true, ellipsis: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('countries.iso_code'),   dataIndex: 'iso_code',     key: 'iso_code',     sorter: true, width: 100, mobile: { role: 'meta', icon: TagOutlined }, defaultHidden: true },
    { title: t('countries.currency'),   dataIndex: 'currency',     key: 'currency',     sorter: true, width: 100, mobile: { role: 'meta', icon: DollarOutlined } },
    { title: t('countries.region'),     dataIndex: 'region',       key: 'region',       sorter: true, width: 160, mobile: { role: 'meta', icon: GlobalOutlined } },
    { title: t('countries.default_locale'), dataIndex: 'default_locale', key: 'default_locale', sorter: false, width: 160, mobile: { role: 'meta', icon: TranslationOutlined } },
    { title: t('countries.timezone'),   dataIndex: 'timezone',     key: 'timezone',     sorter: true, width: 200, mobile: { role: 'meta', icon: ClockCircleOutlined } },
    { title: t('countries.is_active'),  dataIndex: 'is_active',    key: 'status',       sorter: true, width: 130, mobile: { role: 'status' } },
    { title: t('global.created_at'),    dataIndex: 'created_at',   key: 'created_at',   sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    // En pantalla chica (tabla) las acciones se colapsan en un kebab → columna angosta.
    { title: t('global.actions'),       key: 'actions',            width: isMobile ? 56 : 100, fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
