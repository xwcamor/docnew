<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Empty } from 'ant-design-vue';

/**
 * CustomerStructureTable — vista de tabla plana de la jerarquía del cliente.
 * Una fila por transformador (o por subestación sin transformadores), con su
 * ruta Ubicación / Área / Subestación y el semáforo de salud. Solo lectura.
 */
import { useConditions } from '@/Composables/useConditions';

const props = defineProps({
    nodes: { type: Array, default: () => [] },
});

const { ratingLabel } = useConditions();

const healthColor = (hi) => {
    if (hi === null || hi === undefined) return '#9aa0a6';
    const v = Number(hi);
    if (v > 85) return '#1D7044';
    if (v > 70) return '#3FA66A';
    if (v > 50) return '#E9A23B';
    if (v > 30) return '#E8833A';
    return '#C8281D';
};

// Aplana el árbol a filas: una por transformador; si la subestación no tiene,
// una fila con el transformador en "—".
const rows = computed(() => {
    const out = [];
    for (const loc of props.nodes) {
        for (const area of loc.children ?? []) {
            for (const sub of area.children ?? []) {
                const trafos = sub.children ?? [];
                if (trafos.length === 0) {
                    out.push({ location: loc.name, area: area.name, substation: sub.name, trafo: null });
                } else {
                    for (const t of trafos) {
                        out.push({ location: loc.name, area: area.name, substation: sub.name, trafo: t });
                    }
                }
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
                    <th>{{ $t('transformers.singular') }}</th>
                    <th>{{ $t('transformers.health_index') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(r, i) in rows" :key="i">
                    <td>{{ r.location }}</td>
                    <td>{{ r.area }}</td>
                    <td>{{ r.substation }}</td>
                    <td>
                        <Link v-if="r.trafo" :href="route('business_management.transformers.show', r.trafo.slug)" class="st-link">
                            {{ r.trafo.name }}
                        </Link>
                        <span v-else class="st-muted">—</span>
                    </td>
                    <td>
                        <span v-if="r.trafo" class="st-health">
                            <span class="st-dot" :style="{ background: healthColor(r.trafo.health_index) }"></span>
                            <template v-if="r.trafo.health_index !== null && r.trafo.health_index !== undefined">
                                {{ Math.round(Number(r.trafo.health_index)) }}% · {{ ratingLabel(r.trafo.health_rating) }}
                            </template>
                            <span v-else class="st-muted">{{ $t('transformers.no_diagnostic') }}</span>
                        </span>
                        <span v-else class="st-muted">—</span>
                    </td>
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
.st-link { color: #0A6ED1; text-decoration: none; }
.st-link:hover { text-decoration: underline; }
.st-muted { color: var(--color-text-muted, #9aa0a6); }
.st-health { display: inline-flex; align-items: center; gap: 7px; }
.st-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }

html[data-theme="dark"] .st-table th, html[data-theme="dark"] .st-table td { border-bottom-color: #3f4448; }
html[data-theme="dark"] .st-table td { color: #e5e6e7; }
html[data-theme="dark"] .st-table tbody tr:hover { background: #2c3034; }
</style>
