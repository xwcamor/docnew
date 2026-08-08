<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Form, FormItem, Select, InputNumber, Switch, Space, Alert, Tag } from 'ant-design-vue';
import { NodeIndexOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    approvalRule:     { type: Object, default: null },
    options:          { type: Object, default: () => ({}) },
    sequential:       { type: Boolean, default: false },
    defaultCountryId: { type: [Number, null], default: null },
});

const isEdit = computed(() => !!props.approvalRule);

const form = useForm({
    country_id:     props.approvalRule?.country_id ?? props.defaultCountryId ?? null,
    // null (no 0, no '') significa «todos los tipos de trabajo».
    work_type_id:   props.approvalRule?.work_type_id ?? null,
    approver_role:  props.approvalRule?.approver_role ?? null,
    priority_level: props.approvalRule?.priority_level ?? 1,
    is_required:    props.approvalRule?.is_required ?? true,
    is_active:      props.approvalRule?.is_active ?? true,
});

// Los tipos de trabajo son de un país: ofrecer los de otro sería ofrecer algo
// que la regla nunca podrá aplicar.
const workTypeOptions = computed(() => [
    { value: null, label: '— ' + t('approval_rules.all_work_types') + ' —' },
    ...(props.options.work_types ?? []).filter((tipo) => tipo.country_id === form.country_id),
]);

// Al cambiar de país, un tipo del país anterior deja de tener sentido.
watch(() => form.country_id, (nuevo, viejo) => {
    if (viejo !== undefined && nuevo !== viejo) form.work_type_id = null;
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.approval_rules.update', props.approvalRule.slug));
    } else {
        form.post(route('business_management.approval_rules.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('approval_rules.edit_title') : $t('approval_rules.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.approval_rules.index')"
            :title="isEdit ? $t('approval_rules.edit_title') : $t('approval_rules.new')"
            :subtitle="$t('approval_rules.create_subtitle')"
        >
            <template #icon><NodeIndexOutlined /></template>
        </SectionHeader>

        <div class="form-body">
            <Form
                layout="horizontal"
                :label-col="{ xs: 24, sm: 8, md: 6 }"
                :wrapper-col="{ xs: 24, sm: 16, md: 13 }"
                label-align="right"
                :colon="true"
                @submit.prevent="submit"
            >
                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <FormItem
                    :label="$t('approval_rules.country')"
                    :tooltip="$t('approval_rules.country_help')"
                    required
                    :validate-status="form.errors.country_id ? 'error' : ''"
                    :help="form.errors.country_id"
                >
                    <Select
                        v-model:value="form.country_id"
                        size="large"
                        show-search
                        option-filter-prop="label"
                        :options="options.countries ?? []"
                        :placeholder="$t('approval_rules.country')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('approval_rules.work_type')"
                    :tooltip="$t('approval_rules.work_type_help')"
                    :validate-status="form.errors.work_type_id ? 'error' : ''"
                    :help="form.errors.work_type_id"
                >
                    <Select
                        v-model:value="form.work_type_id"
                        size="large"
                        show-search
                        option-filter-prop="label"
                        :options="workTypeOptions"
                        :disabled="!form.country_id"
                    />
                    <!-- Sin tipo, la regla vale para todos. Con tipo, ese tipo
                         deja de heredar las generales: es la consecuencia que
                         más sorprende, así que se dice en el propio formulario. -->
                    <p class="field-note">
                        {{ form.work_type_id
                            ? $t('approval_rules.how_it_works_override')
                            : $t('approval_rules.how_it_works_general') }}
                    </p>
                </FormItem>

                <FormItem
                    :label="$t('approval_rules.approver_role')"
                    :tooltip="$t('approval_rules.approver_role_help')"
                    required
                    :validate-status="form.errors.approver_role ? 'error' : ''"
                    :help="form.errors.approver_role"
                >
                    <Select
                        v-model:value="form.approver_role"
                        size="large"
                        show-search
                        option-filter-prop="label"
                        :options="options.approver_roles ?? []"
                        :placeholder="$t('approval_rules.approver_role')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('approval_rules.priority_level')"
                    :tooltip="$t('approval_rules.priority_level_help')"
                    required
                    :validate-status="form.errors.priority_level ? 'error' : ''"
                    :help="form.errors.priority_level"
                >
                    <Space wrap>
                        <InputNumber v-model:value="form.priority_level" size="large" :min="1" :max="20" style="width: 140px" />
                        <Tag :color="sequential ? 'orange' : 'default'" :bordered="false" class="seq-tag">
                            {{ sequential ? $t('approval_rules.sequential_on') : $t('approval_rules.sequential_off') }}
                        </Tag>
                    </Space>
                </FormItem>

                <FormItem
                    :label="$t('approval_rules.is_required')"
                    :tooltip="$t('approval_rules.is_required_help')"
                    :validate-status="form.errors.is_required ? 'error' : ''"
                    :help="form.errors.is_required"
                >
                    <Space>
                        <Switch v-model:checked="form.is_required" />
                        <span class="state-label">
                            {{ form.is_required ? $t('approval_rules.required') : $t('approval_rules.optional') }}
                        </span>
                    </Space>
                </FormItem>

                <FormItem
                    v-if="isEdit"
                    :label="$t('approval_rules.is_active')"
                    :tooltip="$t('approval_rules.is_active_help')"
                    :validate-status="form.errors.is_active ? 'error' : ''"
                    :help="form.errors.is_active"
                >
                    <Space>
                        <Switch v-model:checked="form.is_active" />
                        <span class="state-label">
                            {{ form.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                    </Space>
                </FormItem>

                <FormFooter
                    :cancel-href="route('business_management.approval_rules.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </div>
    </div>
</template>

<style scoped>
.state-label { font-size: 0.875rem; color: var(--color-text); font-weight: 500; }
.field-note { margin: 6px 0 0 0; font-size: 0.8rem; color: var(--color-text-muted); line-height: 1.5; }
.seq-tag { white-space: normal; max-width: 460px; line-height: 1.5; padding: 4px 10px; }
.mb-4 { margin-bottom: 16px; }
</style>
