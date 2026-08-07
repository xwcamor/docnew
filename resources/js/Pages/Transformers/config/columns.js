/**
 * Columnas de la tabla principal de Transformers.
 *
 * `isSuper` agrega 2 columnas que admins de workspace no necesitan ver:
 *   - ID: identificador tecnico (ruido para admins, util para super).
 *   - Workspace (tenant): cruz-tenant, super ve transformers de varios
 *     workspaces. Admin solo ve los suyos, la columna seria redundante.
 *
 * Varias columnas de dominio vienen ocultas por defecto (`defaultHidden`) para
 * no saturar; el usuario las activa desde el selector de columnas.
 */
export const transformersTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: '★',                      dataIndex: 'is_favorite', key: 'favorite',   width: 48,  alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('transformers.serial'),    dataIndex: 'serial',      key: 'serial',     width: 190, ellipsis: true, sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('transformers.tag'),       dataIndex: 'tag',         key: 'tag',        width: 140, sorter: true, mobile: { role: 'meta', primary: true }, defaultHidden: true },
    { title: t('transformers.customer'),  dataIndex: ['customer', 'name'], key: 'customer', width: 200, sorter: true, mobile: { role: 'meta' } },
    { title: t('transformers.transformer_type'), dataIndex: ['transformer_type', 'name'], key: 'transformer_type', width: 150, sorter: true, mobile: { role: 'meta' } },
    { title: t('transformers.oil_type'),  dataIndex: ['oil_type', 'name'], key: 'oil_type', width: 130, sorter: true, mobile: { role: 'meta' } },
    { title: t('transformers.brand'), dataIndex: ['brand', 'name'], key: 'brand', width: 150, sorter: true, mobile: { role: 'meta' } },
    { title: t('transformers.voltage_kv'), dataIndex: 'voltage_kv', key: 'voltage_kv', width: 120, align: 'right', sorter: true, mobile: { role: 'meta' } },
    { title: t('transformers.power_mva'),  dataIndex: 'power_mva',  key: 'power_mva',  width: 120, align: 'right', sorter: true, mobile: { role: 'meta' } },
    { title: t('transformers.phases'),    dataIndex: 'phases',      key: 'phases',     width: 120, sorter: true, mobile: { role: 'meta' }, defaultHidden: true },
    { title: t('transformers.tap_changer_type'), dataIndex: ['tap_changer_type', 'name'], key: 'tap_changer_type', width: 150, sorter: true, mobile: { role: 'meta' } },
    { title: t('transformers.connection_type'), dataIndex: ['connection_type', 'name'], key: 'connection_type', width: 130, sorter: true, mobile: { role: 'meta' }, defaultHidden: true },
    { title: t('transformers.preservation'), dataIndex: ['preservation', 'name'], key: 'preservation', width: 180, sorter: true, mobile: { role: 'meta' }, defaultHidden: true },
    { title: t('transformers.manufacture_year'), dataIndex: 'manufacture_year', key: 'manufacture_year', width: 110, align: 'center', sorter: true, mobile: { role: 'meta' }, defaultHidden: true },
    ...(isSuper ? [
        { title: t('tenants.singular'), dataIndex: ['tenant', 'name'], key: 'tenant', width: 180, sorter: true, mobile: { role: 'meta' } },
    ] : []),
    { title: t('global.created_at'),   dataIndex: 'created_at',  key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta' }, defaultHidden: true },
    { title: t('transformers.health_index'), dataIndex: 'health_index', key: 'health', width: 130, align: 'center', sorter: true, mobile: { role: 'meta', primary: true } },
    // En pantalla chica (tabla) las acciones se colapsan en un kebab → columna angosta.
    { title: t('global.actions'),      key: 'actions',           width: isMobile ? 56 : 200, fixed: 'right', align: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
];
