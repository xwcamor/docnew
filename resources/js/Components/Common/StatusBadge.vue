<script setup>
import { computed } from 'vue';
import { Tag } from 'ant-design-vue';

const props = defineProps({
    params: Object,
});

const isActive = computed(() => Boolean(props.params?.value));
</script>

<template>
    <Tag :color="isActive ? 'success' : 'error'" :bordered="false">
        {{ isActive ? 'Activo' : 'Inactivo' }}
    </Tag>
</template>
