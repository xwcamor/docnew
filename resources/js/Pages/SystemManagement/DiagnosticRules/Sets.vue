<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { Card, Input, Select, Tag, Button, Empty, Tooltip, Popconfirm } from 'ant-design-vue';
import { ExperimentOutlined, EditOutlined, ArrowLeftOutlined, SearchOutlined, UndoOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sets:    { type: Array,   default: () => [] },
    tests:   { type: Array,   default: () => [] },
    isSuper: { type: Boolean, default: false },
});

const { t } = useI18n();

const q = ref('');
const testFilter = ref(null);

const testOptions = computed(() => props.tests.map((x) => ({ value: x, label: x })));

const filtered = computed(() => {
    const needle = q.value.trim().toLowerCase();
    return props.sets.filter((s) => {
        if (testFilter.value && s.test !== testFilter.value) return false;
        if (!needle) return true;
        return [s.test, s.oil, s.trafo, s.standard, s.label]
            .filter(Boolean).join(' ').toLowerCase().includes(needle);
    });
});

const goEdit = (id) => router.visit(route('system_management.diagnostic_rules.set_edit', id));
const restore = (id) => router.post(route('system_management.diagnostic_rules.set_restore', id), {}, { preserveScroll: true });
</script>

<template>
    <Head :title="$t('diagnostic_rules.sets_title')" />
    <div class="sets-page">
        <SectionHeader :title="$t('diagnostic_rules.sets_title')" :subtitle="$t('diagnostic_rules.sets_subtitle')">
            <template #icon><ExperimentOutlined /></template>
            <template #actions>
                <Link :href="route('system_management.diagnostic_rules.index')">
                    <Button><template #icon><ArrowLeftOutlined /></template>{{ $t('diagnostic_rules.back_to_scale') }}</Button>
                </Link>
            </template>
        </SectionHeader>

        <p v-if="!isSuper" class="hint-banner">
            {{ $t('diagnostic_rules.sets_tenant_hint') }}
        </p>

        <div class="filters">
            <Input v-model:value="q" :placeholder="$t('diagnostic_rules.search_sets')" allow-clear style="max-width:280px">
                <template #prefix><SearchOutlined /></template>
            </Input>
            <Select
                v-model:value="testFilter" :options="testOptions" allow-clear
                :placeholder="$t('diagnostic_rules.filter_test')" style="width:200px" />
        </div>

        <Card :bodyStyle="{ padding: 0 }">
            <table v-if="filtered.length" class="sets-table">
                <thead>
                    <tr>
                        <th>{{ $t('diagnostic_rules.col_test') }}</th>
                        <th>{{ $t('diagnostic_rules.col_oil') }}</th>
                        <th>{{ $t('diagnostic_rules.col_trafo') }}</th>
                        <th>{{ $t('diagnostic_rules.col_standard') }}</th>
                        <th class="num">{{ $t('diagnostic_rules.col_rules') }}</th>
                        <th>{{ $t('diagnostic_rules.col_active') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in filtered" :key="s.id" class="row" @click="goEdit(s.id)">
                        <td>
                            <b>{{ s.test }}</b>
                            <Tooltip v-if="!isSuper" :title="s.customized ? $t('diagnostic_rules.customized_hint') : $t('diagnostic_rules.factory_hint')">
                                <Tag :color="s.customized ? 'blue' : 'default'" class="origin-tag">
                                    {{ s.customized ? $t('diagnostic_rules.customized') : $t('diagnostic_rules.factory') }}
                                </Tag>
                            </Tooltip>
                        </td>
                        <td>{{ s.oil || '—' }}</td>
                        <td>{{ s.trafo || $t('diagnostic_rules.all_trafos') }}</td>
                        <td>{{ s.standard || '—' }}</td>
                        <td class="num">{{ s.rules_count }}</td>
                        <td>
                            <Tag :color="s.is_active ? 'green' : 'default'">
                                {{ s.is_active ? $t('global.yes') : $t('global.no') }}
                            </Tag>
                        </td>
                        <td class="actions">
                            <Popconfirm
                                v-if="!isSuper && s.customized"
                                :title="$t('diagnostic_rules.set_restore_confirm')"
                                :ok-text="$t('global.yes')"
                                :cancel-text="$t('global.no')"
                                @confirm="restore(s.id)"
                            >
                                <Button type="text" size="small" danger @click.stop>
                                    <template #icon><UndoOutlined /></template>{{ $t('diagnostic_rules.set_restore') }}
                                </Button>
                            </Popconfirm>
                            <Button type="text" size="small" @click.stop="goEdit(s.id)">
                                <template #icon><EditOutlined /></template>{{ $t('diagnostic_rules.edit') }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Empty v-else :description="$t('diagnostic_rules.no_sets')" style="padding:36px" />
        </Card>
    </div>
</template>

<style scoped>
.sets-page { max-width: 980px; }
.hint-banner {
    background: var(--color-fill-tertiary, #f0f6ff);
    border: 1px solid var(--color-primary-border, #bcdcff);
    color: var(--color-text, #32363A);
    border-radius: 8px; padding: 10px 14px; margin: 0 0 14px; font-size: 0.85rem;
}
.origin-tag { margin-left: 8px; font-size: 0.68rem; }
.filters { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.sets-table { width:100%; border-collapse:collapse; }
.sets-table th { text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color:var(--color-text-muted,#6A6D70); padding:10px 14px; border-bottom:1px solid var(--color-border,#eef0f2); }
.sets-table td { padding:10px 14px; border-bottom:1px solid var(--color-border,#f2f4f6); font-size:0.88rem; }
.sets-table .num { text-align:right; font-variant-numeric:tabular-nums; }
.sets-table .row { cursor:pointer; }
.sets-table .row:hover td { background:var(--color-fill-tertiary,#f7f9fb); }
.sets-table .actions { text-align:right; }
</style>
