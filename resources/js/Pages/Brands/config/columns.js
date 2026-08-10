import { ApartmentOutlined } from '@ant-design/icons-vue';

/**
 * Columnas de la tabla principal de Brands.
 *
 * El ID y el slug NO se muestran en el listado (datos técnicos): solo el super
 * los ve, y únicamente en el drawer de detalle y en el Show.
 *
 * `isSuper` agrega la columna Workspace (tenant): el super ve marcas cross-tenant,
 * así que necesita saber de qué workspace es cada una. El admin solo ve las suyas,
 * la columna sería redundante (y por eso tampoco aparece en el selector de columnas).
 */
export const brandsTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                      dataIndex: 'is_favorite', key: 'favorite',   width: 52,  align: 'center', alwaysVisible: true, mobile: { role: 'pin' } },
    // Celda principal "rica": avatar + nombre + código como subtítulo (el código
    // ya no es columna aparte, va fundido aquí).
    { title: t('brands.name'),     dataIndex: 'name',        key: 'name',       sorter: (a, b) => a.name.localeCompare(b.name), alwaysVisible: true, mobile: { role: 'title' } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    // Oculta por defecto: solo interesa a quien ordena el catalogo a mano. Es
    // lo que le da sentido a la columna `sort_order` de la tabla, que hasta
    // ahora solo existia en la exportacion (y salia vacia).
    { title: t('brands.sort_order'), dataIndex: 'sort_order', key: 'sort_order', width: 110, align: 'right', sorter: true, defaultHidden: true, mobile: { role: 'meta' } },
    { title: t('brands.is_active'), dataIndex: 'is_active',   key: 'status',     width: 150, sorter: (a, b) => Number(a.is_active) - Number(b.is_active), mobile: { role: 'status' } },
    { title: t('global.actions'),      key: 'actions',           width: isMobile ? 56 : 150, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
