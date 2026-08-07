import { ApartmentOutlined, SafetyCertificateOutlined, TeamOutlined } from '@ant-design/icons-vue';
/**
 * Columnas de la tabla principal de Roles.
 *
 * `isSuper` agrega 2 columnas que admins de workspace no necesitan:
 *   - ID: identificador tecnico (ruido para admins).
 *   - Workspace: super ve roles de varios tenants + roles globales
 *     (tenant_id NULL). El admin solo ve roles de su propio tenant.
 */
export const rolesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => {
    const cols = [
        { title: '★', dataIndex: 'is_favorite', key: 'favorite', width: 48, alwaysVisible: true, mobile: { role: 'pin' } },
    ];
    cols.push(
        { title: t('roles.name'),              dataIndex: 'name',              key: 'name',              sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
        { title: t('roles.description'),       dataIndex: 'description',       key: 'description',       sorter: true, ellipsis: true, mobile: { role: 'hidden' } },
    );
    if (isSuper) {
        cols.push({ title: t('tenants.singular'), dataIndex: 'tenant_name', key: 'workspace', width: 180, sorter: true, mobile: { role: 'meta', icon: ApartmentOutlined } });
    }
    cols.push(
        { title: t('roles.is_active'),         dataIndex: 'is_active',         key: 'is_active',         width: 110, sorter: true, mobile: { role: 'status' } },
        { title: t('roles.permissions_count'), dataIndex: 'permissions_count', key: 'permissions_count', width: 120, align: 'center', sorter: true, mobile: { role: 'meta', icon: SafetyCertificateOutlined } },
        { title: t('roles.users_count'),       dataIndex: 'users_count',       key: 'users_count',       width: 110, align: 'center', sorter: true, mobile: { role: 'meta', icon: TeamOutlined } },
        { title: t('global.actions'),          key: 'actions',                 width: isMobile ? 56 : 200, fixed: 'right', align: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
    );
    return cols;
};
