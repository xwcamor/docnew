<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from 'ant-design-vue';
import { DeleteOutlined } from '@ant-design/icons-vue';

/**
 * El lienzo donde la persona firma con el dedo.
 *
 * Se firma **una vez**: a partir de ahí la firma queda en archivo y en los
 * planes siguientes sólo se toma la foto. Es lo que hacía el sistema anterior
 * —`workers.signature` más el marcador `signed_by_IA`— y tiene dos razones:
 * nadie va a redibujar su firma cinco veces al día con guantes, y una firma
 * trazada de nuevo cada día es distinta cada día, así que no prueba nada.
 *
 * Detalles que importan en una tablet en obra:
 *
 * - **Punteros, no ratón.** `pointerdown/move/up` cubre dedo, lápiz y ratón con
 *   un solo camino. `setPointerCapture` evita que el trazo se corte al salirse
 *   del lienzo, que con el dedo pasa constantemente.
 * - **`touch-action: none`.** Sin eso, arrastrar el dedo hace scroll de la
 *   página en vez de dibujar.
 * - **Escala por `devicePixelRatio`.** En una pantalla retina un lienzo sin
 *   escalar sale borroso, y esto acaba impreso en un PDF.
 * - **Fondo transparente.** La firma se pinta sobre el papel del formato; un
 *   rectángulo blanco encima taparía el texto.
 */
const emit = defineEmits(['update:modelValue', 'change']);

const props = defineProps({
    /** Alto del lienzo en píxeles CSS. Ancho: el del contenedor. */
    height: { type: Number, default: 180 },
});

const lienzo = ref(null);
const vacio = ref(true);

let ctx = null;
let dibujando = false;

const preparar = () => {
    const el = lienzo.value;
    if (!el) return;

    const ratio = window.devicePixelRatio || 1;
    const ancho = el.clientWidth;

    el.width  = ancho * ratio;
    el.height = props.height * ratio;

    ctx = el.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#1f2329';
};

const punto = (e) => {
    const r = lienzo.value.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
};

const empezar = (e) => {
    dibujando = true;
    lienzo.value.setPointerCapture(e.pointerId);
    const { x, y } = punto(e);
    ctx.beginPath();
    ctx.moveTo(x, y);
};

const mover = (e) => {
    if (!dibujando) return;
    const { x, y } = punto(e);
    ctx.lineTo(x, y);
    ctx.stroke();

    if (vacio.value) {
        vacio.value = false;
        emit('change', false);
    }
};

const soltar = () => {
    if (!dibujando) return;
    dibujando = false;
    emit('update:modelValue', vacio.value ? null : lienzo.value.toDataURL('image/png'));
};

const limpiar = () => {
    ctx?.clearRect(0, 0, lienzo.value.width, lienzo.value.height);
    vacio.value = true;
    emit('update:modelValue', null);
    emit('change', true);
};

// Al girar la tablet cambia el ancho y el lienzo hay que rehacerlo. Se pierde
// el trazo, así que se avisa vaciándolo en vez de dejar media firma escalada.
const alRedimensionar = () => { preparar(); limpiar(); };

onMounted(() => {
    preparar();
    window.addEventListener('resize', alRedimensionar);
});

onBeforeUnmount(() => window.removeEventListener('resize', alRedimensionar));

defineExpose({ limpiar, estaVacio: () => vacio.value });
</script>

<template>
    <div class="pad">
        <canvas
            ref="lienzo"
            class="pad__lienzo"
            :style="{ height: height + 'px' }"
            @pointerdown="empezar"
            @pointermove="mover"
            @pointerup="soltar"
            @pointercancel="soltar"
        />

        <div class="pad__pie">
            <span class="pad__linea" aria-hidden="true" />
            <Button size="small" type="text" :disabled="vacio" @click="limpiar">
                <template #icon><DeleteOutlined /></template>
                {{ $t('field_work.sign.clear') }}
            </Button>
        </div>
    </div>
</template>

<style scoped>
.pad {
    border: 1px solid var(--color-border, #d9d9d9);
    border-radius: 10px;
    background: var(--color-surface, #fff);
    overflow: hidden;
}
/* `touch-action: none` o el dedo hace scroll en vez de dibujar. */
.pad__lienzo { display: block; width: 100%; touch-action: none; cursor: crosshair; }

.pad__pie {
    display: flex; align-items: center; gap: 12px;
    padding: 0 10px 8px;
}
/* La raya sobre la que se firma, como en el papel. */
.pad__linea { flex: 1 1 auto; height: 1px; background: var(--color-border, #d9d9d9); }
</style>
