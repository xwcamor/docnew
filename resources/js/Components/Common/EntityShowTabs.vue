<script setup>
/**
 * EntityShowTabs — wrapper de tabs para páginas Show de cualquier módulo.
 *
 * Patrón: 2 tabs (Detalles / Historial). El tab Historial se muestra solo si
 * el viewer tiene permiso (super o admin) — el componente NO calcula
 * eso, lo recibe como prop `showHistory`.
 *
 * Uso:
 *   <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
 *     <template #general>
 *       <Card>...</Card>
 *       <Card>...</Card>
 *     </template>
 *     <template #history>
 *       <ActivityTimeline :activity="activity" />
 *     </template>
 *   </EntityShowTabs>
 *
 * Layout: full-width single column dentro de cada tab. Si un módulo quiere
 * grid de 2 cols adentro, usar Row/Col dentro del slot — este wrapper no
 * impone layout interno.
 */
import { ref } from 'vue';
import { Tabs, TabPane, Badge } from 'ant-design-vue';
import { FileTextOutlined, HistoryOutlined } from '@ant-design/icons-vue';

defineProps({
    showHistory:  { type: Boolean, default: false },
    historyCount: { type: Number,  default: 0 },
    defaultKey:   { type: String,  default: 'general' },
});

const activeKey = ref('general');
</script>

<template>
    <Tabs
        v-model:activeKey="activeKey"
        class="entity-show-tabs"
        :tabBarStyle="{ marginBottom: '16px' }"
    >
        <TabPane key="general">
            <template #tab>
                <span class="tab-label">
                    <FileTextOutlined /> {{ $t('global.details') }}
                </span>
            </template>
            <slot name="general" />
        </TabPane>

        <!-- El tab Historial SIEMPRE se muestra: "Auditoría del registro" la ve
             cualquiera que pueda abrir el Show. El feed de actividad de adentro
             es lo que se gatea a super/admin (dentro de RecordHistory). -->
        <TabPane key="history">
            <template #tab>
                <span class="tab-label">
                    <HistoryOutlined /> {{ $t('global.history') }}
                    <Badge
                        v-if="historyCount > 0"
                        :count="historyCount"
                        :overflow-count="99"
                        :number-style="{ backgroundColor: 'var(--color-surface-alt, #f0f5ff)', color: 'var(--color-primary, #0A6ED1)', boxShadow: '0 0 0 1px var(--color-border, #d9d9d9) inset' }"
                        class="history-badge"
                    />
                </span>
            </template>
            <slot name="history" />
        </TabPane>
    </Tabs>
</template>

<style scoped>
.entity-show-tabs :deep(.ant-tabs-nav) {
    margin: 0 0 16px 0;
}
.entity-show-tabs :deep(.ant-tabs-tab) {
    padding: 10px 16px;
    font-size: 0.9375rem;
}
.entity-show-tabs :deep(.ant-tabs-tab .anticon) {
    margin-right: 6px;
}
.tab-label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.history-badge {
    margin-left: 6px;
}

/* Mobile: tabs un poco más chicas, scroll horizontal si no caben */
@media (max-width: 640px) {
    .entity-show-tabs :deep(.ant-tabs-tab) {
        padding: 8px 12px;
        font-size: 0.875rem;
    }
}
</style>
