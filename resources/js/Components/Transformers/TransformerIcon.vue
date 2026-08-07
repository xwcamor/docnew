<script setup>
/**
 * TransformerIcon — el dibujo del transformador (TransformerGlyph) usado como
 * ÍCONO monocromo: hereda el color del contexto (currentColor), sin animación,
 * y sin el rayo interno ni las chispas. Drop-in para menús/headers donde antes
 * iba un ícono de Ant (mismo tamaño 1em).
 */
import TransformerGlyph from '@/Components/Transformers/TransformerGlyph.vue';
</script>

<template>
    <span class="anticon tr-icon"><TransformerGlyph :animated="false" color="currentColor" /></span>
</template>

<style scoped>
.tr-icon { display: inline-flex; line-height: 0; }
.tr-icon :deep(.tr-art) { width: 1em; height: 1em; }
/* Sin rayo ni chispas: es un ícono de trafo, no de electricidad. */
.tr-icon :deep(.tr-art__bolt),
.tr-icon :deep(.tr-art__arcs) { display: none; }
/* Monocromo: todo el cuerpo hereda el color del texto (currentColor). */
.tr-icon :deep(.tr-art__tank) { stroke: currentColor; fill: none; }
.tr-icon :deep(.tr-art__bushings rect),
.tr-icon :deep(.tr-art__base),
.tr-icon :deep(.tr-art__pole) { fill: currentColor; }
.tr-icon :deep(.tr-art__fins line) { stroke: currentColor; }
.tr-icon :deep(.tr-art__bushings circle) { fill: currentColor; }
</style>
