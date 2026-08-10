import { KeyOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de SystemModules. `mobile.role` determina cómo
 * cada columna se renderiza en card-view. `alwaysVisible` excluye del
 * ColumnSelector. `defaultHidden` arranca apagada (el usuario la habilita
 * desde "Adaptar columnas").
 */
export const system_modulesTableColumns = (t, { isMobile = false } = {}) => [
    { title: '★',                    dataIndex: 'is_favorite', key: 'favorite',  width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('system_modules.name'),      dataIndex: 'name',        key: 'name',       sorter: true, ellipsis: true, alwaysVisible: true, mobile: { role: 'title' } },
    // La clave de permiso es LO que identifica al módulo: es el prefijo de los
    // siete permisos que se reparten en los perfiles. Sin ella la lista era una
    // columna de nombres sin manera de saber qué gatea cada fila.
    { title: t('system_modules.permission_key'), dataIndex: 'permission_key', key: 'permission_key', sorter: true, ellipsis: true, width: 220, mobile: { role: 'subtitle', icon: KeyOutlined } },
    { title: t('system_modules.is_active'), dataIndex: 'is_active',   key: 'status',     sorter: true, width: 130, mobile: { role: 'status' } },
    { title: t('global.actions'),    key: 'actions',           width: isMobile ? 56 : 100, fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
