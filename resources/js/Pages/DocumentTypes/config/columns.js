import { ApartmentOutlined } from '@ant-design/icons-vue';

/**
 * Columnas del listado de tipos de documento.
 *
 * Arriba lo que identifica —la sigla, que es lo que queda escrito en la ficha
 * de la persona— y debajo lo que matiza: el nombre largo y la longitud del
 * número (docs/UI.md §5). La longitud es ayuda al dar de alta, no condición de
 * búsqueda, y por eso se enseña como un rango legible y no como dos números
 * sueltos.
 *
 * El orden por defecto es alfabético y no la fecha de alta: un catálogo se lee
 * buscando una fila concreta, no viendo qué se creó ayer.
 *
 * `isSuper` añade la columna Workspace, porque el super ve el catálogo global y
 * el de cada workspace mezclados y necesita distinguirlos.
 */
export const document_typesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: t('document_types.code'), dataIndex: 'code', key: 'code', width: 130, sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    // A quién pertenece el documento, pegado a la sigla. Sin esta columna, «RUC»
    // y «DNI» se leían igual en la lista y no había forma de saber cuál de los
    // dos selectores lo va a ofrecer — que es justo lo que hay que mirar cuando
    // alguien avisa de que el alta de empresas no le da ningún tipo.
    { title: t('document_types.scope'), dataIndex: 'scope', key: 'scope', width: 130, sorter: true, mobile: { role: 'meta' } },
    { title: t('document_types.name'), dataIndex: 'name', key: 'name', sorter: true, ellipsis: true, mobile: { role: 'subtitle' } },
    { title: t('document_types.length'), dataIndex: 'min_length', key: 'length', width: 170, mobile: { role: 'meta' } },
    { title: t('document_types.country'), dataIndex: ['country', 'name'], key: 'country', width: 180, mobile: { role: 'meta' } },
    { title: t('document_types.usage_count'), dataIndex: 'usage_count', key: 'usage_count', width: 190, align: 'center', mobile: { role: 'meta' } },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, mobile: { role: 'meta', icon: ApartmentOutlined } },
    ] : []),
    { title: t('document_types.is_active'), dataIndex: 'is_active', key: 'status', width: 130, sorter: true, mobile: { role: 'status' } },
    { title: t('global.actions'), key: 'actions', width: isMobile ? 56 : 120, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
