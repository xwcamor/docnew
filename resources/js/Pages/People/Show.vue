<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert, Modal, Upload, Button, Tooltip,
} from 'ant-design-vue';
import { IdcardOutlined, CameraOutlined, UploadOutlined, DeleteOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const props = defineProps({
    person: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
// `t` del composable y no el `$t` global: `$t` lee `this.$page`, y pasado a un
// `Modal.confirm()` pierde el `this` y revienta.
const { t } = useI18n();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.person.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);

// ── Retirar el rostro registrado ─────────────────────────────────────────────
//
// Existe porque se lo prometemos. El texto que el trabajador acepta antes de
// que se le registre la cara dice que puede pedir en cualquier momento que se
// borre, y hasta ahora eso sólo se podía hacer entrando a la base por SQL: la
// promesa no era cierta.
//
// Con confirmación, y la confirmación dice las dos cosas que hay que saber
// antes de pulsar: que se borra de verdad —no se desactiva— y que las firmas
// que esa persona ya dio NO se tocan. Un documento firmado hace ocho meses dice
// que ese día se la reconoció por la cara, y eso pasó; retirar el dato de hoy
// no reescribe lo de entonces.
const retirando = ref(false);

function retirarRostro() {
    Modal.confirm({
        title: t('people.biometric_forget'),
        content: t('people.biometric_forget_confirm', { name: props.person.full_name ?? props.person.name }),
        okText: t('people.biometric_forget'),
        okType: 'danger',
        cancelText: t('global.cancel'),
        onOk: () => {
            retirando.value = true;

            router.delete(route('business_management.people.biometric.forget', props.person.slug), {
                preserveScroll: true,
                onFinish: () => { retirando.value = false; },
            });
        },
    });
}

// ── Foto de referencia y firma ───────────────────────────────────────────────
//
// La foto que se captura al firmar sale a contraluz, con casco y en
// movimiento: con ella no se reconoce a nadie. Aqui se sube la buena, que es lo
// que hacia el administrador en el sistema anterior.
const subiendo = ref(null);

/** Devuelve `false` para que Ant Design no suba el archivo por su cuenta. */
const subir = (tipo, archivo) => {
    subiendo.value = tipo;

    router.post(
        route('business_management.people.media.store', [props.person.slug, tipo]),
        { file: archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => { subiendo.value = null; },
        },
    );

    return false;
};
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
                        <!-- Aquí salía la nacionalidad, y era la misma pregunta
                             hecha dos veces: el tipo de documento ya dice quién
                             viene de fuera —en Perú un peruano lleva DNI y un
                             extranjero carné o PTP— y eso es lo que se comprueba
                             en la puerta. La columna se borró. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('people.origin') }}</span>
                            <span class="spec-cell__value">
                                <Tag v-if="person.is_foreigner" color="gold" :bordered="false">
                                    {{ $t('people.origin_foreign') }}
                                </Tag>
                                <template v-else>{{ $t('people.origin_local') }}</template>
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

                                <!-- Sólo si hay algo que retirar. Un botón que
                                     únicamente puede fallar es peor que un botón
                                     que no está (docs/UI.md §6). -->
                                <Tooltip v-if="person.has_biometric && can('people.edit')" :title="$t('people.biometric_forget_hint')">
                                    <Button size="small" danger :loading="retirando" @click="retirarRostro">
                                        <template #icon><DeleteOutlined /></template>
                                        {{ $t('people.biometric_forget') }}
                                    </Button>
                                </Tooltip>
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

                <!-- Foto de referencia y firma. Solo con `people.view_media`,
                     que por defecto tiene el super y no el admin del workspace:
                     esto es material del administrador del sistema. La firma se
                     ENSEÑA aqui y en ningun otro sitio de la aplicacion —al
                     papel solo llega dentro del PDF. -->
                <Card v-if="person.media" size="small" class="info-card">
                    <template #title><CameraOutlined /> {{ $t('people.media_title') }}</template>

                    <div class="media-grid">
                        <div class="media-slot">
                            <span class="media-slot__label">{{ $t('people.photo') }}</span>

                            <img v-if="person.media.photo_url" :src="person.media.photo_url" class="media-slot__foto" alt="">
                            <div v-else class="media-slot__vacio">{{ $t('people.no_photo') }}</div>

                            <!-- Una capturada es la que se tomo en obra a falta
                                 de otra, y es justo la que hay que reemplazar. -->
                            <Tag
                                v-if="person.media.photo_source"
                                :color="person.media.photo_source === 'captured' ? 'orange' : 'blue'"
                                :bordered="false"
                            >
                                {{ $t(`people.photo_source_${person.media.photo_source}`) }}
                            </Tag>

                            <Upload
                                :before-upload="(f) => subir('photo', f)"
                                :show-upload-list="false"
                                accept="image/jpeg,image/png,image/webp"
                            >
                                <Button size="small" :loading="subiendo === 'photo'">
                                    <template #icon><UploadOutlined /></template>
                                    {{ person.media.photo_url ? $t('people.replace') : $t('people.upload') }}
                                </Button>
                            </Upload>
                        </div>

                        <div class="media-slot">
                            <span class="media-slot__label">{{ $t('people.signature') }}</span>

                            <img v-if="person.media.signature_url" :src="person.media.signature_url" class="media-slot__firma" alt="">
                            <div v-else class="media-slot__vacio">{{ $t('people.no_signature') }}</div>

                            <Upload
                                :before-upload="(f) => subir('signature', f)"
                                :show-upload-list="false"
                                accept="image/jpeg,image/png,image/webp"
                            >
                                <Button size="small" :loading="subiendo === 'signature'">
                                    <template #icon><UploadOutlined /></template>
                                    {{ person.media.signature_url ? $t('people.replace') : $t('people.upload') }}
                                </Button>
                            </Upload>
                        </div>
                    </div>

                    <p class="media-nota">{{ $t('people.media_help') }}</p>
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

/* Foto y firma, una al lado de la otra. La foto es cuadrada porque es una
   cara; la firma es apaisada porque es un trazo. */
.media-grid { display: flex; flex-wrap: wrap; gap: 24px; }
.media-slot { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
.media-slot__label { font-size: 0.8125rem; color: var(--color-text-muted); }
.media-slot__foto {
    width: 140px; height: 140px; object-fit: cover;
    border: 1px solid var(--ant-color-border, #d9d9d9); border-radius: 8px;
}
.media-slot__firma {
    width: 220px; height: 90px; object-fit: contain; padding: 6px;
    background: #fff;
    border: 1px solid var(--ant-color-border, #d9d9d9); border-radius: 8px;
}
.media-slot__vacio {
    display: flex; align-items: center; justify-content: center;
    width: 140px; height: 140px; padding: 8px; text-align: center;
    font-size: 0.8125rem; color: var(--color-text-muted);
    border: 1px dashed var(--ant-color-border, #d9d9d9); border-radius: 8px;
}
.media-nota { margin: 16px 0 0; font-size: 0.8125rem; color: var(--color-text-muted); }

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
