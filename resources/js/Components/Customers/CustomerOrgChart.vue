<script setup>
import { Empty } from 'ant-design-vue';
import { UserOutlined } from '@ant-design/icons-vue';
import OrgChartNode from './OrgChartNode.vue';

/**
 * CustomerOrgChart — vista de organigrama (solo lectura) de la jerarquía del
 * cliente. Cajas top-down con conectores; el cliente es la raíz. Alternativa
 * visual al árbol editable.
 */
defineProps({
    customerName: { type: String, required: true },
    nodes:        { type: Array,  default: () => [] },
});
</script>

<template>
    <div v-if="!nodes.length" class="orgchart-empty">
        <Empty :description="$t('customers.empty_hierarchy')" />
    </div>
    <div v-else class="orgchart-scroll">
        <ul class="orgchart">
            <li>
                <div class="org-box org-box--root">
                    <UserOutlined class="org-box__icon" />
                    <span class="org-box__name">{{ customerName }}</span>
                </div>
                <ul>
                    <OrgChartNode v-for="node in nodes" :key="`${node.type}-${node.id}`" :node="node" />
                </ul>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.orgchart-empty { padding: 28px 0; }
.orgchart-scroll { overflow-x: auto; padding: 8px 4px 16px; }
.orgchart { display: flex; justify-content: center; min-width: max-content; list-style: none; margin: 0; padding: 0; }
.orgchart > li { display: flex; flex-direction: column; align-items: center; }

.org-box {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 16px; border-radius: 8px; white-space: nowrap;
    border: 1px solid var(--color-border, #e5e7eb);
    background: var(--color-surface, #fff);
    font-size: 0.9rem; font-weight: 600;
}
.org-box--root { border-top: 3px solid #1f2937; }
.org-box--root .org-box__icon { color: #1f2937; }
.org-box__name { color: var(--color-text, #1f2937); }

/* Conector del nodo raíz hacia sus hijos */
.orgchart > li > ul { display: flex; padding-top: 20px; position: relative; margin: 0; list-style: none; }
.orgchart > li > ul::before {
    content: ''; position: absolute; top: 0; left: 50%;
    border-left: 1px solid var(--color-border, #cdd3da); width: 0; height: 20px;
}

html[data-theme="dark"] .org-box { background: #2c3034; border-color: #3f4448; }
html[data-theme="dark"] .org-box__name { color: #e5e6e7; }
html[data-theme="dark"] .orgchart > li > ul::before { border-color: #3f4448; }
</style>
