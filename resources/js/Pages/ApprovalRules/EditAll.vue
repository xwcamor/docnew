<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button, Card, Pagination, Alert } from 'ant-design-vue';
import { NodeIndexOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EditAllFooter from '@/Components/Common/EditAllFooter.vue';
import ApprovalRulesEditAllTable from '@/Components/ApprovalRules/ApprovalRulesEditAllTable.vue';

import { useEditAllDraft } from '@/Composables/useEditAllDraft';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

/**
 * Edición en masa de los niveles y de qué firmas son obligatorias — que es lo
 * que de verdad se retoca cuando se reordena un flujo.
 *
 * El país, el tipo y el rol no se tocan aquí: cambiarlos no es editar la regla,
 * es cambiarla por otra, y eso va por el formulario con sus validaciones.
 */
const props = defineProps({
    approval_rules: { type: Object, required: true },
    filters:   { type: Object, required: true },
    isSuper:   { type: Boolean, default: false },
});

const source = computed(() => props.approval_rules.data);
const { draft, isDirty, dirtyCount, dirtyChanges, discardAll } = useEditAllDraft({
    source,
    editableFields: ['priority_level', 'is_required', 'is_active'],
    // `uniqueField` compara un campo de texto fila contra fila, y aquí no hay
    // ninguno: lo que no se puede repetir es el NIVEL, y solo dentro del mismo
    // flujo. Se calcula abajo. Antes se pasaba `null`, con lo que
    // `duplicateRows` salía siempre vacío y el aviso de niveles repetidos —que
    // la pantalla promete— no llegaba a mostrarse nunca.
    uniqueField:    null,
});

/**
 * Dos firmas en el mismo nivel DENTRO DEL MISMO FLUJO (mismo país y mismo tipo
 * de trabajo): el orden entre ellas queda al azar. Repetir el nivel en flujos
 * distintos es lo normal y no se avisa.
 *
 * Las inactivas no cuentan: no se exigen en ningún plan nuevo.
 */
const duplicateRows = computed(() => {
    const vistos = new Map();
    const dupes  = new Set();
    draft.value.forEach((r, i) => {
        if (!r.is_active) return;
        const clave = `${r.country_id ?? '-'}|${r.work_type_id ?? '*'}|${r.priority_level ?? '-'}`;
        if (vistos.has(clave)) {
            dupes.add(i);
            dupes.add(vistos.get(clave));
        } else {
            vistos.set(clave, i);
        }
    });
    return dupes;
});

const submitting = ref(false);
const saveAll = () => {
    // Dos firmas en el mismo nivel es un AVISO, no un error: el sistema lo
    // acepta y solo deja el orden entre ellas al azar. Bloquear el guardado
    // habría dejado la pantalla muerta con datos que ya venían así.
    if (dirtyCount.value === 0) return;
    submitting.value = true;
    router.post(
        route('business_management.approval_rules.edit_all.update'),
        { changes: dirtyChanges.value },
        {
            preserveScroll: true,
            onFinish: () => { submitting.value = false; },
        },
    );
};

const onPageChange = (page, pageSize) => {
    router.get(
        route('business_management.approval_rules.edit_all'),
        { ...props.filters, page, per_page: pageSize },
        { preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <Head :title="$t('approval_rules.edit_all_title')" />

    <div class="edit-all sap-form">
        <SectionHeader
            :back-href="route('business_management.approval_rules.index')"
            :title="$t('global.edit_all') + ' — ' + $t('approval_rules.plural')"
            :subtitle="$t('approval_rules.edit_all_subtitle')"
        >
            <template #icon><NodeIndexOutlined /></template>
        </SectionHeader>

        <Alert
            v-if="duplicateRows.size > 0"
            type="warning"
            show-icon
            :message="$t('approval_rules.edit_all_duplicate_levels')"
            class="status-bar"
        />

        <Card :bodyStyle="{ padding: 0 }" class="edit-table-card">
            <ApprovalRulesEditAllTable
                v-model:draft="draft"
                :is-dirty="isDirty"
                :duplicate-rows="duplicateRows"
                :is-super="isSuper"
            />

        <div v-if="approval_rules.total > approval_rules.per_page" class="edit-pagination">
            <Pagination
                :current="approval_rules.current_page"
                :pageSize="approval_rules.per_page"
                :total="approval_rules.total"
                :pageSizeOptions="['10', '25', '50', '100']"
                show-size-changer
                @change="onPageChange"
                @show-size-change="onPageChange"
            />
        </div>
        </Card>
        <EditAllFooter
            :discard-label="$t('approval_rules.edit_all_discard')"
            :save-label="$t('approval_rules.edit_all_save_all')"
            :discard-disabled="dirtyCount === 0"
            :save-disabled="dirtyCount === 0"
            :submitting="submitting"
            :status-text="dirtyCount > 0 ? $tc('approval_rules.edit_all_changes', dirtyCount) : ''"
            @discard="discardAll"
            @save="saveAll"
        />

    </div>
</template>

<style scoped>
.status-bar { margin-bottom: 12px; }
/* La tabla queda como card BLANCA sobre el fondo gris de .sap-form. */
.sap-form .edit-table-card {
    background: var(--color-surface, #fff) !important;
    border: 1px solid var(--color-border, #e8eaed) !important;
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 4px 12px rgba(16,24,40,0.04);
}

.pagination {
    display: flex;
    justify-content: center;
    margin-top: 16px;
}
</style>
