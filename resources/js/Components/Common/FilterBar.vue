<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import {
    Card, Space, Button, Select, RangePicker, DatePicker,
    InputNumber, Input, Tag, Drawer, Checkbox, Badge, Tooltip, Empty, Switch,
} from 'ant-design-vue';
import {
    FilterOutlined, ClearOutlined, CloseOutlined,
    SettingOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';

/**
 * FilterBar — SAP Fiori-style filter bar, reusable across modules.
 *
 * Usage:
 *   <FilterBar
 *       :fields="filterFields"
 *       v-model="filters"
 *       storage-key="regions"
 *   />
 *
 * Field shape:
 *   { key: 'name',       label: 'Nombre',  type: 'tags',         visible: true }
 *   { key: 'is_active',  label: 'Estado',  type: 'select',       options: [{value: true, label: 'Activo'}] }
 *   { key: 'created_at', label: 'Creado',  type: 'date_range' }
 *   { key: 'amount',     label: 'Monto',   type: 'number_range' }
 *
 * `visible: false` hides it from the bar by default — the user can enable it from "Adapt Filters".
 */

const props = defineProps({
    fields:       { type: Array,  required: true },
    modelValue:   { type: Object, required: true },
    debounceMs:   { type: Number, default: 300 },
    storageKey:   { type: String, default: 'default' }, // for persisting visible filters per page
});
const emit = defineEmits(['update:modelValue']);

// ─── Mobile responsive detection ────────────────────────────────────────────
const isMobile = ref(false);
const checkMobile = () => { isMobile.value = window.innerWidth < 768; };
onMounted(() => { checkMobile(); window.addEventListener('resize', checkMobile); });
onBeforeUnmount(() => window.removeEventListener('resize', checkMobile));

// Mobile drawer state (replaces inline inputs on small screens)
const mobileDrawerOpen = ref(false);

// ─── Local state mirrors modelValue with debounce on text-like fields ──────
const local = ref({ ...props.modelValue });
watch(() => props.modelValue, (v) => { local.value = { ...v }; }, { deep: true });

let debounceTimer;
const fireUpdate = () => emit('update:modelValue', { ...local.value });
const updateField = (key, value, immediate = false) => {
    local.value = { ...local.value, [key]: value };
    clearTimeout(debounceTimer);
    if (immediate) fireUpdate();
    else debounceTimer = setTimeout(fireUpdate, props.debounceMs);
};

// ─── Visibility (which filters are shown) — persisted in localStorage ──────
const STORAGE_PREFIX = 'filter-bar:visible:';
const fullStorageKey = computed(() => STORAGE_PREFIX + props.storageKey);

const visibleKeys = ref([]);

const loadVisibility = () => {
    try {
        const raw = localStorage.getItem(fullStorageKey.value);
        if (raw) {
            const stored = JSON.parse(raw);
            // Validate against current fields (in case fields changed)
            visibleKeys.value = stored.filter(k => props.fields.some(f => f.key === k));
            return;
        }
    } catch (e) {}
    // Default: show fields that don't have visible:false
    visibleKeys.value = props.fields.filter(f => f.visible !== false).map(f => f.key);
};

const persistVisibility = () => {
    try {
        localStorage.setItem(fullStorageKey.value, JSON.stringify(visibleKeys.value));
    } catch (e) {}
};

onMounted(loadVisibility);

const visibleFields = computed(() =>
    props.fields.filter(f => visibleKeys.value.includes(f.key))
);

// ─── Adapt Filters Drawer ───────────────────────────────────────────────────
const adaptOpen = ref(false);
const draftVisibleKeys = ref([]);

const openAdapt = () => {
    draftVisibleKeys.value = [...visibleKeys.value];
    adaptOpen.value = true;
};
const applyAdapt = () => {
    visibleKeys.value = [...draftVisibleKeys.value];
    persistVisibility();
    adaptOpen.value = false;
};
const cancelAdapt = () => { adaptOpen.value = false; };
const toggleField = (key) => {
    if (draftVisibleKeys.value.includes(key)) {
        draftVisibleKeys.value = draftVisibleKeys.value.filter(k => k !== key);
    } else {
        draftVisibleKeys.value = [...draftVisibleKeys.value, key];
    }
};

// ─── Active filters (for chips + counter) ───────────────────────────────────
// Recibe el field completo, no solo la key, para poder discriminar por tipo:
// un `switch` con valor `false` es el default (apagado) y NO debe contar como
// filtro aplicado, pero un `select` con valor explícito `false` (ej. is_active
// = "Inactivo") SÍ debe contar. Sin esa distinción, el badge mostraba "1" en
// los módulos con `only_favorites` aunque el usuario no aplicara nada.
const isFieldActive = (field) => {
    const v = local.value[field.key];
    if (v === undefined || v === null || v === '') return false;
    if (Array.isArray(v)) return v.length > 0;
    if (field.type === 'switch' && v === false) return false;
    return true;
};

const activeFields = computed(() =>
    props.fields.filter(f => isFieldActive(f))
);

const activeCount = computed(() => activeFields.value.length);

// Clear one field (set to its empty value depending on type)
const clearField = (field) => {
    const empty = emptyValueFor(field);
    updateField(field.key, empty, true);
};

const clearAll = () => {
    const empty = {};
    props.fields.forEach(f => { empty[f.key] = emptyValueFor(f); });
    local.value = empty;
    fireUpdate();
};

const emptyValueFor = (field) => {
    switch (field.type) {
        case 'tags':
        case 'multiselect':
            return [];
        case 'date_range':
        case 'number_range':
            return null;
        default:
            return undefined;
    }
};

// ─── Display label of an active field (for chips) ───────────────────────────
const chipLabel = (field) => {
    const v = local.value[field.key];
    switch (field.type) {
        case 'tags':
        case 'multiselect': {
            if (!Array.isArray(v) || v.length === 0) return '';
            const labels = field.type === 'multiselect'
                ? v.map(val => field.options?.find(o => o.value === val)?.label ?? val)
                : v;
            if (labels.length <= 2) return labels.join(', ');
            return `${labels[0]} +${labels.length - 1}`;
        }
        case 'select': {
            const opt = field.options?.find(o => o.value === v);
            return opt?.label ?? String(v);
        }
        case 'date_range': {
            if (!v || v.length !== 2) return '';
            const fmt = (d) => dayjs.isDayjs(d) ? d.format('DD/MM/YY') : dayjs(d).format('DD/MM/YY');
            return `${fmt(v[0])} – ${fmt(v[1])}`;
        }
        case 'date':
            return dayjs.isDayjs(v) ? v.format('DD/MM/YY') : dayjs(v).format('DD/MM/YY');
        case 'number_range':
            if (!v || v.length !== 2) return '';
            return `${v[0] ?? '∞'} – ${v[1] ?? '∞'}`;
        default:
            return String(v);
    }
};
</script>

<template>
    <Card class="filter-bar" :bodyStyle="{ padding: '14px 16px' }">
        <!-- (Los chips de filtros activos viven en <FilterChips> standalone,
             arriba del listado de resultados. Single source of truth.) -->

        <!-- DESKTOP: inputs in line -->
        <Space v-if="!isMobile" wrap :size="[10, 10]" class="filter-inputs">
            <template v-for="field in visibleFields" :key="field.key">
                <!-- (same input rendering as before, see component below) -->
                <Select
                    v-if="field.type === 'tags'"
                    :value="local[field.key] ?? []"
                    mode="tags"
                    :placeholder="field.label"
                    style="min-width: 240px; max-width: 380px"
                    allowClear
                    :token-separators="[',']"
                    :max-tag-count="2"
                    @update:value="(v) => updateField(field.key, v)"
                />
                <Input
                    v-else-if="field.type === 'text'"
                    :value="local[field.key] ?? ''"
                    :placeholder="field.label"
                    allow-clear
                    style="min-width: 200px; max-width: 260px"
                    @update:value="(v) => updateField(field.key, v)"
                />
                <Select
                    v-else-if="field.type === 'select'"
                    :value="local[field.key]"
                    :placeholder="field.label"
                    style="min-width: 160px"
                    allowClear
                    show-search
                    option-filter-prop="label"
                    @update:value="(v) => updateField(field.key, v, true)"
                >
                    <Select.Option v-for="opt in field.options" :key="String(opt.value)" :value="opt.value" :label="opt.label">
                        {{ opt.label }}
                    </Select.Option>
                </Select>
                <Select
                    v-else-if="field.type === 'multiselect'"
                    :value="local[field.key] ?? []"
                    mode="multiple"
                    :placeholder="field.label"
                    style="min-width: 220px; max-width: 360px"
                    allowClear
                    show-search
                    option-filter-prop="label"
                    :max-tag-count="2"
                    @update:value="(v) => updateField(field.key, v, true)"
                >
                    <Select.Option v-for="opt in field.options" :key="String(opt.value)" :value="opt.value" :label="opt.label">
                        {{ opt.label }}
                    </Select.Option>
                </Select>
                <RangePicker
                    v-else-if="field.type === 'date_range'"
                    :value="local[field.key]"
                    :placeholder="[`${field.label}: ${$t('global.from')}`, $t('global.to')]"
                    format="DD-MM-YYYY"
                    style="width: 260px"
                    @update:value="(v) => updateField(field.key, v, true)"
                />
                <DatePicker
                    v-else-if="field.type === 'date'"
                    :value="local[field.key]"
                    :placeholder="field.label"
                    format="DD-MM-YYYY"
                    style="width: 180px"
                    @update:value="(v) => updateField(field.key, v, true)"
                />
                <Space.Compact v-else-if="field.type === 'number_range'">
                    <InputNumber
                        :value="local[field.key]?.[0]"
                        :placeholder="`${field.label}: ${$t('global.min')}`"
                        style="width: 130px"
                        @update:value="(v) => updateField(field.key, [v, local[field.key]?.[1]])"
                    />
                    <InputNumber
                        :value="local[field.key]?.[1]"
                        :placeholder="$t('global.max')"
                        style="width: 130px"
                        @update:value="(v) => updateField(field.key, [local[field.key]?.[0], v])"
                    />
                </Space.Compact>
                <!-- Switch: filtros booleanos tipo "Solo X" presentados como
                     pill toggle (más natural que un select de Sí/No). -->
                <span
                    v-else-if="field.type === 'switch'"
                    class="filter-switch"
                    :class="{ 'filter-switch--on': !!local[field.key] }"
                    @click="updateField(field.key, !local[field.key], true)"
                >
                    <Switch
                        :checked="!!local[field.key]"
                        size="small"
                    />
                    <span class="filter-switch__label">{{ field.label }}</span>
                </span>
            </template>

            <Tooltip :title="$t('global.configure_filters')">
                <Button @click="openAdapt">
                    <SettingOutlined /> {{ $t('global.filters') }}
                </Button>
            </Tooltip>
            <Tooltip :title="$t('global.clear_filters_hint')">
                <Button @click="clearAll" :disabled="activeCount === 0">
                    <ClearOutlined /> {{ $t('global.clear') }}
                    <Badge v-if="activeCount > 0" :count="activeCount"
                        :number-style="{ backgroundColor: '#0A6ED1', marginLeft: '6px' }" />
                </Button>
            </Tooltip>

            <!-- Slot opt-in para acciones extra del módulo (ej. "Filtros
                 avanzados" en Customers). Queda dentro del Card del FilterBar
                 alineado a la derecha, agrupado visualmente con el resto. -->
            <slot name="actions" />
        </Space>

        <!-- MOBILE: single button that opens a drawer with all filters -->
        <Space v-else direction="vertical" style="width: 100%" :size="8">
            <Button block size="large" @click="mobileDrawerOpen = true">
                <FilterOutlined />
                <span style="margin-left: 6px;">
                    {{ $t('global.filters') }}<span v-if="activeCount > 0"> ({{ activeCount }})</span>
                </span>
            </Button>
            <Button v-if="activeCount > 0" block @click="clearAll">
                <ClearOutlined /> {{ $t('global.clear_all') }}
            </Button>
        </Space>
    </Card>

    <!-- MOBILE FILTER DRAWER (shows all filters in a column) -->
    <Drawer
        v-model:open="mobileDrawerOpen"
        :title="$t('global.filters')"
        placement="bottom"
        :height="'85vh'"
        :body-style="{ padding: '16px' }"
    >
        <Space direction="vertical" :size="14" style="width: 100%">
            <div v-for="field in visibleFields" :key="field.key" class="mobile-field">
                <label class="mobile-field__label">{{ field.label }}</label>

                <Select
                    v-if="field.type === 'tags'"
                    :value="local[field.key] ?? []"
                    mode="tags"
                    style="width: 100%"
                    allowClear
                    :token-separators="[',']"
                    @update:value="(v) => updateField(field.key, v)"
                />
                <Input
                    v-else-if="field.type === 'text'"
                    :value="local[field.key] ?? ''"
                    allow-clear
                    style="width: 100%"
                    @update:value="(v) => updateField(field.key, v)"
                />
                <Select
                    v-else-if="field.type === 'select'"
                    :value="local[field.key]"
                    style="width: 100%"
                    allowClear
                    show-search
                    option-filter-prop="label"
                    @update:value="(v) => updateField(field.key, v, true)"
                >
                    <Select.Option v-for="opt in field.options" :key="String(opt.value)" :value="opt.value" :label="opt.label">
                        {{ opt.label }}
                    </Select.Option>
                </Select>
                <Select
                    v-else-if="field.type === 'multiselect'"
                    :value="local[field.key] ?? []"
                    mode="multiple"
                    style="width: 100%"
                    allowClear
                    show-search
                    option-filter-prop="label"
                    @update:value="(v) => updateField(field.key, v, true)"
                >
                    <Select.Option v-for="opt in field.options" :key="String(opt.value)" :value="opt.value" :label="opt.label">
                        {{ opt.label }}
                    </Select.Option>
                </Select>
                <RangePicker
                    v-else-if="field.type === 'date_range'"
                    :value="local[field.key]"
                    style="width: 100%"
                    format="DD-MM-YYYY"
                    @update:value="(v) => updateField(field.key, v, true)"
                />
                <DatePicker
                    v-else-if="field.type === 'date'"
                    :value="local[field.key]"
                    style="width: 100%"
                    format="DD-MM-YYYY"
                    @update:value="(v) => updateField(field.key, v, true)"
                />
                <Space.Compact v-else-if="field.type === 'number_range'" style="width: 100%">
                    <InputNumber
                        :value="local[field.key]?.[0]"
                        :placeholder="$t('global.min')"
                        style="flex: 1"
                        @update:value="(v) => updateField(field.key, [v, local[field.key]?.[1]])"
                    />
                    <InputNumber
                        :value="local[field.key]?.[1]"
                        :placeholder="$t('global.max')"
                        style="flex: 1"
                        @update:value="(v) => updateField(field.key, [local[field.key]?.[0], v])"
                    />
                </Space.Compact>
                <Switch
                    v-else-if="field.type === 'switch'"
                    :checked="!!local[field.key]"
                    @update:checked="(v) => updateField(field.key, v, true)"
                />
            </div>
        </Space>

        <template #footer>
            <Space style="width: 100%; justify-content: space-between;">
                <Button @click="openAdapt">
                    <SettingOutlined /> {{ $t('global.configure') }}
                </Button>
                <Button type="primary" @click="mobileDrawerOpen = false">
                    {{ $t('global.close') }}
                </Button>
            </Space>
        </template>
    </Drawer>

    <!-- Adapt Filters Drawer -->
    <Drawer
        v-model:open="adaptOpen"
        :title="$t('global.adapt_filters')"
        :width="380"
        placement="right"
    >
        <p class="adapt-help">{{ $t('global.adapt_filters_help') }}</p>

        <div class="adapt-list">
            <div
                v-for="field in props.fields"
                :key="field.key"
                class="adapt-item"
                @click="toggleField(field.key)"
            >
                <Checkbox
                    :checked="draftVisibleKeys.includes(field.key)"
                    @click.stop
                    @change="toggleField(field.key)"
                />
                <span class="adapt-item__label">{{ field.label }}</span>
                <span class="adapt-item__type">{{ field.type }}</span>
            </div>
        </div>

        <Empty
            v-if="props.fields.length === 0"
            :description="$t('global.no_records')"
        />

        <template #footer>
            <Space>
                <Button @click="cancelAdapt">{{ $t('global.cancel') }}</Button>
                <Button type="primary" @click="applyAdapt">{{ $t('global.apply') }}</Button>
            </Space>
        </template>
    </Drawer>
</template>

<style scoped>
.filter-bar { margin-bottom: 16px; }
.filter-inputs { width: 100%; }

/* Pill toggle para filtros booleanos tipo "Solo X" */
.filter-switch {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 12px;
    border-radius: 18px;
    border: 1px solid #d4d8dd;
    background: #fff;
    cursor: pointer;
    user-select: none;
    transition: border-color 0.12s ease, background 0.12s ease;
    font-size: 0.875rem;
    color: #334155;
    height: 32px;
}
.filter-switch:hover { border-color: #94a3b8; }
.filter-switch--on {
    background: #E6F1FB;
    border-color: #0A6ED1;
    color: #0A6ED1;
    font-weight: 500;
}
.filter-switch__label { white-space: nowrap; }
html[data-theme="dark"] .filter-switch {
    background: #2c3034;
    border-color: #3f4448;
    color: #cbd5e1;
}
html[data-theme="dark"] .filter-switch--on {
    background: rgba(77, 182, 232, 0.15);
    border-color: #4db6e8;
    color: #4db6e8;
}

/* Mobile field labels */
.mobile-field { display: flex; flex-direction: column; gap: 6px; }
.mobile-field__label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #32363A;
    letter-spacing: 0.01em;
}

/* Adapt Filters drawer list */
.adapt-help {
    color: #6A6D70;
    font-size: 0.875rem;
    margin: 0 0 16px 0;
}
.adapt-list { display: flex; flex-direction: column; gap: 4px; }
.adapt-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.12s ease;
}
.adapt-item:hover { background: var(--color-surface-hover, #F5F5F5); }
.adapt-item__label {
    flex: 1;
    color: #32363A;
    font-weight: 500;
}
.adapt-item__type {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #8c8c8c;
    background: var(--color-surface-hover, #F0F0F0);
    padding: 2px 6px;
    border-radius: 2px;
}
</style>

<style>
/* Dark mode (not scoped) */
html[data-theme="dark"] .adapt-help { color: #a8aaae; }
html[data-theme="dark"] .adapt-item:hover { background: #313a44; }
html[data-theme="dark"] .adapt-item__label { color: #e5e6e7; }
html[data-theme="dark"] .adapt-item__type {
    background: #2c3034;
    color: #a8aaae;
}
html[data-theme="dark"] .mobile-field__label { color: #e5e6e7; }
</style>
