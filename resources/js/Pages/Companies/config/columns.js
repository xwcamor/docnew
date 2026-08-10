import {
    ApartmentOutlined, GlobalOutlined,
    TeamOutlined, ScheduleOutlined, IdcardOutlined,
} from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Companies (empresas contratistas).
 *
 * La celda principal junta el nombre comercial y la razón social; al lado va el
 * documento, con su tipo delante — un número suelto no dice si es un RUC
 * peruano o un RUT chileno, y el módulo es multi-país. La razón social completa
 * casi nunca hace falta a simple vista, así que su columna va oculta.
 *
 * `isSuper` agrega la columna Workspace (tenant): el super ve empresas de
 * varios workspaces. Para el admin sería siempre el mismo.
 */
export const companiesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                          dataIndex: 'is_favorite',   key: 'favorite', width: 52, align: 'center', alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('companies.name'),          dataIndex: 'name',          key: 'name',     sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    // Tipo y numero juntos, como en personas: un numero suelto no dice si es
    // un RUC, un RUT o un CUIT, y el modulo es multi-pais.
    { title: t('companies.document'),      key: 'document',            width: 190, sorter: true, mobile: { role: 'meta', icon: IdcardOutlined } },
    { title: t('companies.country'),       key: 'country',             width: 190, sorter: true, mobile: { role: 'meta', icon: GlobalOutlined } },
    { title: t('companies.people_count'),  dataIndex: 'people_count',  key: 'people_count', width: 120, align: 'center', sorter: true, mobile: { role: 'meta', icon: TeamOutlined, hideWhenZero: true } },
    { title: t('companies.plans_count'),   dataIndex: 'work_plans_count', key: 'work_plans_count', width: 120, align: 'center', sorter: true, mobile: { role: 'meta', icon: ScheduleOutlined, hideWhenZero: true } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    { title: t('companies.complete_name'), dataIndex: 'complete_name', key: 'complete_name', width: 320, ellipsis: true, sorter: true, defaultHidden: true, mobile: { role: 'subtitle' } },
    { title: t('companies.is_active'),     dataIndex: 'is_active',     key: 'status',   width: 130, align: 'center', sorter: true, mobile: { role: 'status' } },
    // En pantalla chica las acciones se colapsan en un kebab → columna angosta.
    { title: t('global.actions'),          key: 'actions',             width: isMobile ? 56 : 150, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
