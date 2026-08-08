import { CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas del listado de reglas del flujo.
 *
 * El orden por defecto es el nivel, no la fecha de alta: una lista de reglas se
 * lee en el orden en que se firman.
 */
export const approvalRulesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: t('approval_rules.priority_level'), dataIndex: 'priority_level', key: 'priority_level', width: 90, align: 'center', sorter: true, alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('approval_rules.approver_role'), dataIndex: 'approver_role_label', key: 'approver_role', sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('approval_rules.work_type'), dataIndex: ['work_type', 'code'], key: 'work_type', width: 190, mobile: { role: 'subtitle' } },
    { title: t('approval_rules.country'), dataIndex: ['country', 'name'], key: 'country', width: 160, mobile: { role: 'meta' } },
    { title: t('approval_rules.is_required'), dataIndex: 'is_required', key: 'is_required', width: 130, align: 'center', sorter: true, mobile: { role: 'meta' } },
    { title: t('approval_rules.is_active'), dataIndex: 'is_active', key: 'status', width: 130, sorter: true, mobile: { role: 'status' } },
    { title: t('global.created_at'), dataIndex: 'created_at', key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'), key: 'actions', width: isMobile ? 56 : 120, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
