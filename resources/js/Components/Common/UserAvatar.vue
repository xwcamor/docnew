<script setup>
/**
 * UserAvatar — muestra la foto del usuario si existe; si no, la inicial
 * del nombre sobre un fondo de color derivado (mismo nombre → mismo color).
 */
import { computed } from 'vue';
import { Avatar } from 'ant-design-vue';

const props = defineProps({
    photo:     { type: String, default: null },
    name:      { type: String, default: '' },
    size:      { type: [Number, String], default: 36 },
    updatedAt: { type: [String, Number, Date], default: null },
});

const photoUrl = computed(() => {
    if (!props.photo) return null;
    // Ya es URL absoluta (http) o ruta absoluta (/storage/...): usar tal cual.
    if (props.photo.startsWith('http') || props.photo.startsWith('/')) return props.photo;
    // Filename raw: armar /storage/{filename}?v={ts} para cache-busting.
    const ts = props.updatedAt
        ? Math.floor(new Date(props.updatedAt).getTime() / 1000)
        : Math.floor(Date.now() / 1000);
    return `/storage/${props.photo}?v=${ts}`;
});

const initial = computed(() => {
    const trimmed = (props.name || '').trim();
    if (!trimmed) return '?';
    return trimmed.charAt(0).toUpperCase();
});

// Fondo derivado del nombre: misma persona → mismo color en cada render.
// Se generan colores HSL con saturacion y luminosidad fijas para mantener
// contraste con texto blanco.
const backgroundColor = computed(() => {
    const str = (props.name || '').trim() || '?';
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
        hash |= 0;
    }
    const hue = Math.abs(hash) % 360;
    return `hsl(${hue}, 55%, 45%)`;
});

const avatarStyle = computed(() => (
    photoUrl.value ? {} : { backgroundColor: backgroundColor.value, color: '#fff' }
));

const fontSize = computed(() => {
    const s = typeof props.size === 'number' ? props.size : parseInt(props.size, 10) || 36;
    return `${Math.round(s * 0.45)}px`;
});
</script>

<template>
    <Avatar :src="photoUrl" :size="size" :style="avatarStyle">
        <template v-if="!photoUrl" #default>
            <span :style="{ fontSize: fontSize, fontWeight: 600, lineHeight: 1 }">
                {{ initial }}
            </span>
        </template>
    </Avatar>
</template>
