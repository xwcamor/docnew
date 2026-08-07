<script setup>
/** Barra de acciones masivas para Transformers (seleccion multiple). Mobile: sticky al pie. */
import { Button, Space, Tooltip } from 'ant-design-vue';
import { DeleteOutlined, ShareAltOutlined, FileExcelOutlined } from '@ant-design/icons-vue';

defineProps({
    count:          { type: Number,  required: true },
    isMobile:       { type: Boolean, default: false },
    canDelete:      { type: Boolean, default: false },
    canShare:       { type: Boolean, default: false },
    canExport:      { type: Boolean, default: false },
    // Motivo por el que compartir no aplica a esta selección (mezcla clientes o
    // ninguno tiene). Vacío = se puede compartir.
    shareBlocked:   { type: String,  default: '' },
});

defineEmits(['cancel', 'delete', 'share', 'export']);
</script>

<template>
    <div
        class="bulk-bar"
        :class="{ 'bulk-bar--mobile-sticky': isMobile }"
    >
        <span class="bulk-bar__label">
            <strong>{{ count }}</strong>
            {{ count === 1 ? $t('global.selected') : $t('global.selected_plural') }}
        </span>
        <Space wrap>
            <Button size="small" @click="$emit('cancel')">{{ $t('global.cancel') }}</Button>
            <!-- Compartir la selección como flota: el filtrado ya lo hizo el
                 índice (subestación, estado, vistas guardadas…), así que no hace
                 falta repetir filtros dentro del modal. -->
            <!-- Excel de la selección: archivo de trabajo interno, así que NO
                 exige un solo cliente (a diferencia de compartir). -->
            <Button
                v-if="canExport"
                size="small"
                @click="$emit('export')"
            >
                <FileExcelOutlined /> {{ $t('transformers.bulk_excel') }}
            </Button>
            <!-- Deshabilitado, no oculto: un botón que desaparece deja al
                 usuario sin saber por qué. El tooltip dice el motivo. -->
            <Tooltip v-if="canShare" :title="shareBlocked || $t('sharing.share')">
                <Button
                    size="small"
                    :disabled="!!shareBlocked"
                    @click="$emit('share')"
                >
                    <ShareAltOutlined /> {{ $t('sharing.share') }}
                </Button>
            </Tooltip>
            <Button
                v-if="canDelete"
                size="small"
                danger
                type="primary"
                @click="$emit('delete')"
            >
                <DeleteOutlined /> {{ $t('global.delete') }}
            </Button>
        </Space>
    </div>
</template>
