<script setup>
import {
    EnvironmentFilled, AppstoreFilled, ClusterOutlined,
} from '@ant-design/icons-vue';

/**
 * OrgChartNode — nodo recursivo del organigrama del cliente (solo lectura).
 *
 * Vista alternativa al árbol: cajas top-down con conectores (CSS clásico de
 * organigrama). La edición vive en la vista de árbol; esta es para visualizar.
 *
 * Tenía un cuarto nivel «transformador» del sistema viejo que enlazaba a una
 * ruta ya purgada (`transformers.show`). Purgado también aquí.
 */
defineProps({
    node: { type: Object, required: true },
});

const ICONS = {
    location: EnvironmentFilled,
    area: AppstoreFilled,
    substation: ClusterOutlined,
};
</script>

<template>
    <li>
        <div class="org-box" :class="`org-box--${node.type}`">
            <component :is="ICONS[node.type] ?? AppstoreFilled" class="org-box__icon" />
            <span class="org-box__name">{{ node.name }}</span>
        </div>

        <ul v-if="node.children && node.children.length">
            <OrgChartNode v-for="child in node.children" :key="`${child.type}-${child.id}`" :node="child" />
        </ul>
    </li>
</template>

<style scoped>
/* Conectores clásicos de organigrama (nested ul/li). */
ul { display: flex; padding-top: 20px; position: relative; margin: 0; list-style: none; transition: all 0.3s; }
li { display: flex; flex-direction: column; align-items: center; padding: 0 12px; position: relative; transition: all 0.3s; }

/* Conector vertical de cada hijo hacia su línea horizontal */
li::before, li::after {
    content: ''; position: absolute; top: 0; right: 50%;
    border-top: 1px solid var(--color-border, #cdd3da); width: 50%; height: 20px;
}
li::after { right: auto; left: 50%; border-left: 1px solid var(--color-border, #cdd3da); }
li:only-child::after, li:only-child::before { display: none; }
li:only-child { padding-top: 0; }
li:first-child::before, li:last-child::after { border: 0 none; }
li:last-child::before { border-right: 1px solid var(--color-border, #cdd3da); border-radius: 0 6px 0 0; }
li:first-child::after { border-radius: 6px 0 0 0; }

/* Conector desde el padre hacia abajo */
ul::before {
    content: ''; position: absolute; top: 0; left: 50%;
    border-left: 1px solid var(--color-border, #cdd3da); width: 0; height: 20px;
}

.org-box {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 14px; border-radius: 8px; white-space: nowrap;
    border: 1px solid var(--color-border, #e5e7eb);
    background: var(--color-surface, #fff);
    font-size: 0.85rem; font-weight: 500;
    position: relative;
}
.org-box__icon { font-size: 0.85rem; }
.org-box--location { border-top: 3px solid #0A6ED1; }
.org-box--location .org-box__icon { color: #0A6ED1; }
.org-box--area { border-top: 3px solid #6A6D70; }
.org-box--area .org-box__icon { color: #6A6D70; }
.org-box--substation { border-top: 3px solid #8254c8; }
.org-box--substation .org-box__icon { color: #8254c8; }
.org-box__name { color: var(--color-text, #1f2937); }

html[data-theme="dark"] .org-box { background: #2c3034; border-color: #3f4448; }
html[data-theme="dark"] .org-box__name { color: #e5e6e7; }
html[data-theme="dark"] li::before, html[data-theme="dark"] li::after,
html[data-theme="dark"] li:last-child::before, html[data-theme="dark"] ul::before { border-color: #3f4448; }
</style>
