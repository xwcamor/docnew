<script setup>
/**
 * LazyMount — monta su slot SOLO cuando entra (o se acerca) al viewport; hasta
 * entonces muestra un esqueleto. Llena el alto del contenedor padre (que ya
 * tiene alto fijo), así no hay reflow ni cambia el layout. Sirve para diferir
 * el render de gráficos pesados: la primera pantalla aparece rápido y el resto
 * se dibuja al hacer scroll. Lo que nunca se ve, nunca se monta.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    // Monta un poco ANTES de entrar a la vista (sin parpadeo al scrollear).
    rootMargin: { type: String, default: '400px' },
});
// `visible` se emite una vez, al entrar en viewport → sirve para disparar la
// descarga diferida de recursos pesados (ej. el GeoJSON del mapa).
const emit = defineEmits(['visible']);

const visible = ref(false);
const el = ref(null);
let obs = null;

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') { visible.value = true; emit('visible'); return; }
    obs = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) {
            visible.value = true;
            emit('visible');
            obs?.disconnect();
            obs = null;
        }
    }, { rootMargin: props.rootMargin });
    if (el.value) obs.observe(el.value);
});
onBeforeUnmount(() => { obs?.disconnect(); });
</script>

<template>
    <div ref="el" class="lazym">
        <slot v-if="visible" />
        <div v-else class="lazym__skel" aria-hidden="true" />
    </div>
</template>

<style scoped>
.lazym { width: 100%; height: 100%; }
.lazym__skel {
    width: 100%; height: 100%; border-radius: 8px;
    background: linear-gradient(100deg, var(--color-surface-alt, #f1f3f5) 30%, rgba(0,0,0,0.045) 50%, var(--color-surface-alt, #f1f3f5) 70%);
    background-size: 200% 100%;
    animation: lazym-shimmer 1.3s linear infinite;
}
@keyframes lazym-shimmer { to { background-position: -200% 0; } }
</style>
