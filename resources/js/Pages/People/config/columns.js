import {
    ApartmentOutlined, CalendarOutlined, GlobalOutlined,
    IdcardOutlined, BankOutlined, ScanOutlined,
    ShopOutlined, SolutionOutlined,
} from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de People.
 *
 * Lo que se pregunta de una persona antes de mandarla a obra es siempre lo
 * mismo: quién es (apellido + nombre), qué documento tiene, en cuántas empresas
 * trabaja y si ya tiene la cara enrolada (sin biometría vigente no puede
 * firmar). Eso es lo que va visible; el resto se activa desde el selector.
 *
 * `isSuper` agrega la columna Workspace (tenant): el super ve personas de
 * varios workspaces. Para el admin sería siempre el mismo.
 *
 * Sobre el `sorter`: no es decorativo. La cabecera sale clicable **sólo** si
 * `Person::ordenesDelListado()` sabe resolver esa clave en el servidor; el
 * mismo `sorter` alimenta el desplegable de orden de las vistas guardadas, así
 * que una cabecera de adorno se convierte además en una opción de menú que no
 * hace nada. Las que se quedan sin él llevan escrito el motivo.
 */
export const peopleTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    // Sin sorter a propósito: los favoritos ya van pineados arriba SIEMPRE
    // (`orderByFavoriteFirst` manda sobre el orden que se elija), así que
    // ordenar por la estrella no podría cambiar nada.
    { title: '★',                        dataIndex: 'is_favorite', key: 'favorite', width: 52, align: 'center', alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('people.full_name'),      dataIndex: 'lastname',    key: 'person',   sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('people.document'),       key: 'document',          width: 180, sorter: true, mobile: { role: 'meta', icon: IdcardOutlined } },
    { title: t('people.companies'),      dataIndex: 'company_links_count', key: 'companies_count', width: 130, align: 'center', sorter: true, mobile: { role: 'meta', icon: BankOutlined, hideWhenZero: true } },
    { title: t('people.biometric'),      key: 'biometric',         width: 150, align: 'center', sorter: true, mobile: { role: 'meta', icon: ScanOutlined } },
    // Sin sorter a propósito: una persona lleva varios roles a la vez
    // (trabajador + supervisor HSE), o sea que la celda es una lista y no hay
    // un valor por el que ordenar. Para buscar por rol está el filtro.
    { title: t('people.roles'),          key: 'roles',             width: 210, mobile: { role: 'subtitle' } },
    { title: t('people.is_active'),      dataIndex: 'is_active',   key: 'status',   width: 130, align: 'center', sorter: true, mobile: { role: 'status' } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    // Ocultables: útiles en escritorio, ruido en tablet.
    //
    // Empresa y cargo son lo primero que se pregunta de un trabajador («¿quién
    // es el eléctrico de tal contratista?») y el controlador ya los cargaba con
    // la persona; lo que faltaba era la columna. Van ocultas por defecto para
    // no ensanchar la tabla en la tablet, que es donde se usa.
    { title: t('people.company'),        key: 'company',           width: 200, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: ShopOutlined } },
    { title: t('people.position'),       key: 'position',          width: 170, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: SolutionOutlined } },
    { title: t('people.country'),        key: 'country',           width: 190, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: GlobalOutlined } },
    { title: t('people.signatures'),     dataIndex: 'signatures_count', key: 'signatures_count', width: 130, align: 'center', sorter: true, defaultHidden: true, mobile: { role: 'meta', hideWhenZero: true } },
    { title: t('global.created_at'),     dataIndex: 'created_at',  key: 'created_at', width: 180, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: CalendarOutlined } },
    // En pantalla chica las acciones se colapsan en un kebab → columna angosta.
    // Sin sorter: no es un dato, son botones.
    { title: t('global.actions'),        key: 'actions',           width: isMobile ? 56 : 150, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
