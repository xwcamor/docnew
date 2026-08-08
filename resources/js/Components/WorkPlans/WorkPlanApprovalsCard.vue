<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Popconfirm, Tooltip, Switch,
} from 'ant-design-vue';
import {
    SafetyCertificateOutlined, DeleteOutlined, PlusOutlined, NodeIndexOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { useAuth } from '@/Composables/useAuth';

/**
 * Aprobaciones del plan: quién tiene que firmarlo antes de que se ejecute.
 *
 * Los roles salen de `approval_rules` —trabajador, supervisor, supervisor HSE—
 * y al crear el plan quedan propuestos sin persona. Aquí se le pone nombre a
 * cada uno, se marca si la firma es obligatoria (una obligatoria sin levantar
 * impide dar el plan por terminado) y se puede añadir o quitar un aprobador.
 *
 * Una aprobación ya firmada no se quita: la firma ES la aprobación.
 */
const props = defineProps({
    planSlug:  { type: String, required: true },
    approvals: { type: Array,  default: () => [] },
    rules:     { type: Array,  default: () => [] },
    canEdit:   { type: Boolean, default: false },
});

const { t } = useI18n();
const { can } = useAuth();

// El flujo (qué firmas exige un plan, quién puede darlas) se configura en otros
// dos módulos y nadie los encontraba. El enlace vive aquí, que es donde surge
// la pregunta, y no solo en el menú lateral.
const puedeConfigurarReglas = computed(() => can('approval_rules.view'));
const puedeVerRoles         = computed(() => can('approver_roles.view'));

const firmadas = computed(() => props.approvals.filter((a) => a.signed).length);

const regla = ref(undefined);
const persona = ref(undefined);
const obligatoria = ref(true);
const candidatos = ref([]);
const buscando = ref(false);
const guardando = ref(false);
const quitando = ref(null);

// Nombre legible del rol. Si llegara un rol que el catálogo no conoce se
// muestra su código: mejor un texto raro que una fila en blanco.
const rotulo = (rol) => (rol ? t('work_plans.approver_role.' + rol) : '—');

let temporizador = null;
const buscar = (texto) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get(
                route('business_management.work_plans.crew.candidates', props.planSlug),
                { params: { q: texto } },
            );
            candidatos.value = data.people;
        } finally {
            buscando.value = false;
        }
    }, 250);
};

const anadir = () => {
    if (!regla.value) return;
    guardando.value = true;
    router.post(
        route('business_management.work_plans.approvals.store', props.planSlug),
        { approval_rule_id: regla.value, person_slug: persona.value ?? null, is_required: obligatoria.value },
        {
            preserveScroll: true,
            onFinish: () => {
                guardando.value = false;
                regla.value = undefined;
                persona.value = undefined;
                candidatos.value = [];
            },
        },
    );
};

const quitar = (a) => {
    quitando.value = a.slug;
    router.delete(
        route('business_management.work_plans.approvals.destroy', [props.planSlug, a.slug]),
        { preserveScroll: true, onFinish: () => { quitando.value = null; } },
    );
};
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card">
        <template #title>
            <SafetyCertificateOutlined /> {{ $t('work_plans.approvals_title') }} ({{ approvals.length }})
        </template>
        <template v-if="approvals.length" #extra>
            <span class="ff-count" :class="{ 'is-done': firmadas === approvals.length }">
                {{ $tc('work_plans.approvals_summary', firmadas, { done: firmadas, total: approvals.length }) }}
            </span>
        </template>

        <p v-if="canEdit" class="ff-cardhint">{{ $t('work_plans.approvals_subtitle') }}</p>

        <p v-if="!approvals.length" class="ff-empty">{{ $t('work_plans.approvals_empty') }}</p>

        <ul v-else class="ff-items">
            <li v-for="a in approvals" :key="a.slug" class="ff-item">
                <div class="ff-item__main ff-item__name">
                    <strong>{{ rotulo(a.role) }}</strong>
                    <span class="ff-item__sub">
                        {{ a.person ? a.person.name + ' · ' + (a.person.num_doc || '—') : $t('work_plans.approval_unassigned') }}
                    </span>
                </div>

                <div class="ff-item__meta">
                    <Tag :color="a.required ? 'red' : 'default'" :bordered="false">
                        {{ a.required ? $t('work_plans.approval_required') : $t('work_plans.approval_optional') }}
                    </Tag>
                    <Tag :color="a.signed ? 'success' : 'warning'" :bordered="false">
                        {{ a.signed ? $t('work_plans.approval_approved') : $t('work_plans.approval_pending') }}
                    </Tag>
                </div>

                <div v-if="canEdit" class="ff-item__acts">
                    <Tooltip v-if="a.signed" :title="$t('work_plans.approval_signed_cannot_remove')">
                        <Button size="small" type="text" disabled><DeleteOutlined /></Button>
                    </Tooltip>
                    <Popconfirm
                        v-else
                        :title="$t('work_plans.approvals_remove_confirm')"
                        :ok-text="$t('work_plans.approvals_remove')"
                        :cancel-text="$t('global.cancel')"
                        placement="topRight"
                        @confirm="quitar(a)"
                    >
                        <Button size="small" type="text" danger :loading="quitando === a.slug">
                            <DeleteOutlined />
                        </Button>
                    </Popconfirm>
                </div>
            </li>
        </ul>

        <div v-if="canEdit" class="ff-addrow">
            <Select
                v-model:value="regla"
                class="ff-addrow__grow"
                allow-clear
                :disabled="!rules.length"
                :placeholder="rules.length ? $t('work_plans.approval_role') : $t('work_plans.approval_rules_empty')"
            >
                <SelectOption v-for="r in rules" :key="r.id" :value="r.id">{{ rotulo(r.role) }}</SelectOption>
            </Select>
            <Select
                v-model:value="persona"
                class="ff-addrow__grow"
                show-search
                allow-clear
                :filter-option="false"
                :loading="buscando"
                :placeholder="$t('work_plans.approval_person')"
                :not-found-content="buscando ? undefined : $t('work_plans.crew_no_results')"
                @search="buscar"
                @focus="buscar('')"
            >
                <SelectOption v-for="p in candidatos" :key="p.slug" :value="p.slug">
                    {{ p.name }} — {{ p.num_doc }}
                </SelectOption>
            </Select>
            <Switch
                v-model:checked="obligatoria"
                :checked-children="$t('work_plans.approval_required')"
                :un-checked-children="$t('work_plans.approval_optional')"
            />
            <Button type="primary" :disabled="!regla" :loading="guardando" @click="anadir">
                <template #icon><PlusOutlined /></template>
                {{ $t('work_plans.approvals_add') }}
            </Button>
        </div>

        <!-- Adónde ir cuando la respuesta no está en este plan sino en el flujo:
             la lista de roles propuestos sale de ahí, no de esta pantalla. -->
        <div v-if="puedeConfigurarReglas || puedeVerRoles" class="wp-flowlink">
            <p class="ff-cardhint">{{ $t('work_plans.approvals_configure_hint') }}</p>
            <div class="ff-addrow">
                <Link v-if="puedeConfigurarReglas" :href="route('business_management.approval_rules.index')">
                    <Button>
                        <template #icon><NodeIndexOutlined /></template>
                        {{ $t('work_plans.approvals_configure') }}
                    </Button>
                </Link>
                <Link v-if="puedeVerRoles" :href="route('business_management.approver_roles.index')">
                    <Button>
                        <template #icon><SafetyCertificateOutlined /></template>
                        {{ $t('work_plans.approvals_roles_link') }}
                    </Button>
                </Link>
            </div>
        </div>
    </Card>
</template>

<style scoped>
.wp-flowlink {
    margin-top: 16px; padding-top: 14px;
    border-top: 1px solid var(--color-border-soft, #f0f0f0);
}
.wp-flowlink .ff-cardhint { margin-bottom: 8px; }
/* Objetivo de toque de tablet, igual que el resto de la ficha. */
.wp-flowlink :deep(.ant-btn) { min-height: 44px; }
</style>
