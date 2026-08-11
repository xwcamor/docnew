<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Button, Input, Select, SelectOption, Switch, DatePicker,
    Radio, RadioGroup, Form, FormItem, Alert,
} from 'ant-design-vue';
import { MessageOutlined, SaveOutlined, SendOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import RichTextEditor from '@/Components/Common/RichTextEditor.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    message: { type: Object, default: null },
    tenants: { type: Array,  default: () => [] },
    users:   { type: Array,  default: () => [] },
});

const isEdit = computed(() => !!props.message);

const form = useForm({
    subject:       props.message?.subject ?? '',
    body:          props.message?.body ?? '',
    audience_type: props.message?.audience_type ?? 'global',
    audience_id:   props.message?.audience_id ?? null,
    allow_replies: !!props.message?.allow_replies,
    is_active:     props.message?.is_active ?? true,
    expires_at:    props.message?.expires_at ? dayjs(props.message.expires_at) : null,
    publish_now:   false,
});

// Cuando cambia el audience_type, reseteamos audience_id (excepto si quedaba ya seteado para edicion).
watch(() => form.audience_type, (val, oldVal) => {
    if (val !== oldVal) {
        form.audience_id = null;
    }
});

// Se envía SIEMPRE con el helper del form (form.put / form.post), nunca con
// router.*: `router` no rellena `form.errors` ni `form.processing`, así que
// con él un asunto vacío devolvía 422 y la pantalla no decía absolutamente
// nada — el botón ni siquiera giraba. Los `:help` de cada campo dependen de
// que los errores lleguen por aquí.
const submit = (publishNow = false) => {
    form.publish_now = publishNow;

    // expires_at viaja como ISO; el DatePicker guarda un objeto dayjs.
    //
    // `endOf('day')` porque ahora se elige solo el día: sin esto un mensaje que
    // caduca «el 15» se apagaría a las 00:00 de ese 15, o sea el día antes de
    // lo que quien lo escribió tenía en la cabeza.
    form.transform((data) => ({
        ...data,
        expires_at: data.expires_at ? data.expires_at.endOf('day').toISOString() : null,
    }));

    if (isEdit.value) {
        form.put(route('communication.messages.update', props.message.slug));
    } else {
        form.post(route('communication.messages.store'));
    }
};

const isPublished = computed(() => !!props.message?.published_at);
</script>

<template>
    <Head :title="isEdit ? t('messages.edit_message') : t('messages.new_message')" />

    <!-- `.sap-form` + `.form-body`, como el resto de formularios: esta pantalla
         era la unica sin la barra de acciones compartida — los botones iban
         sueltos al final de una Card, asi que ni sangraban hasta los bordes ni
         apoyaban en el borde inferior como en todos los demas modulos
         (docs/UI.md §8). -->
    <div class="form-page sap-form message-form">
        <SectionHeader
            :back-href="route('communication.messages.index')"
            :title="isEdit ? t('messages.edit_message') : t('messages.new_message')"
        >
            <template #icon><MessageOutlined /></template>
        </SectionHeader>

        <div class="form-body">
            <Alert
                v-if="isPublished"
                type="info"
                show-icon
                :message="t('messages.status_published')"
                style="margin-bottom: 16px"
            />

            <Form layout="vertical">
                <FormItem
                    :label="t('messages.subject')"
                    :tooltip="t('messages.subject_help')"
                    required
                    :validate-status="form.errors.subject ? 'error' : ''"
                    :help="form.errors.subject"
                >
                    <Input v-model:value="form.subject" :maxlength="200" show-count :placeholder="t('messages.subject_placeholder')" />
                </FormItem>

                <FormItem
                    :label="t('messages.body')"
                    :tooltip="t('messages.body_help')"
                    required
                    :validate-status="form.errors.body ? 'error' : ''"
                    :help="form.errors.body"
                >
                    <RichTextEditor v-model="form.body" />
                </FormItem>

                <FormItem
                    :label="t('messages.audience_type')"
                    :tooltip="t('messages.audience_type_help')"
                    :validate-status="form.errors.audience_type ? 'error' : ''"
                    :help="form.errors.audience_type"
                >
                    <RadioGroup v-model:value="form.audience_type" :disabled="isPublished">
                        <Radio value="global">{{ t('messages.audience_global') }}</Radio>
                        <Radio value="tenant">{{ t('messages.audience_tenant') }}</Radio>
                        <Radio value="user">{{ t('messages.audience_user') }}</Radio>
                    </RadioGroup>
                </FormItem>

                <FormItem
                    v-if="form.audience_type === 'tenant'"
                    :label="t('messages.audience_select_tenant')"
                    :tooltip="t('messages.audience_select_tenant_help')"
                    required
                    :validate-status="form.errors.audience_id ? 'error' : ''"
                    :help="form.errors.audience_id"
                >
                    <Select
                        v-model:value="form.audience_id"
                        :placeholder="t('messages.audience_select_tenant')"
                        show-search
                        option-filter-prop="label"
                        :disabled="isPublished"
                        style="max-width: 420px"
                    >
                        <!-- `w`, no `t`: `t` es la función de traducción y
                             dentro del v-for la tapaba. -->
                        <SelectOption v-for="w in tenants" :key="w.id" :value="w.id" :label="w.name">
                            {{ w.name }}
                        </SelectOption>
                    </Select>
                </FormItem>

                <FormItem
                    v-if="form.audience_type === 'user'"
                    :label="t('messages.audience_select_user')"
                    :tooltip="t('messages.audience_select_user_help')"
                    required
                    :validate-status="form.errors.audience_id ? 'error' : ''"
                    :help="form.errors.audience_id"
                >
                    <Select
                        v-model:value="form.audience_id"
                        :placeholder="t('messages.audience_select_user')"
                        show-search
                        option-filter-prop="label"
                        :disabled="isPublished"
                        style="max-width: 480px"
                    >
                        <SelectOption v-for="u in users" :key="u.id" :value="u.id" :label="`${u.name} (${u.email})`">
                            {{ u.name }} <span style="color:#999">({{ u.email }})</span>
                        </SelectOption>
                    </Select>
                </FormItem>

                <FormItem :label="t('messages.allow_replies')" :tooltip="t('messages.allow_replies_help')">
                    <Switch v-model:checked="form.allow_replies" />
                    <span style="margin-left:8px; color:#666; font-size:0.85rem;">
                        {{ t('messages.allow_replies') }}
                    </span>
                </FormItem>

                <FormItem :label="t('messages.expires_at')" :tooltip="t('messages.expires_at_help')">
                    <!-- Sin `show-time`: obligaba a pulsar «Aceptar» para
                         fijar la caducidad al minuto, que nadie necesita. Se
                         elige el día y caduca al acabar ese día (ver submit). -->
                    <DatePicker
                        v-model:value="form.expires_at"
                        :placeholder="t('messages.no_expiration')"
                        format="DD-MM-YYYY"
                    />
                </FormItem>

                <FormItem :label="t('messages.is_active')" :tooltip="t('messages.is_active_help')">
                    <Switch v-model:checked="form.is_active" />
                </FormItem>

                <!-- La barra compartida, con las dos acciones propias en el slot:
                     publicar (primaria, pegada al borde derecho) y guardar
                     borrador. El Cancelar lo pone la propia barra. -->
                <FormFooter :cancel-href="route('communication.messages.index')">
                    <template #submit>
                        <Button v-if="!isPublished" type="primary" :loading="form.processing" @click="submit(true)">
                            <template #icon><SendOutlined /></template>
                            {{ t('messages.save_and_publish') }}
                        </Button>
                        <Button :type="isPublished ? 'primary' : 'default'" :loading="form.processing" @click="submit(false)">
                            <template #icon><SaveOutlined /></template>
                            {{ t('messages.save_draft') }}
                        </Button>
                    </template>
                </FormFooter>
            </Form>
        </div>
    </div>
</template>

<style scoped>
/* El layout de la pagina (fondo, sangrados, cabecera) lo ponen `.sap-form` y
   `SectionHeader` desde app.css; aqui solo queda lo propio de esta pantalla. */
@media (max-width: 767px) {
    /* Los selects de audiencia y el DatePicker llevan max-width en escritorio;
       a media pantalla se estiran para que el dedo los acierte. */
    .message-form :deep(.ant-select),
    .message-form :deep(.ant-picker) { width: 100% !important; max-width: 100% !important; }
}
</style>
