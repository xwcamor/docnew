<script setup>
/**
 * BackLink — flecha "volver" con tooltip + spring hover.
 *
 * Reusable para SectionHeader o headers custom (cuando hay avatar/tabs y no
 * encaja SectionHeader). Cualquier cambio al patrón (tooltip text, efecto)
 * vive en este único archivo.
 */
import { Link } from '@inertiajs/vue3';
import { Tooltip } from 'ant-design-vue';
import { ArrowLeftOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineProps({
    href: { type: String, required: true },
});
</script>

<template>
    <Tooltip :title="t('global.back_hint')">
        <Link :href="href" class="back-link" :aria-label="t('global.back')">
            <ArrowLeftOutlined />
        </Link>
    </Tooltip>
</template>

<style scoped>
.back-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 4px;
    color: var(--color-text-muted);
    transition: background 0.18s ease,
                color 0.18s ease,
                transform 0.16s cubic-bezier(0.34, 1.56, 0.64, 1),
                box-shadow 0.18s ease;
    margin-top: 2px;
    will-change: transform;
}
.back-link:hover {
    background: var(--color-surface-hover);
    color: var(--color-primary);
}
@media (hover: hover) and (pointer: fine) {
    .back-link:hover {
        transform: translateX(-2px) scale(1.1);
        box-shadow: 0 4px 12px rgba(10, 110, 209, 0.15);
    }
    .back-link:active {
        transform: translateX(0) scale(0.95);
        transition-duration: 80ms;
    }
}
</style>
