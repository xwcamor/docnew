<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Select, Tag, Alert, Empty, Button } from 'ant-design-vue';
import { NodeIndexOutlined, ArrowRightOutlined, WarningFilled } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';

defineOptions({ layout: AppLayout });

/**
 * «Para IZAJE quedan estas 4 firmas, en este orden.»
 *
 * El backend resuelve cada flujo con el mismo método que usa la creación de un
 * plan real, así que esto no es una simulación: es lo que va a pasar mañana en
 * obra con las reglas que hay ahora mismo.
 */
const props = defineProps({
    countries:  { type: Array,  default: () => [] },
    countryId:  { type: [Number, null], default: null },
    flows:      { type: Array,  default: () => [] },
    sequential: { type: Boolean, default: false },
});

const country = ref(props.countryId);
watch(country, (id) => {
    if (id === props.countryId) return;
    router.get(route('business_management.approval_rules.preview'), { country_id: id }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
});

// De dónde salen las firmas de cada tipo. Es la mitad de la explicación: sin
// esto, un tipo con las mismas 3 firmas que el país parece heredarlas cuando
// en realidad puede tener las suyas propias, idénticas por casualidad.
const sourceColor = (source) => ({
    own: 'geekblue',
    inherited: 'default',
    country: 'purple',
    none: 'error',
}[source] ?? 'default');
</script>

<template>
    <Head :title="$t('approval_rules.preview_title')" />

    <div class="preview-page sap-form">
        <SectionHeader
            :back-href="route('business_management.approval_rules.index')"
            :title="$t('approval_rules.preview_title')"
            :subtitle="$t('approval_rules.preview_subtitle')"
        >
            <template #icon><NodeIndexOutlined /></template>
        </SectionHeader>

        <div class="preview-toolbar">
            <label class="preview-toolbar__label" for="preview-country">{{ $t('approval_rules.preview_country') }}</label>
            <Select
                id="preview-country"
                v-model:value="country"
                size="large"
                show-search
                option-filter-prop="label"
                :options="countries"
                class="preview-toolbar__select"
            />
        </div>

        <Alert
            :type="sequential ? 'warning' : 'info'"
            show-icon
            class="preview-note"
            :message="sequential ? $t('approval_rules.sequential_on') : $t('approval_rules.sequential_off')"
        />

        <Empty v-if="flows.length === 0" :description="$t('approval_rules.preview_empty_country')" class="preview-empty" />

        <div v-else class="flow-grid">
            <Card
                v-for="flow in flows"
                :key="flow.work_type.id ?? 'all'"
                class="flow-card"
                :bodyStyle="{ padding: '16px 18px' }"
            >
                <template #title>
                    <div class="flow-card__head">
                        <span class="flow-card__type">{{ flow.work_type.code }}</span>
                        <Tag :color="sourceColor(flow.source)" :bordered="false">
                            {{ $t('approval_rules.preview_source_' + flow.source) }}
                        </Tag>
                    </div>
                </template>

                <p class="flow-card__count">
                    {{ $tc('approval_rules.preview_signatures', flow.signatures.length) }}
                </p>

                <!-- Sin ninguna firma, un plan de ese tipo nace aprobado de
                     hecho. Es un aviso, no un dato más. -->
                <Alert
                    v-if="flow.signatures.length === 0"
                    type="error"
                    show-icon
                    class="flow-card__warning"
                >
                    <template #icon><WarningFilled /></template>
                    <template #message>{{ $t('approval_rules.preview_none_warning') }}</template>
                </Alert>

                <ol v-else class="flow-steps">
                    <li v-for="(firma, i) in flow.signatures" :key="firma.role" class="flow-step">
                        <span class="flow-step__level">{{ firma.level }}</span>
                        <span class="flow-step__body">
                            <span class="flow-step__label">{{ firma.label }}</span>
                            <span class="flow-step__code">{{ firma.role }}</span>
                        </span>
                        <Tag :color="firma.required ? 'red' : 'default'" :bordered="false">
                            {{ firma.required ? $t('approval_rules.required') : $t('approval_rules.optional') }}
                        </Tag>
                        <ArrowRightOutlined v-if="i < flow.signatures.length - 1" class="flow-step__arrow" />
                    </li>
                </ol>
            </Card>
        </div>

        <div class="preview-footer">
            <Link :href="route('business_management.approval_rules.index')">
                <Button size="large">{{ $t('global.back') }}</Button>
            </Link>
        </div>
    </div>
</template>

<style scoped>
.preview-toolbar {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    margin-bottom: 14px;
}
.preview-toolbar__label { font-weight: 600; font-size: 0.875rem; }
/* En tablet el selector ocupa el ancho: dedo, no ratón. */
.preview-toolbar__select { min-width: 260px; flex: 1 1 260px; max-width: 420px; }

.preview-note { margin-bottom: 16px; }
.preview-empty { padding: 48px 16px; }

/* Rejilla fluida: en 10" caben dos tarjetas sin desbordar a lo ancho. */
.flow-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 14px;
}
.flow-card {
    border-radius: 12px;
    border: 1px solid var(--color-border, #e8eaed);
    box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 4px 12px rgba(16,24,40,0.04);
}
.flow-card__head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.flow-card__type { font-weight: 600; }
.flow-card__count { margin: 0 0 10px 0; color: var(--color-text-muted); font-size: 0.82rem; }
.flow-card__warning { margin-top: 4px; }

.flow-steps { list-style: none; margin: 0; padding: 0; }
.flow-step {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    /* Objetivo de toque cómodo: la fila entera mide más de 44 px. */
    min-height: 48px;
    padding: 6px 0;
    border-bottom: 1px solid var(--color-border-subtle, #f2f3f5);
}
.flow-step:last-child { border-bottom: none; }
.flow-step__level {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 30px; height: 30px; flex-shrink: 0;
    border-radius: 999px; font-weight: 700; font-size: 0.82rem;
    color: var(--color-primary, #0A6ED1);
    background: rgba(10, 110, 209, 0.10);
    border: 1px solid rgba(10, 110, 209, 0.20);
}
.flow-step__body { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
.flow-step__label { font-weight: 500; }
.flow-step__code { font-family: ui-monospace, Consolas, monospace; font-size: 0.75rem; color: var(--color-text-muted); }
.flow-step__arrow { display: none; }

.preview-footer { margin-top: 18px; }
</style>
