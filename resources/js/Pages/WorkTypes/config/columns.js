import { CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas del listado de tipos de trabajo.
 *
 * El orden por defecto es el código y no la fecha de alta: esto es un catálogo,
 * y un catálogo se busca por su nombre.
 *
 * La columna de formatos va con «obligatorios / total» y no con un número
 * suelto: dos tipos con tres formatos cada uno no exigen lo mismo si en uno son
 * los tres obligatorios y en el otro ninguno.
 */
export const workTypesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: t('work_types.code'), dataIndex: 'code', key: 'code', sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('work_types.country'), dataIndex: ['country', 'name'], key: 'country', width: 170, mobile: { role: 'subtitle' } },
    { title: t('work_types.table_headers.forms_summary'), key: 'forms', width: 210, mobile: { role: 'meta' } },
    { title: t('work_types.open_plans'), dataIndex: 'open_plans_count', key: 'open_plans', width: 130, align: 'center', mobile: { role: 'meta' } },
    { title: t('work_types.is_active'), dataIndex: 'is_active', key: 'status', width: 130, sorter: true, mobile: { role: 'status' } },
    { title: t('global.created_at'), dataIndex: 'created_at', key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'), key: 'actions', width: isMobile ? 56 : 120, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
