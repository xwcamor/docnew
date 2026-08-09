<script setup>
import { computed } from 'vue';
import { Empty } from 'ant-design-vue';

/**
 * CustomerStructureTable — vista de tabla plana de la jerarquía del cliente.
 * Una fila por subestación, con su ruta Ubicación / Área / Subestación.
 * Solo lectura.
 *
 * Traía dos columnas más del sistema viejo — «Transformador» e «Índice de
 * salud» — que salían con la CABECERA SIN TRADUCIR (`transformers.singular`,
 * en mayúsculas por el CSS de la tabla) y la celda siempre en «—», porque el
 * payload de la jerarquía no lleva transformadores desde la purga. Purgadas.
 */
const props = defineProps({
    nodes: { type: Array, default: () => [] },
});

// Aplana el árbol a filas: una por subestación.
const rows = computed(() => {
    const out = [];
    for (const loc of props.nodes) {
        for (const area of loc.children ?? []) {
            for (const sub of area.children ?? []) {
                out.push({ location: loc.name, area: area.name, substation: sub.name });
            }
        }
    }
    return out;
});
</script>

<template>
    <div v-if="!rows.length" class="st-empty">
        <Empty :description="$t('customers.empty_hierarchy')" />
    </div>
    <div v-else class="st-wrap">
        <table class="st-table">
            <thead>
                <tr>
                    <th>{{ $t('customers.location') }}</th>
                    <th>{{ $t('customers.area') }}</th>
                    <th>{{ $t('customers.substation') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(r, i) in rows" :key="i">
                    <td>{{ r.location }}</td>
                    <td>{{ r.area }}</td>
                    <td>{{ r.substation }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.st-empty { padding: 24px 0; }
.st-wrap { overflow-x: auto; }
.st-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.st-table th, .st-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--color-border, #eef0f2); white-space: nowrap; }
.st-table th { color: var(--color-text-muted, #6A6D70); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.4px; }
.st-table tbody tr:hover { background: var(--color-surface-alt, #f7f9fb); }
.st-muted { color: var(--color-text-muted, #9aa0a6); }

html[data-theme="dark"] .st-table th, html[data-theme="dark"] .st-table td { border-bottom-color: #3f4448; }
html[data-theme="dark"] .st-table td { color: #e5e6e7; }
html[data-theme="dark"] .st-table tbody tr:hover { background: #2c3034; }
</style>
