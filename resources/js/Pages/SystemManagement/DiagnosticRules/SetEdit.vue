<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import {
    Card, Button, Input, InputNumber, Select, Switch, Tag, message,
} from 'ant-design-vue';
import {
    ExperimentOutlined, ArrowLeftOutlined, PlusOutlined, DeleteOutlined, SaveOutlined,
} from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const props = defineProps({
    set:       { type: Object, required: true },
    rules:     { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    operators: { type: Array, default: () => [] },
    // El admin está viendo el cuadro de FÁBRICA: al guardar se crea su copia.
    inherited: { type: Boolean, default: false },
});

const { t } = useI18n();
const page = usePage();

// Copia editable local.
const meta = ref({ ...props.set });
const rows = ref(props.rules.map((r) => ({
    ...r,
    conditions: r.conditions.map((c) => ({ ...c })),
})));
const saving = ref(false);

const varOptions = computed(() => props.variables.map((v) => ({
    value: v.id, label: v.unit ? `${v.code} (${v.unit})` : v.code,
})));
const opOptions = computed(() => props.operators.map((o) => ({ value: o, label: o })));
const varCode = (id) => props.variables.find((v) => v.id === id)?.code ?? '—';

const defaultVar = () => (props.variables[0]?.id ?? null);

// ─── Vista AGRUPADA (matriz gas × bandas, como el Excel original) ───────────
// Las reglas de cromas son regulares: por variable, N bandas con scores
// crecientes y cortes encadenados (<=t1 | >t1 AND <=t2 | ... | >tN-1).
// Si TODAS las variables siguen el patrón, se puede editar como matriz de
// cortes + peso por gas (mucho más rápido que regla por regla). Si alguna no
// lo sigue, solo queda la vista detallada.
const parseGroups = (ruleRows) => {
    const byVar = new Map();
    for (const r of ruleRows) {
        if (!byVar.has(r.variable_id)) byVar.set(r.variable_id, []);
        byVar.get(r.variable_id).push(r);
    }
    const groups = [];
    for (const [variableId, list] of byVar) {
        const bands = [...list].sort((a, b) => a.score - b.score);
        const weight = bands[0]?.weight;
        if (!bands.every((b) => b.weight === weight && b.is_active !== false)) return null;
        const cuts = [];
        for (let i = 0; i < bands.length; i++) {
            const conds = bands[i].conditions;
            if (conds.some((c) => c.variable_id !== variableId)) return null;
            const gt = conds.filter((c) => c.operator === '>');
            const le = conds.filter((c) => c.operator === '<=');
            if (i === 0) {
                // primera banda: solo <= t1
                if (conds.length !== 1 || le.length !== 1) return null;
                cuts.push(le[0].value);
            } else if (i === bands.length - 1) {
                // última banda: solo > t(n-1), debe encadenar con el corte previo
                if (conds.length !== 1 || gt.length !== 1 || gt[0].value !== cuts[cuts.length - 1]) return null;
            } else {
                // intermedia: > prev AND <= t
                if (conds.length !== 2 || gt.length !== 1 || le.length !== 1) return null;
                if (gt[0].value !== cuts[cuts.length - 1]) return null;
                cuts.push(le[0].value);
            }
        }
        groups.push({
            variable_id: variableId,
            scores: bands.map((b) => b.score),
            weight,
            cuts: [...cuts],
        });
    }
    // Matriz homogénea: mismas bandas en todos los gases (caso cromas).
    const n = groups[0]?.scores.length;
    if (!n || !groups.every((g) => g.scores.length === n)) return null;
    return groups;
};

const groups = ref(parseGroups(props.rules.map((r) => ({ ...r, conditions: r.conditions.map((c) => ({ ...c })) }))));
const viewMode = ref(groups.value ? 'grouped' : 'detailed');

/** Reconstruye las reglas planas desde la matriz (inversa exacta del parse). */
const groupsToRules = () => {
    const out = [];
    let priority = 1;
    for (const g of groups.value) {
        for (let i = 0; i < g.scores.length; i++) {
            const conds = [];
            if (i > 0) conds.push({ variable_id: g.variable_id, operator: '>', value: g.cuts[i - 1] });
            if (i < g.cuts.length) conds.push({ variable_id: g.variable_id, operator: '<=', value: g.cuts[i] });
            out.push({
                variable_id: g.variable_id, score: g.scores[i], weight: g.weight,
                priority: priority++, result_label: null, is_active: true, conditions: conds,
            });
        }
    }
    return out;
};

const switchView = (mode) => {
    if (mode === viewMode.value) return;
    if (mode === 'detailed') {
        if (groups.value) rows.value = groupsToRules();
        viewMode.value = 'detailed';
        return;
    }
    const parsed = parseGroups(rows.value);
    if (!parsed) { message.warning(t('diagnostic_rules.grouped_unavailable')); return; }
    groups.value = parsed;
    viewMode.value = 'grouped';
};

/** Cortes crecientes por gas (t1 < t2 < … ). */
const groupedValid = () => groups.value.every(
    (g) => g.cuts.every((c, i) => c !== null && c !== undefined && (i === 0 || c > g.cuts[i - 1])),
);

const addRule = () => {
    const v = defaultVar();
    const nextPriority = rows.value.length
        ? Math.max(...rows.value.map((r) => r.priority)) + 1 : 1;
    rows.value.push({
        variable_id: v, score: 0, weight: 1, priority: nextPriority,
        result_label: '', is_active: true,
        conditions: [{ variable_id: v, operator: '<=', value: 0 }],
    });
};
const removeRule = (i) => rows.value.splice(i, 1);

const addCondition = (rule) => {
    rule.conditions.push({ variable_id: rule.variable_id, operator: '<=', value: 0 });
};
const removeCondition = (rule, i) => {
    if (rule.conditions.length <= 1) { message.warning(t('diagnostic_rules.min_one_cond')); return; }
    rule.conditions.splice(i, 1);
};

const save = () => {
    // En vista agrupada: validar cortes crecientes y regenerar las reglas planas.
    if (viewMode.value === 'grouped') {
        if (!groupedValid()) {
            message.error(t('diagnostic_rules.cuts_not_increasing'));
            return;
        }
        rows.value = groupsToRules();
    }
    // Validación local: cada regla con al menos una condición.
    if (rows.value.some((r) => r.conditions.length === 0)) {
        message.error(t('diagnostic_rules.min_one_cond'));
        return;
    }
    saving.value = true;
    router.put(route('system_management.diagnostic_rules.set_update', meta.value.id), {
        label: meta.value.label,
        total_weight: meta.value.total_weight,
        is_active: meta.value.is_active,
        rules: rows.value.map((r) => ({
            variable_id: r.variable_id,
            score: r.score,
            weight: r.weight,
            priority: r.priority,
            result_label: r.result_label || null,
            is_active: r.is_active,
            conditions: r.conditions.map((c) => ({
                variable_id: c.variable_id,
                operator: c.operator,
                value: c.value,
            })),
        })),
    }, {
        preserveScroll: true,
        onSuccess: () => { if (page.props.flash?.success) message.success(page.props.flash.success); },
        onError: (e) => { message.error(Object.values(e)[0] || t('diagnostic_rules.error')); },
        onFinish: () => { saving.value = false; },
    });
};

const subtitle = computed(() => [
    props.set.test, props.set.oil,
    props.set.trafo || t('diagnostic_rules.all_trafos'), props.set.standard,
].filter(Boolean).join(' · '));
</script>

<template>
    <Head :title="$t('diagnostic_rules.sets_title')" />
    <div class="set-edit">
        <SectionHeader :title="set.label || subtitle" :subtitle="subtitle">
            <template #icon><ExperimentOutlined /></template>
            <template #actions>
                <Link :href="route('system_management.diagnostic_rules.sets')">
                    <Button><template #icon><ArrowLeftOutlined /></template>{{ $t('diagnostic_rules.sets_title') }}</Button>
                </Link>
            </template>
        </SectionHeader>

        <p v-if="inherited" class="inherited-banner">
            {{ $t('diagnostic_rules.set_inherited_hint') }}
        </p>

        <Card class="meta-card" :bodyStyle="{ padding: '16px 20px' }">
            <div class="meta-grid">
                <label>
                    <span>{{ $t('diagnostic_rules.set_label') }}</span>
                    <Input v-model:value="meta.label" size="small" :maxlength="120" />
                </label>
                <label>
                    <span>{{ $t('diagnostic_rules.set_total_w') }}</span>
                    <InputNumber v-model:value="meta.total_weight" :min="0" :step="1" size="small" style="width:100%" />
                </label>
                <label class="inline">
                    <span>{{ $t('diagnostic_rules.set_active') }}</span>
                    <Switch v-model:checked="meta.is_active" size="small" />
                </label>
            </div>
        </Card>

        <div class="rules-head">
            <div class="rules-head__row">
                <h3>{{ $t('diagnostic_rules.rules_title') }}</h3>
                <div class="view-toggle">
                    <Button size="small" :type="viewMode === 'grouped' ? 'primary' : 'default'" @click="switchView('grouped')">
                        {{ $t('diagnostic_rules.grouped_view') }}
                    </Button>
                    <Button size="small" :type="viewMode === 'detailed' ? 'primary' : 'default'" @click="switchView('detailed')">
                        {{ $t('diagnostic_rules.detailed_view') }}
                    </Button>
                </div>
            </div>
            <p class="hint">{{ viewMode === 'grouped' ? $t('diagnostic_rules.grouped_hint') : $t('diagnostic_rules.rules_hint') }}</p>
        </div>

        <!-- VISTA AGRUPADA: matriz gas × bandas (como el Excel original). -->
        <Card v-if="viewMode === 'grouped' && groups" :bodyStyle="{ padding: 0 }">
            <table class="rules-table grouped">
                <thead>
                    <tr>
                        <th class="c-var">{{ $t('diagnostic_rules.variable') }}</th>
                        <th class="c-num">{{ $t('diagnostic_rules.weight') }}</th>
                        <th v-for="(s, i) in groups[0].scores.slice(0, -1)" :key="i" class="c-num">
                            ≤ → {{ $t('diagnostic_rules.score') }} {{ s }}
                        </th>
                        <th class="c-rest">> → {{ $t('diagnostic_rules.score') }} {{ groups[0].scores[groups[0].scores.length - 1] }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="g in groups" :key="g.variable_id">
                        <td class="gas"><Tag color="blue" :bordered="false">{{ varCode(g.variable_id) }}</Tag></td>
                        <td><InputNumber v-model:value="g.weight" :min="0" size="small" style="width:70px" :step="0.5" /></td>
                        <td v-for="(c, i) in g.cuts" :key="i">
                            <InputNumber v-model:value="g.cuts[i]" size="small" style="width:96px" :step="1"
                                :status="i > 0 && g.cuts[i] !== null && g.cuts[i] <= g.cuts[i - 1] ? 'error' : ''" />
                        </td>
                        <td class="rest">{{ $t('diagnostic_rules.rest_band') }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card v-if="viewMode === 'detailed' && rows.length" :bodyStyle="{ padding: 0 }">
            <table class="rules-table">
                <thead>
                    <tr>
                        <th class="c-prio">{{ $t('diagnostic_rules.priority') }}</th>
                        <th class="c-var">{{ $t('diagnostic_rules.variable') }}</th>
                        <th class="c-cond">{{ $t('diagnostic_rules.conditions') }}</th>
                        <th class="c-num">{{ $t('diagnostic_rules.score') }}</th>
                        <th class="c-num">{{ $t('diagnostic_rules.weight') }}</th>
                        <th class="c-label">{{ $t('diagnostic_rules.result_label') }}</th>
                        <th class="c-act">{{ $t('diagnostic_rules.rule_active') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, ri) in rows" :key="ri">
                        <td><InputNumber v-model:value="r.priority" :min="0" size="small" style="width:64px" /></td>
                        <td>
                            <Select v-model:value="r.variable_id" :options="varOptions" size="small" style="width:120px" show-search
                                :filter-option="(input, opt) => opt.label.toLowerCase().includes(input.toLowerCase())" />
                        </td>
                        <td>
                            <div v-for="(c, ci) in r.conditions" :key="ci" class="cond">
                                <Tag v-if="ci > 0" color="default" class="and">{{ $t('diagnostic_rules.and') }}</Tag>
                                <Select v-model:value="c.variable_id" :options="varOptions" size="small" style="width:96px" show-search
                                    :filter-option="(input, opt) => opt.label.toLowerCase().includes(input.toLowerCase())" />
                                <Select v-model:value="c.operator" :options="opOptions" size="small" style="width:64px" />
                                <InputNumber v-model:value="c.value" size="small" style="width:90px" :step="0.1" />
                                <Button type="text" size="small" danger @click="removeCondition(r, ci)">
                                    <template #icon><DeleteOutlined /></template>
                                </Button>
                            </div>
                            <Button type="link" size="small" class="add-cond" @click="addCondition(r)">
                                <template #icon><PlusOutlined /></template>{{ $t('diagnostic_rules.add_condition') }}
                            </Button>
                        </td>
                        <td><InputNumber v-model:value="r.score" size="small" style="width:72px" :step="0.1" /></td>
                        <td><InputNumber v-model:value="r.weight" :min="0" size="small" style="width:72px" :step="0.1" /></td>
                        <td><Input v-model:value="r.result_label" size="small" :maxlength="120" /></td>
                        <td><Switch v-model:checked="r.is_active" size="small" /></td>
                        <td>
                            <Button type="text" size="small" danger @click="removeRule(ri)">
                                <template #icon><DeleteOutlined /></template>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
        <Card v-if="viewMode === 'detailed' && !rows.length" :bodyStyle="{ padding: '28px' }" class="empty">{{ $t('diagnostic_rules.no_rules') }}</Card>

        <div class="acts">
            <Button v-if="viewMode === 'detailed'" @click="addRule"><template #icon><PlusOutlined /></template>{{ $t('diagnostic_rules.add_rule') }}</Button>
            <Button type="primary" :loading="saving" @click="save">
                <template #icon><SaveOutlined /></template>{{ $t('diagnostic_rules.save') }}
            </Button>
        </div>
    </div>
</template>

<style scoped>
.set-edit { max-width: 1100px; }
.meta-card { margin-bottom: 18px; border-radius: 8px; }
.inherited-banner {
    background: #fff7e6; border: 1px solid #ffd591; color: #613400;
    border-radius: 8px; padding: 10px 14px; margin: 0 0 14px; font-size: 0.85rem;
}
.meta-grid { display:grid; grid-template-columns: 1fr 160px 140px; gap:16px; align-items:end; }
.meta-grid label { display:flex; flex-direction:column; gap:4px; font-size:0.8rem; color:var(--color-text-muted,#6A6D70); }
.meta-grid label.inline { flex-direction:row; align-items:center; gap:8px; }
.rules-head { margin-bottom: 10px; }
.rules-head__row { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.view-toggle { display:flex; gap:6px; }
.rules-table.grouped .gas { font-weight:600; }
.rules-table.grouped .rest { font-size:0.75rem; color:var(--color-text-muted,#6A6D70); white-space:nowrap; }
.rules-head h3 { margin:0; font-size:1rem; }
.rules-head .hint { margin:4px 0 0; font-size:0.8rem; color:var(--color-text-muted,#6A6D70); max-width:760px; }
.rules-table { width:100%; border-collapse:collapse; }
.rules-table th { text-align:left; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.4px; color:var(--color-text-muted,#6A6D70); padding:8px 10px; border-bottom:1px solid var(--color-border,#eef0f2); }
.rules-table td { padding:8px 10px; border-bottom:1px solid var(--color-border,#f2f4f6); vertical-align:top; }
.rules-table .c-num, .rules-table .c-prio { width:78px; }
.rules-table .c-cond { min-width:330px; }
.cond { display:flex; align-items:center; gap:5px; margin-bottom:5px; }
.cond .and { margin:0; font-size:0.65rem; }
.add-cond { padding-left:0; }
.acts { display:flex; gap:8px; margin-top:16px; }
.empty { text-align:center; color:var(--color-text-muted,#6A6D70); border-radius:8px; }
</style>
