<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';

const props = defineProps({ submission: Object, template: Object, answers: Array, missing: Array });
defineOptions({ layout: AppLayout });

const valores = reactive({});
props.answers.forEach((r) => {
    valores[r.form_field_id] = r.value_text ?? r.value_number ?? r.value_json ?? r.value_boolean;
});

const archivo = ref(null);

function guardar() {
    const respuestas = [];
    props.template.sections.forEach((s) => s.fields.forEach((c) => {
        if (valores[c.id] !== undefined) respuestas.push({ code: c.code, value: valores[c.id] });
    }));

    router.post(route('field_work.forms.answer', props.submission.slug), { answers: respuestas },
        { preserveScroll: true });
}

function subir() {
    if (!archivo.value) return;
    const datos = new FormData();
    datos.append('file', archivo.value);
    router.post(route('field_work.forms.attach', props.submission.slug), datos, { preserveScroll: true });
}

function confirmar() {
    router.post(route('field_work.forms.confirm', props.submission.slug), {}, { preserveScroll: true });
}
</script>

<template>
    <div class="mi-console">
        <SectionHeader :title="template.code"
                       :subtitle="`Version ${submission.template_version} · ${submission.status}`" />

        <a-alert v-if="missing.length" type="warning" show-icon class="mb-4"
                 :message="`Falta completar: ${missing.join(', ')}`" />

        <!-- La HOJA X: el formato es el papel, solo se le toma la foto -->
        <a-card v-if="template.kind !== 'structured'" title="Documento" size="small" class="mb-4">
            <input type="file" accept="image/*,application/pdf" @change="archivo = $event.target.files[0]" />
            <a-button type="primary" size="small" class="ml-2" @click="subir">Adjuntar</a-button>
        </a-card>

        <a-card v-for="s in template.sections" :key="s.id" size="small" class="mb-4">
            <div v-for="c in s.fields" :key="c.id" class="field">
                <label class="field__label">
                    {{ c.code }}<span v-if="c.is_required"> *</span>
                </label>

                <a-textarea v-if="c.field_type === 'textarea'" v-model:value="valores[c.id]" :rows="3" />
                <a-input-number v-else-if="c.field_type === 'number'" v-model:value="valores[c.id]" />
                <a-date-picker v-else-if="c.field_type === 'date'" v-model:value="valores[c.id]" />
                <a-checkbox v-else-if="c.field_type === 'checkbox'" v-model:checked="valores[c.id]" />
                <a-input v-else v-model:value="valores[c.id]" />

                <span class="field__hint" v-if="c.field_type !== 'text'">{{ c.field_type }}</span>
            </div>
        </a-card>

        <div class="action-bar">
            <a-button @click="guardar">Guardar</a-button>
            <span class="action-bar__spacer" />
            <a-button type="primary" @click="confirmar">Confirmar formato</a-button>
        </div>
    </div>
</template>
