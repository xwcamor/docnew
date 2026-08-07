<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { Link } from '@inertiajs/vue3';

defineProps({ plan: Object, templates: Array });
defineOptions({ layout: AppLayout });

const color = { pending: 'default', draft: 'orange', submitted: 'blue', confirmed: 'green' };
const etiqueta = { pending: 'Sin empezar', draft: 'En borrador', submitted: 'Enviado', confirmed: 'Confirmado' };
</script>

<template>
    <div class="mi-console">
        <SectionHeader title="Formatos del plan" :subtitle="plan.code" />

        <a-list :data-source="templates" item-layout="horizontal" bordered>
            <template #renderItem="{ item }">
                <a-list-item>
                    <a-list-item-meta :title="item.code">
                        <template #description>
                            <a-tag v-if="item.required" color="red">Obligatorio</a-tag>
                            <a-tag v-if="item.kind === 'upload_only'">Solo foto del papel</a-tag>
                        </template>
                    </a-list-item-meta>
                    <a-tag :color="color[item.status]">{{ etiqueta[item.status] }}</a-tag>
                    <Link :href="route('field_work.forms.open', [plan.slug, item.slug])">
                        <a-button type="primary" size="small">Abrir</a-button>
                    </Link>
                </a-list-item>
            </template>
        </a-list>
    </div>
</template>
