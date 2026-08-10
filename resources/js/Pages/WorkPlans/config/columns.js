import {
    ApartmentOutlined, CalendarOutlined, BankOutlined, ToolOutlined,
    EnvironmentOutlined, BlockOutlined, TeamOutlined, FileTextOutlined,
    UserOutlined,
} from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de WorkPlans.
 *
 * Un plan no tiene nombre: se identifica por su código (PE24-0412-0458), y la
 * orden de servicio va debajo como subtítulo. Se muestran de entrada solo las
 * cinco que sirven para encontrar un plan en una tablet en obra — código,
 * empresa, tipo de trabajo, fecha y estado; el resto queda en el selector de
 * columnas para quien las necesite en escritorio.
 *
 * `isSuper` agrega la columna Workspace (tenant): el super ve planes de varios
 * workspaces y necesita distinguirlos. Para el admin sería siempre el mismo.
 */
export const workPlansTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                            dataIndex: 'is_favorite', key: 'favorite', width: 52, align: 'center', alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('work_plans.code'),           dataIndex: 'code',        key: 'code',     width: 190, sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('work_plans.company'),        key: 'company',           width: 240, sorter: true, ellipsis: true, mobile: { role: 'subtitle', icon: BankOutlined } },
    { title: t('work_plans.work_type'),      key: 'work_type',         width: 170, sorter: true, mobile: { role: 'meta', icon: ToolOutlined } },
    { title: t('work_plans.date_start'),     dataIndex: 'date_start',  key: 'date_start', width: 130, sorter: true, mobile: { role: 'meta', icon: CalendarOutlined } },
    { title: t('work_plans.is_done'),        dataIndex: 'is_done',     key: 'status',    width: 150, sorter: true, mobile: { role: 'status' } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    // Ocultables: útiles en escritorio, ruido en tablet.
    { title: t('work_plans.num_os'),         dataIndex: 'num_os',      key: 'num_os',        width: 140, defaultHidden: true, mobile: { role: 'meta' } },
    { title: t('work_plans.work_location'),  key: 'work_location',     width: 190, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: EnvironmentOutlined } },
    { title: t('work_plans.work_area'),      key: 'work_area',         width: 170, defaultHidden: true, mobile: { role: 'meta', icon: BlockOutlined } },
    { title: t('work_plans.workstation'),    key: 'workstation',       width: 170, defaultHidden: true, mobile: { role: 'meta', icon: BlockOutlined } },
    { title: t('work_plans.description'),    dataIndex: 'description', key: 'description',   width: 320, ellipsis: true, defaultHidden: true, mobile: { role: 'subtitle' } },
    { title: t('work_plans.people_count'),   dataIndex: 'people_count', key: 'people_count', width: 120, align: 'center', defaultHidden: true, mobile: { role: 'meta', icon: TeamOutlined, hideWhenZero: true } },
    { title: t('work_plans.forms_count'),    dataIndex: 'submissions_count', key: 'submissions_count', width: 120, align: 'center', defaultHidden: true, mobile: { role: 'meta', icon: FileTextOutlined, hideWhenZero: true } },
    { title: t('work_plans.date_end'),       dataIndex: 'date_end',    key: 'date_end',      width: 130, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: CalendarOutlined } },
    { title: t('work_plans.registered_by'),  key: 'registered_by',     width: 190, defaultHidden: true, mobile: { role: 'meta', icon: UserOutlined } },
    // En pantalla chica las acciones se colapsan en un kebab → columna angosta.
    { title: t('global.actions'),            key: 'actions',           width: isMobile ? 56 : 150, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
