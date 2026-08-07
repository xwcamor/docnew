import { GlobalOutlined, IdcardOutlined, ApartmentOutlined, CalendarOutlined } from '@ant-design/icons-vue';
/**
 * Columnas de la tabla principal de Users.
 *
 * `isSuper` agrega la columna Empresa (tenant) — solo tiene sentido para super
 * (los demás roles ven solo users de su propio tenant, así que la columna
 * Empresa sería ruido redundante). El ID y el slug NO se muestran como columna:
 * solo el super los ve, y únicamente en el drawer de detalle y en el Show.
 */
export const usersTableColumns = (t, { isSuper = false, isMobile = false } = {}) => {
    const cols = [
        { title: '★', dataIndex: 'is_favorite', key: 'favorite', width: 48, alwaysVisible: true, mobile: { role: 'pin' } },
    ];

    cols.push(
        { title: t('users.name'),  dataIndex: 'name',  key: 'name',  sorter: true, ellipsis: true, alwaysVisible: true, mobile: { role: 'title' } },
        { title: t('users.country'), key: 'country', dataIndex: ['country', 'name'], sorter: true, ellipsis: true, mobile: { role: 'meta', icon: GlobalOutlined } },
        { title: t('users.role'),  dataIndex: 'role',  key: 'role',  width: 140, sorter: true, mobile: { role: 'meta', icon: IdcardOutlined } },
    );

    if (isSuper) {
        cols.push({
            title: t('users.tenant'), dataIndex: ['tenant', 'name'], key: 'tenant',
            width: 160, sorter: true, mobile: { role: 'meta', icon: ApartmentOutlined },
        });
    }

    cols.push(
        { title: t('users.is_active'),   dataIndex: 'is_active',  key: 'status',     sorter: true, width: 120, mobile: { role: 'status' } },
        { title: t('global.created_at'), dataIndex: 'created_at', key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
        { title: t('global.actions'),    key: 'actions',          width: isMobile ? 56 : 100, fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
    );

    return cols;
};
