<script setup>
/** Toggle de favorito polimorfico para una fila de la tabla de Roles. */
import { StarFilled, StarOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineProps({
    record:     { type: Object,  required: true },
    submitting: { type: [Number, String, null], default: null },
});

defineEmits(['toggle']);
</script>

<template>
    <button
        type="button"
        class="fav-btn"
        :class="{ 'fav-btn--on': record.is_favorite }"
        :title="record.is_favorite ? t('global.only_favorites') : ''"
        :disabled="submitting === record.id"
        @click.stop="$emit('toggle', record)"
    >
        <StarFilled v-if="record.is_favorite" />
        <StarOutlined v-else />
    </button>
</template>

<style scoped>
.fav-btn {
    background: transparent;
    border: 0;
    cursor: pointer;
    color: var(--color-icon-mute);
    font-size: 1.1rem;
    padding: 4px;
    line-height: 1;
    transition: color 0.12s ease, transform 0.12s ease;
}
.fav-btn:hover { transform: scale(1.15); }
.fav-btn:disabled { cursor: wait; opacity: 0.6; }

.fav-btn :deep(svg)       { fill: var(--color-icon-mute) !important; }
.fav-btn:hover :deep(svg) { fill: var(--color-warning) !important; }
.fav-btn--on :deep(svg)   { fill: var(--color-warning) !important; }
</style>
