import { CalendarOutlined } from '@ant-design/icons-vue';

/**
 * Columnas del listado de reglas del flujo.
 *
 * El orden por defecto es el nivel, no la fecha de alta: una lista de reglas se
 * lee en el orden en que se firman.
 *
 * La columna que identifica la fila es el NOMBRE de la firma —«Supervisor
 * Autorizante - HITACHI»—, no el rol. El rol dice qué clase de persona firma;
 * el nombre dice por parte de quién, y eso es lo que distingue una firma de
 * otra en un documento que puede acabar delante de un inspector.
 *
 * `dataIndex` de las columnas ordenables tiene que ser una columna REAL de la
 * tabla: es lo que la tabla manda como `sort`. Con `approver_role_label` (un
 * campo calculado) el backend descartaba el orden en silencio y pulsar la
 * cabecera no hacía nada.
 */
export const approvalRulesTableColumns = (t, { isSuper = false, isMobile = false } = {}) => [
    { title: t('approval_rules.priority_level'), dataIndex: 'priority_level', key: 'priority_level', width: 90, align: 'center', sorter: true, alwaysVisible: true, mobile: { role: 'pin' } },
    { title: t('approval_rules.name'), dataIndex: 'name', key: 'name', sorter: true, alwaysVisible: true, mobile: { role: 'title' } },
    { title: t('approval_rules.approver_role'), dataIndex: 'approver_role', key: 'approver_role', width: 190, sorter: true, mobile: { role: 'subtitle' } },
    // Sin `sorter`: ordenar por tipo de trabajo sería ordenar por su id, que no
    // significa nada para quien mira la tabla.
    { title: t('approval_rules.work_type'), dataIndex: 'work_type_id', key: 'work_type', width: 170, mobile: { role: 'meta' } },
    { title: t('approval_rules.country'), dataIndex: ['country', 'name'], key: 'country', width: 160, mobile: { role: 'meta' } },
    { title: t('approval_rules.is_required'), dataIndex: 'is_required', key: 'is_required', width: 130, align: 'center', sorter: true, mobile: { role: 'meta' } },
    { title: t('approval_rules.is_active'), dataIndex: 'is_active', key: 'status', width: 130, sorter: true, mobile: { role: 'status' } },
    { title: t('global.created_at'), dataIndex: 'created_at', key: 'created_at', sorter: true, width: 180, mobile: { role: 'meta', icon: CalendarOutlined }, defaultHidden: true },
    { title: t('global.actions'), key: 'actions', width: isMobile ? 56 : 120, fixed: 'right', align: 'center', alwaysVisible: true, mobile: { role: 'actions' } },
];
