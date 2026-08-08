import { ApartmentOutlined, CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas del listado de puestos de trabajo.
 *
 * El orden por defecto es alfabético y no la fecha de alta: un catálogo se lee
 * buscando una fila concreta, no viendo qué se creó ayer.
 *
 * `isSuper` añade la columna Workspace, porque el super ve el catálogo global y
 * el de cada workspace mezclados y necesita distinguirlos.
 */
export const workstationsTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: t('workstations.name'), dataIndex: 'name', key: 'name', sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    // La sede es lo que distingue dos puestos con el mismo nombre:
    // sin ella la lista de 16 filas se lee como 16 «Tablero».
    { title: t('workstations.work_location_id'), dataIndex: ['work_location', 'name'], key: 'work_location', width: 200, mobile: { role: 'subtitle' } },
    { title: t('workstations.usage_count'), dataIndex: 'usage_count', key: 'usage_count', width: 190, align: 'center', mobile: { role: 'meta' } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    { title: t('workstations.is_active'), dataIndex: 'is_active', key: 'status', width: 130, sorter: true, mobile: { role: 'status' } },
    { title: t('global.created_at'), dataIndex: 'created_at', key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'), key: 'actions', width: isMobile ? 56 : 120, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
