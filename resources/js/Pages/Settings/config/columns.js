import { KeyOutlined, ProfileOutlined, FileTextOutlined, FolderOutlined, LockOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Settings.
 *
 * `mobile.role` determina cómo cada columna se renderiza en card-view:
 *   pin / title / status / meta / actions / hidden
 *
 * `alwaysVisible` excluye del ColumnSelector. `defaultHidden` arranca apagada
 * (el usuario la habilita desde "Adaptar columnas").
 */
export const settingsTableColumns = (t, { isMobile = false } = {}) => [
    { title: '★',                    dataIndex: 'is_favorite', key: 'favorite',  width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('settings.name'),      dataIndex: 'name',        key: 'name',       sorter: true, ellipsis: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('settings.key'),       dataIndex: 'key',         key: 'key',        sorter: true, ellipsis: true, width: 240, mobile: { role: 'meta', icon: KeyOutlined } },
    { title: t('settings.type'),      dataIndex: 'type',        key: 'type',       sorter: true, width: 100, mobile: { role: 'meta', icon: ProfileOutlined } },
    { title: t('settings.value'),     dataIndex: 'value',       key: 'value',      ellipsis: true, width: 240, mobile: { role: 'meta', icon: FileTextOutlined } },
    { title: t('settings.group'),     dataIndex: 'group',       key: 'group',      sorter: true, width: 130, defaultHidden: true, mobile: { role: 'meta', icon: FolderOutlined } },
    { title: t('settings.is_secret'), dataIndex: 'is_secret',   key: 'is_secret',  width: 100, defaultHidden: true, mobile: { role: 'meta', icon: LockOutlined } },
    { title: t('settings.is_active'), dataIndex: 'is_active',   key: 'status',     sorter: true, width: 130, mobile: { role: 'status' } },
    { title: t('global.created_at'), dataIndex: 'created_at',  key: 'created_at', sorter: true, width: 180, defaultHidden: true, mobile: { role: 'hidden' } },
    { title: t('global.actions'),    key: 'actions',           width: isMobile ? 56 : 100, fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
