import { ApartmentOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Documentos.
 *
 * El ID y el slug NO se muestran en el listado (datos técnicos): solo el super
 * los ve, y únicamente en el drawer de detalle y en el Show.
 *
 * La columna «Publicación» es la que faltaba: el listado enseñaba activo/
 * inactivo, que aquí no es lo que importa. Lo que decide si un plan puede usar
 * un documento es si está publicado, y eso no se veía por ninguna parte — se
 * podía tener el catálogo entero en borrador sin enterarse.
 *
 * `isSuper` agrega la columna Workspace (tenant): el super ve documentos
 * cross-tenant, así que necesita saber de qué workspace es cada uno. El admin
 * solo ve los suyos, la columna sería redundante (y por eso tampoco aparece en
 * el selector de columnas).
 */
export const formTemplatesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                      dataIndex: 'is_favorite', key: 'favorite',   width: 52,  align: 'center', alwaysVisible: true, mobile: { role: 'pin' } },
    // Celda principal "rica": nombre + código como subtítulo (el código
    // ya no es columna aparte, va fundido aquí).
    { title: t('form_templates.name'),     dataIndex: 'name',        key: 'name',       sorter: (a, b) => a.name.localeCompare(b.name), alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('form_templates.status'),   dataIndex: 'status',      key: 'publication', width: 150, sorter: true, mobile: { role: 'status' } },
    { title: t('form_templates.kind'),     dataIndex: 'kind',        key: 'kind',       width: 200, sorter: true, mobile: { role: 'subtitle' } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    { title: t('form_templates.is_active'), dataIndex: 'is_active',   key: 'status',     width: 150, sorter: (a, b) => Number(a.is_active) - Number(b.is_active), mobile: { role: 'meta' } },
    { title: t('global.actions'),      key: 'actions',           width: isMobile ? 56 : 150, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
