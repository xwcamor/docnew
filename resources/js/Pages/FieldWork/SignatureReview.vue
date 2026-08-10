<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';

defineProps({ events: Object });
defineOptions({ layout: AppLayout });

const motivo = ref('');

function resolver(evento, aceptada) {
    router.post(route('field_work.signatures.resolve', evento.id), {
        accepted: aceptada,
        reason: motivo.value || null,
    }, { preserveScroll: true, onSuccess: () => { motivo.value = ''; } });
}
</script>

<template>
    <div class="mi-console">
        <SectionHeader
            :title="$t('sidebar.signature_events')"
            subtitle="Firmas que se capturaron sin reconocimiento y esperan revision" />

        <a-empty v-if="!events.data.length" description="No hay firmas pendientes" />

        <a-card v-for="e in events.data" :key="e.id" size="small" class="mb-3">
            <div class="revision">
                <img v-if="e.evidence[0]?.url" :src="e.evidence[0].url" alt="evidencia" class="revision-foto" />
                <div class="revision-datos">
                    <strong>{{ e.person.name }} {{ e.person.lastname }}</strong>
                    <span class="text-muted">{{ e.person.num_doc }}</span>
                    <div>
                        <a-tag :color="e.manual_override ? 'red' : 'orange'">
                            {{ e.manual_override ? 'Firma manual' : 'Capturada por tiempo de espera' }}
                        </a-tag>
                        <span v-if="e.match_distance !== null" class="text-muted">
                            distancia {{ e.match_distance }} · umbral {{ e.threshold_used }}
                        </span>
                    </div>
                    <p v-if="e.override_reason" class="text-muted">{{ e.override_reason }}</p>
                </div>
                <div class="revision-acciones">
                    <a-input v-model:value="motivo" :placeholder="$t('field_work.signature_reason_placeholder')" size="small" />
                    <a-button type="primary" size="small" @click="resolver(e, true)">Aceptar</a-button>
                    <a-button danger size="small" @click="resolver(e, false)">Rechazar</a-button>
                </div>
            </div>
        </a-card>
    </div>
</template>

<style scoped>
.revision { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
.revision-foto { width: 96px; height: 96px; object-fit: cover; border-radius: 8px; }
.revision-datos { flex: 1; display: flex; flex-direction: column; gap: 4px; }
.revision-acciones { display: flex; gap: 8px; align-items: center; }
.text-muted { color: var(--color-text-muted); font-size: 12px; }
</style>
