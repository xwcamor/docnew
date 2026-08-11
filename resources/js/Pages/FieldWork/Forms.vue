<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { Link } from '@inertiajs/vue3';
// FileOutlined es el icono con el que el formato sale en el menú lateral y en
// todas sus pantallas: un concepto, un icono en toda la aplicación.
import { FileOutlined, FilePdfOutlined } from '@ant-design/icons-vue';

defineProps({ plan: Object, templates: Array, canExport: { type: Boolean, default: false } });
defineOptions({ layout: AppLayout });

const color = { pending: 'default', draft: 'orange', submitted: 'blue', confirmed: 'green' };
const etiqueta = { pending: 'Sin empezar', draft: 'En borrador', submitted: 'Enviado', confirmed: 'Confirmado' };

/**
 * El PDF solo tiene sentido cuando el formato esta cerrado: en borrador seria
 * un documento a medias con firmas que aun pueden cambiar.
 */
const conPdf = (item) => item.submission && item.status === 'confirmed';
</script>

<template>
    <div class="mi-console">
        <SectionHeader title="Formatos del plan" :subtitle="plan.code">
            <template #icon><FileOutlined /></template>
        </SectionHeader>

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

                    <a
                        v-if="canExport && conPdf(item)"
                        :href="route('field_work.forms.pdf', item.submission)"
                        class="mr-2"
                    >
                        <a-button size="small">
                            <template #icon><FilePdfOutlined /></template>
                            PDF
                        </a-button>
                    </a>

                    <Link :href="route('field_work.forms.open', [plan.slug, item.slug])">
                        <a-button type="primary" size="small">Abrir</a-button>
                    </Link>
                </a-list-item>
            </template>
        </a-list>
    </div>
</template>
