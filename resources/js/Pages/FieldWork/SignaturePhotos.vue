<script setup>
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { CameraOutlined } from '@ant-design/icons-vue';
import { useDateFormat } from '@/Composables/useDateFormat';

/**
 * El álbum de las firmas — SOLO SUPER.
 *
 * Tres cosas por tarjeta y ni una más: el plan, el trabajador y la foto que se
 * tomó en el momento de firmar, con su hora. Los parámetros de la captura
 * —coincidencia, coordenadas, IP, aparato— no se enseñan aquí a propósito:
 * regla del dueño del producto, «no deben exhibirse esos parámetros ni en
 * logs». Y mirar esta pantalla no deja rastro en ningún historial: es lectura
 * pura.
 *
 * La ruta la cierra `role:super` y el controlador lo repite; a nadie más le
 * aparece siquiera la entrada del menú.
 */
defineProps({ events: Object });
defineOptions({ layout: AppLayout });

const { formatDateTime } = useDateFormat();

function irA(pagina) {
    router.get(route('field_work.signatures.photos'), { page: pagina }, { preserveScroll: false });
}
</script>

<template>
    <div class="mi-console">
        <SectionHeader
            :title="$t('field_work.photos.title')"
            :subtitle="$t('field_work.photos.subtitle')">
            <template #icon><CameraOutlined /></template>
        </SectionHeader>

        <a-empty v-if="!events.data.length" :description="$t('field_work.photos.empty')" />

        <div v-else class="album">
            <a-card v-for="e in events.data" :key="e.id" size="small" class="album__tarjeta">
                <img v-if="e.photo_url" :src="e.photo_url" class="album__foto" alt="" loading="lazy" />
                <!-- La fila consta pero el archivo ya no está en el disco: se
                     dice, no se deja un recuadro roto. -->
                <div v-else class="album__hueco">{{ $t('field_work.photos.no_photo') }}</div>

                <div class="album__datos">
                    <strong class="album__nombre">{{ e.person }}</strong>
                    <Link v-if="e.plan" :href="e.plan.url" class="album__plan">{{ e.plan.code }}</Link>
                    <span class="album__hora">{{ formatDateTime(e.taken_at) }}</span>
                </div>
            </a-card>
        </div>

        <a-pagination
            v-if="events.total > events.per_page"
            class="album__paginas"
            :current="events.current_page"
            :total="events.total"
            :page-size="events.per_page"
            :show-size-changer="false"
            @change="irA"
        />
    </div>
</template>

<style scoped>
.album {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}

.album__foto,
.album__hueco {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 8px;
    display: block;
}

/* El hueco de la foto perdida, con su frase centrada. */
.album__hueco {
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 12px;
    background: var(--color-bg-muted, rgba(0, 0, 0, 0.04));
    color: var(--color-text-muted);
    font-size: 12px;
}

.album__datos {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-top: 8px;
}

.album__nombre { line-height: 1.3; }
.album__plan   { font-size: 12px; }
.album__hora   { font-size: 12px; color: var(--color-text-muted); font-variant-numeric: tabular-nums; }

.album__paginas { margin-top: 16px; text-align: center; }
</style>
