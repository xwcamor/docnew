<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select,
} from 'ant-design-vue';
import { FlagOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    country:       { type: Object, default: null },
    regionOptions: { type: Array, default: () => [] },
    localeOptions: { type: Array, default: () => [] },
});

const isEdit = computed(() => !!props.country);

const form = useForm({
    name:              props.country?.name ?? '',
    iso_code:          props.country?.iso_code ?? '',
    currency:          props.country?.currency ?? '',
    timezone:          props.country?.timezone ?? '',
    region_id:         props.country?.region_id ?? null,
    default_locale_id: props.country?.default_locale_id ?? null,
    is_active:         props.country?.is_active ?? true,
});

const page = usePage();

// La lista completa de zonas IANA ya viaja como prop compartida (`tz.available`,
// la misma que usan Perfil y Espacios de trabajo). Aquí había en su lugar una
// lista escrita a mano de 21 entradas, y el desplegable NO era libre: para dar de
// alta Ecuador (America/Guayaquil) o Bolivia (America/La_Paz) no había opción y
// había que elegir una zona equivocada. El backend siempre aceptó cualquier IANA.
const tzOptions = computed(() =>
    (page.props.tz?.available ?? []).map(tz => ({ value: tz, label: tz })),
);

const submit = () => {
    if (isEdit.value) {
        form.put(route('system_management.countries.update', props.country.slug));
    } else {
        form.post(route('system_management.countries.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('countries.singular') : $t('countries.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('system_management.countries.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('countries.record') : $t('countries.new')"
            :subtitle="isEdit ? country.name : $t('countries.form_create_hint')"
        >
            <template #icon><FlagOutlined /></template>
        </SectionHeader>

        <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <!-- `form-grid` es obligatorio para que las columnas se respeten:
                     app.css aplana con !important toda fila que no la lleve, y este
                     formulario se pintaba como una tira vertical de siete campos
                     aunque el template dijera dos columnas. El corte es `lg` (992)
                     a propósito — en tablet vertical se apila. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('countries.name')"
                            :tooltip="$t('countries.name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                :placeholder="$t('countries.name_placeholder')"
                                size="large"
                                :maxlength="255"
                                showCount
                                autofocus
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="12" :lg="6">
                        <FormItem
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            :label="$t('countries.iso_code')"
                            :tooltip="$t('countries.iso_code_help')"
                            required
                            :validate-status="form.errors.iso_code ? 'error' : ''"
                            :help="form.errors.iso_code"
                        >
                            <Input
                                v-model:value="form.iso_code"
                                :placeholder="$t('countries.iso_code_placeholder')"
                                size="large"
                                :maxlength="2"
                                style="text-transform: uppercase;"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="12" :lg="6">
                        <FormItem
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            :label="$t('countries.currency')"
                            :tooltip="$t('countries.currency_help')"
                            required
                            :validate-status="form.errors.currency ? 'error' : ''"
                            :help="form.errors.currency"
                        >
                            <Input
                                v-model:value="form.currency"
                                :placeholder="$t('countries.currency_placeholder')"
                                size="large"
                                :maxlength="3"
                                style="text-transform: uppercase;"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('countries.region')"
                            :tooltip="$t('countries.region_help')"
                            required
                            :validate-status="form.errors.region_id ? 'error' : ''"
                            :help="form.errors.region_id"
                        >
                            <Select
                                v-model:value="form.region_id"
                                :options="regionOptions"
                                :placeholder="$t('countries.region_placeholder')"
                                size="large"
                                show-search
                                option-filter-prop="label"
                            />
                        </FormItem>
                    </Col>

                    <!-- El idioma NO es un adorno: el PDF de un formato firmado se
                         emite en el idioma del pais del plan, no en el de quien lo
                         descarga (App\Services\FieldWork\FormSubmissionPdfService).
                         El aviso va debajo del campo, visible: un tooltip en una
                         tablet con guantes no se abre. -->
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('countries.default_locale')"
                            :tooltip="$t('countries.default_locale_help')"
                            required
                            :validate-status="form.errors.default_locale_id ? 'error' : ''"
                            :help="form.errors.default_locale_id || $t('countries.default_locale_notice')"
                        >
                            <Select
                                v-model:value="form.default_locale_id"
                                :options="localeOptions"
                                :placeholder="$t('countries.default_locale_placeholder')"
                                size="large"
                                show-search
                                option-filter-prop="label"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('countries.timezone')"
                            :tooltip="$t('countries.timezone_help')"
                            required
                            :validate-status="form.errors.timezone ? 'error' : ''"
                            :help="form.errors.timezone"
                        >
                            <Select
                                v-model:value="form.timezone"
                                :options="tzOptions"
                                :placeholder="$t('countries.timezone_placeholder')"
                                size="large"
                                show-search
                                option-filter-prop="label"
                            />
                        </FormItem>
                    </Col>

                    <Col v-if="isEdit" :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('countries.is_active')"
                            :tooltip="$t('countries.is_active_help')"
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
                    </Col>
                </Row>

                <FormFooter
                    :cancel-href="route('system_management.countries.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>
    </div>
</template>

<style scoped>
.form-card { border-radius: 6px; }
.state-label {
    font-size: 0.875rem;
    color: var(--color-text);
    font-weight: 500;
}
.mb-4 { margin-bottom: 16px; }
</style>
