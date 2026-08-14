<script setup>
import { reactive } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { CameraOutlined } from '@ant-design/icons-vue';
import { useDateFormat } from '@/Composables/useDateFormat';

/**
 * El álbum de las firmas — SOLO SUPER.
 *
 * Por tarjeta: el plan, el trabajador y la foto que se tomó en el momento de
 * firmar, con su hora — más la marca «Sin reconocimiento» cuando la cara no se
 * llegó a verificar, que es justo lo que el álbum vino a enseñar. Los
 * parámetros de la captura —coincidencia, coordenadas, IP, aparato— no se
 * enseñan aquí a propósito: regla del dueño del producto, «no deben exhibirse
 * esos parámetros ni en logs». Y mirar esta pantalla no deja rastro en ningún
 * historial: es lectura pura.
 *
 * Esto es un observatorio, no una cola: nadie está obligado a resolver nada.
 * Lo único que se puede HACER es anular una firma que se sabe falsa — botón
 * discreto a propósito, porque es la decisión excepcional y no el uso normal
 * de la pantalla.
 *
 * La ruta la cierra `role:super` y el controlador lo repite; a nadie más le
 * aparece siquiera la entrada del menú.
 */
defineProps({ events: Object });
defineOptions({ layout: AppLayout });

const { formatDateTime } = useDateFormat();

// El motivo de cada anulación, por tarjeta: el Popconfirm de una no puede
// compartir texto con el de otra.
const motivos = reactive({});

function irA(pagina) {
    router.get(route('field_work.signatures.photos'), { page: pagina }, { preserveScroll: false });
}

function anular(evento) {
    router.post(
        route('field_work.signatures.resolve', evento.id),
        { accepted: false, reason: motivos[evento.id] || null },
        { preserveScroll: true, onSuccess: () => { delete motivos[evento.id]; } },
    );
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

                    <!-- La cara no se llegó a verificar: palabra y color, nunca
                         el color solo (docs/UI.md §5). Es un hecho, no una
                         tarea: aquí no hay nada que resolver. -->
                    <a-tag
                        v-if="e.method !== 'face_recognition'"
                        color="warning"
                        :bordered="false"
                        class="album__marca">
                        {{ $t('field_work.photos.unrecognized') }}
                    </a-tag>
                </div>

                <!-- Anular: la única acción del álbum, y la excepcional. El
                     Popconfirm dice la consecuencia entera antes del toque
                     (docs/UI.md §6: un botón que no cuenta lo que hace falla
                     peor que ninguno) y deja escribir el motivo, opcional. -->
                <a-popconfirm
                    v-if="e.can_void"
                    :title="$t('field_work.photos.void_confirm')"
                    :ok-text="$t('field_work.photos.void')"
                    ok-type="danger"
                    @confirm="anular(e)">
                    <template #description>
                        <a-textarea
                            v-model:value="motivos[e.id]"
                            :placeholder="$t('field_work.photos.void_reason_placeholder')"
                            :rows="2"
                            :maxlength="255"
                            class="album__motivo"
                        />
                    </template>
                    <a-button type="text" size="small" danger class="album__anular">
                        {{ $t('field_work.photos.void') }}
                    </a-button>
                </a-popconfirm>
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

/* La marca no estira la columna de datos: es una etiqueta, no un botón. */
.album__marca  { margin-top: 4px; align-self: flex-start; }

/* Discreto a propósito: la anulación es la excepción, no el uso normal. */
.album__anular { margin-top: 4px; padding: 0 4px; font-size: 12px; }

.album__motivo { margin-top: 8px; min-width: 220px; }

.album__paginas { margin-top: 16px; text-align: center; }
</style>
