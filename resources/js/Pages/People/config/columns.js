import {
    ApartmentOutlined, CameraOutlined, GlobalOutlined,
    IdcardOutlined, BankOutlined, ScanOutlined,
    ShopOutlined, SolutionOutlined,
} from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de People.
 *
 * Lo que se pregunta de una persona antes de mandarla a obra es siempre lo
 * mismo: quién es (apellido + nombre), qué documento tiene, DE QUÉ EMPRESA es,
 * de qué país y si ya tiene la cara enrolada (sin biometría vigente no puede
 * firmar). Eso es lo que va visible; el resto se activa desde el selector.
 *
 * `isSuper` agrega la columna Workspace (tenant): el super ve personas de
 * varios workspaces. Para el admin sería siempre el mismo.
 *
 * `canMedia` agrega la columna con la cara y la firma. Va por permiso y no por
 * rol: `people.view_media` es exactamente esa pregunta, y hoy sólo lo tiene el
 * super porque el admin no lo recibe por defecto. Atarlo al rol duplicaría la
 * regla y dejaría el permiso sin efecto aquí el día que se conceda a alguien.
 *
 * Sobre el `sorter`: no es decorativo. La cabecera sale clicable **sólo** si
 * `Person::ordenesDelListado()` sabe resolver esa clave en el servidor; el
 * mismo `sorter` alimenta el desplegable de orden de las vistas guardadas, así
 * que una cabecera de adorno se convierte además en una opción de menú que no
 * hace nada. Las que se quedan sin él llevan escrito el motivo.
 */
export const peopleTableColumns = (t, { isSuper = false, isMobile = false, canMedia = false } = {}) => [
    // Sin sorter a propósito: los favoritos ya van pineados arriba SIEMPRE
    // (`orderByFavoriteFirst` manda sobre el orden que se elija), así que
    // ordenar por la estrella no podría cambiar nada.
    { title: '★',                        dataIndex: 'is_favorite', key: 'favorite', width: 52, align: 'center', alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('people.full_name'),      dataIndex: 'lastname',    key: 'person',   sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('people.document'),       key: 'document',          width: 155, sorter: true, mobile: { role: 'meta', icon: IdcardOutlined } },
    // País y empresa, a la vista. Van detrás del documento porque es con lo que
    // se identifica a alguien: el país es el del documento —forma parte de su
    // clave única— y la empresa es lo primero que se pregunta de un trabajador,
    // «¿quién es el eléctrico de tal contratista?».
    // Los anchos van apretados a proposito, y hasta el último píxel.
    //
    // Con país y empresa a la vista la tabla creció, y en una pantalla de 1366
    // —un portátil normal— la columna fija de Acciones se montaba encima de
    // Estado y partía la pastilla por la mitad. La tabla desplaza por dentro,
    // así que funcionar funcionaba, pero media pastilla cortada se lee como algo
    // roto. Se recortó de todas un poco en vez de esconder ninguna: son las que
    // se piden de un trabajador antes de mandarlo a obra y todas hacen falta.
    // «Perú» no necesita 150px ni «DNI 45871240» necesita 180.
    { title: t('people.country'),        key: 'country',           width: 110, sorter: true, mobile: { role: 'meta', icon: GlobalOutlined } },
    { title: t('people.company'),        key: 'company',           width: 160, sorter: true, mobile: { role: 'meta', icon: ShopOutlined } },
    { title: t('people.biometric'),      key: 'biometric',         width: 118, align: 'center', sorter: true, mobile: { role: 'meta', icon: ScanOutlined } },
    // La cara y la firma, sólo para quien puede verlas (`people.view_media`).
    // Sin `sorter`: no es un dato con orden, son dos imágenes.
    ...(canMedia ? [
        { title: t('people.media_title'), key: 'media', width: 108, align: 'center', mobile: { role: 'meta', icon: CameraOutlined } },
    ] : []),
    // Ordena por el PRIMER rol alfabéticamente, que es el que la celda enseña
    // primero: una persona lleva varios a la vez, así que no hay un valor único,
    // pero agrupar por rol es justo para lo que se pulsa esa cabecera.
    { title: t('people.roles'),          key: 'roles',             width: 155, sorter: true, mobile: { role: 'subtitle' } },
    { title: t('people.is_active'),      dataIndex: 'is_active',   key: 'status',   width: 110, align: 'center', sorter: true, mobile: { role: 'status' } },
    // Oculto desde que existe la columna Empresa: esa celda ya enseña la
    // primera y un «+2» con el resto, así que el número al lado es el mismo dato
    // dicho dos veces y una columna más de ancho en la tablet.
    { title: t('people.companies'),      dataIndex: 'company_links_count', key: 'companies_count', width: 130, align: 'center', sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: BankOutlined, hideWhenZero: true } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    // Ocultables: útiles en escritorio, ruido en tablet.
    { title: t('people.position'),       key: 'position',          width: 170, sorter: true, defaultHidden: true, mobile: { role: 'meta', icon: SolutionOutlined } },
    { title: t('people.signatures'),     dataIndex: 'signatures_count', key: 'signatures_count', width: 130, align: 'center', sorter: true, defaultHidden: true, mobile: { role: 'meta', hideWhenZero: true } },
    // En pantalla chica las acciones se colapsan en un kebab → columna angosta.
    // Sin sorter: no es un dato, son botones.
    { title: t('global.actions'),        key: 'actions',           width: isMobile ? 56 : 150, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
