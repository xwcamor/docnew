<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert,
} from 'ant-design-vue';
import { IdcardOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    person: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.person.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="person.full_name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.people.index')"
            :title="person.full_name"
            :icon-bg="iconBg"
        >
            <template #icon><IdcardOutlined /></template>
            <template #subtitle>
                <Space :size="6">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="person.is_active ? 'success' : 'default'" :bordered="false">
                        {{ person.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="people"
                    route-prefix="business_management"
                    :slug="person.slug"
                    :id="person.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('people.edit')"
                    :can-delete="can('people.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :is-global="person.tenant_id === null"
                    :lock="person.lock"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(person.deleted_at) }}</div>
                <div v-if="person.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ person.deleter.name }}
                </div>
                <div v-if="person.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ person.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="people" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><IdcardOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <!-- ID y slug: solo el super (datos técnicos), y van primero. -->
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ person.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ person.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.name') }}</span>
                            <span class="spec-cell__value">{{ person.name }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.lastname') }}</span>
                            <span class="spec-cell__value">{{ person.lastname }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.document') }}</span>
                            <span class="spec-cell__value">{{ person.doc_type }} <code>{{ person.num_doc }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.country') }}</span>
                            <span class="spec-cell__value">{{ person.country?.name || '—' }}</span>
                        </div>
                        <!-- La nacionalidad se guardaba y no se enseñaba en
                             ninguna parte. Cuando no es la del país donde
                             trabaja se marca: es quien lleva carné de
                             extranjería en vez de DNI, y es lo que se comprueba
                             en la puerta. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.nationality') }}</span>
                            <span class="spec-cell__value">
                                <Tag v-if="person.foreign_nationality" color="gold" :bordered="false">
                                    {{ person.foreign_nationality }}
                                </Tag>
                                <template v-else>{{ person.nationality?.code || '—' }}</template>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.birthdate') }}</span>
                            <span class="spec-cell__value">{{ person.birthdate || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.roles') }}</span>
                            <span class="spec-cell__value">
                                <template v-if="person.roles?.length">
                                    <Tag v-for="r in person.roles" :key="r" color="geekblue" :bordered="false">{{ $t('people.role_' + r) }}</Tag>
                                </template>
                                <template v-else>—</template>
                            </span>
                        </div>
                        <!-- Sin cara enrolada la persona no puede firmar en obra. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.biometric') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="person.has_biometric ? 'success' : 'error'" :bordered="false">
                                    {{ person.has_biometric ? $t('people.biometric_yes') : $t('people.biometric_no') }}
                                </Tag>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.signatures') }}</span>
                            <span class="spec-cell__value">{{ person.signatures_count ?? 0 }}</span>
                        </div>
                        <div class="spec-cell spec-cell--wide">
                            <span class="spec-cell__label">{{ $t('people.companies') }}</span>
                            <span class="spec-cell__value">
                                <template v-if="person.companies?.length">
                                    <Tag v-for="c in person.companies" :key="c.company_id" :color="c.is_active ? 'blue' : 'default'" :bordered="false">
                                        {{ c.name }}<template v-if="c.position"> · {{ c.position }}</template>
                                    </Tag>
                                </template>
                                <template v-else>—</template>
                            </span>
                        </div>
                        <!-- Estado: siempre al final. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="person.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ person.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>
            </template>

            <template #history>
                <RecordHistory :record-audit="recordAudit" :activity="activity" :can-see-activity="canSeeAudit" />
            </template>
        </EntityShowTabs>
    </div>
</template>

<style scoped>
.show-page { /* fullscreen — sin max-width, ocupa todo el ancho del content */ }
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }

@media (max-width: 767px) {
    :deep(.ant-descriptions-item-label) {
        width: auto !important;
        min-width: 0 !important;
        white-space: normal !important;
        font-weight: 500;
    }
    :deep(.ant-descriptions-item-content) { word-break: break-word; }
}
</style>
