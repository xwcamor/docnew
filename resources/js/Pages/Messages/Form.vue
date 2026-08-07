<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Card, Button, Input, Select, SelectOption, Switch, DatePicker,
    Radio, RadioGroup, Form, FormItem, Space, Alert,
} from 'ant-design-vue';
import { MessageOutlined, SaveOutlined, SendOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import RichTextEditor from '@/Components/Common/RichTextEditor.vue';
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

const submit = (publishNow = false) => {
    form.publish_now = publishNow;
    const payload = {
        ...form.data(),
        // expires_at: convertir dayjs a ISO string para el backend.
        expires_at: form.expires_at ? form.expires_at.toISOString() : null,
    };

    if (isEdit.value) {
        router.put(route('communication.messages.update', props.message.slug), payload, {
            onError: () => {},
        });
    } else {
        router.post(route('communication.messages.store'), payload, {
            onError: () => {},
        });
    }
};

const isPublished = computed(() => !!props.message?.published_at);
</script>

<template>
    <Head :title="isEdit ? t('messages.edit_message') : t('messages.new_message')" />

    <div class="message-form">
        <Card>
            <div class="page-header">
                <div class="page-header__title">
                    <Link :href="route('communication.messages.index')">
                        <Button type="text"><template #icon><ArrowLeftOutlined /></template></Button>
                    </Link>
                    <MessageOutlined class="page-header__icon" />
                    <h1>{{ isEdit ? t('messages.edit_message') : t('messages.new_message') }}</h1>
                </div>
            </div>

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
                        <SelectOption v-for="t in tenants" :key="t.id" :value="t.id" :label="t.name">
                            {{ t.name }}
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
                    <DatePicker v-model:value="form.expires_at" :placeholder="t('messages.no_expiration')" show-time />
                </FormItem>

                <FormItem :label="t('messages.is_active')" :tooltip="t('messages.is_active_help')">
                    <Switch v-model:checked="form.is_active" />
                </FormItem>

                <Space :size="8" style="margin-top:8px">
                    <Button type="default" :loading="form.processing" @click="submit(false)">
                        <template #icon><SaveOutlined /></template>
                        {{ t('messages.save_draft') }}
                    </Button>
                    <Button v-if="!isPublished" type="primary" :loading="form.processing" @click="submit(true)">
                        <template #icon><SendOutlined /></template>
                        {{ t('messages.save_and_publish') }}
                    </Button>
                    <Link :href="route('communication.messages.index')">
                        <Button type="text">{{ t('global.cancel') }}</Button>
                    </Link>
                </Space>
            </Form>
        </Card>
    </div>
</template>

<style scoped>
.message-form { padding: 16px; width: 100%; }
.page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; gap:10px; flex-wrap:wrap; }
.page-header__title { display:flex; align-items:center; gap:8px; min-width: 0; flex: 1; }
.page-header__title h1 { font-size:1.2rem; margin:0; word-break: break-word; }
.page-header__icon {
    width: 40px; height: 40px; border-radius: 4px;
    background: color-mix(in srgb, var(--color-primary) 12%, transparent); color: var(--color-primary);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}

@media (max-width: 767px) {
    .message-form { padding: 8px; }
    .page-header__title h1 { font-size: 1.05rem; }
    .message-form :deep(.ant-select),
    .message-form :deep(.ant-picker) { width: 100% !important; max-width: 100% !important; }
}
</style>
