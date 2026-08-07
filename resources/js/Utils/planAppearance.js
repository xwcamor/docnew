/**
 * planAppearance — catálogo de iconos y colores soportados para Plans.
 *
 * Single source of truth para:
 *   - El Form (selects de icono y color)
 *   - Index/Show/Trash/Modal (renderizar el icono del plan)
 *
 * Si quieres agregar otro icono, agréguelo a PLAN_ICONS con su componente y un
 * label traducible. Nada más cambia en la app.
 */
import {
    CrownOutlined, StarOutlined, RocketOutlined, ThunderboltOutlined,
    TrophyOutlined, GiftOutlined, FireOutlined, HeartOutlined,
    FlagOutlined, GoldOutlined, ExperimentOutlined, BulbOutlined,
    AppstoreOutlined, SafetyOutlined,
} from '@ant-design/icons-vue';

// Paleta limitada (los colores que Ant Design Tag entiende como tokens).
// Si el usuario quiere un hex específico se podría ampliar a ColorPicker,
// pero un set acotado mantiene la estética consistente con Tags del sistema.
export const PLAN_COLORS = [
    { value: 'default', label: 'Gris',     swatch: '#d9d9d9' },
    { value: 'blue',    label: 'Azul',     swatch: '#1677ff' },
    { value: 'cyan',    label: 'Cian',     swatch: '#13c2c2' },
    { value: 'green',   label: 'Verde',    swatch: '#52c41a' },
    { value: 'gold',    label: 'Dorado',   swatch: '#faad14' },
    { value: 'orange',  label: 'Naranja',  swatch: '#fa8c16' },
    { value: 'red',     label: 'Rojo',     swatch: '#f5222d' },
    { value: 'purple',  label: 'Púrpura',  swatch: '#722ed1' },
    { value: 'magenta', label: 'Magenta',  swatch: '#eb2f96' },
];

export const PLAN_ICONS = [
    { value: 'CrownOutlined',       component: CrownOutlined,       label: 'Corona' },
    { value: 'StarOutlined',        component: StarOutlined,        label: 'Estrella' },
    { value: 'RocketOutlined',      component: RocketOutlined,      label: 'Cohete' },
    { value: 'ThunderboltOutlined', component: ThunderboltOutlined, label: 'Rayo' },
    { value: 'TrophyOutlined',      component: TrophyOutlined,      label: 'Trofeo' },
    { value: 'GiftOutlined',        component: GiftOutlined,        label: 'Regalo' },
    { value: 'FireOutlined',        component: FireOutlined,        label: 'Fuego' },
    { value: 'HeartOutlined',       component: HeartOutlined,       label: 'Corazón' },
    { value: 'FlagOutlined',        component: FlagOutlined,        label: 'Bandera' },
    { value: 'GoldOutlined',        component: GoldOutlined,        label: 'Lingote' },
    { value: 'ExperimentOutlined',  component: ExperimentOutlined,  label: 'Experimento' },
    { value: 'BulbOutlined',        component: BulbOutlined,        label: 'Idea' },
    { value: 'AppstoreOutlined',    component: AppstoreOutlined,    label: 'Apps' },
    { value: 'SafetyOutlined',      component: SafetyOutlined,      label: 'Seguridad' },
];

/** Devuelve el componente del icono o null si no se reconoce. */
export const resolveIconComponent = (iconName) => {
    if (!iconName) return null;
    return PLAN_ICONS.find(i => i.value === iconName)?.component ?? null;
};

/** Color con fallback al default si el guardado no está en la paleta. */
export const resolveColor = (color) => {
    if (!color) return 'default';
    return PLAN_COLORS.find(c => c.value === color) ? color : 'default';
};
