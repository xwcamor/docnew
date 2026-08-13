<script setup>
import { computed, ref } from 'vue';
import { Button, Modal, Tooltip, TypographyParagraph } from 'ant-design-vue';
import { CameraOutlined, EnvironmentOutlined } from '@ant-design/icons-vue';
import { useDateFormat } from '@/Composables/useDateFormat';
import { resumirAgente, acortarAparato } from '@/Support/agente';
import SignatureMark from '@/Components/WorkPlans/SignatureMark.vue';

/**
 * La cara con la que se firmó, y el rastro entero de esa firma.
 *
 * Esto empezó siendo una tarjetita flotante y se quedó pequeño en cuanto la
 * firma pasó a guardar de dónde y con qué se hizo: la cara, el método, la
 * hora, la coincidencia, la IP, el aparato, el navegador y las coordenadas no
 * caben en 260 píxeles sin que el navegador —que es un párrafo entero— eche
 * fuera todo lo demás. Ahora es una ventana, con el sitio que hace falta.
 *
 * Dos cosas que se aprendieron por el camino y que gobiernan cómo se pinta:
 *
 *  - **Lo largo se resume y se puede desplegar.** El user-agent en crudo y el
 *    UUID del aparato son ilegibles y a la vez son lo único que vale si un día
 *    hay que discutir una firma. Se enseña «Chrome 126 · Android 13» y detrás,
 *    a un clic, la cadena tal cual.
 *  - **Las coordenadas se ven en un mapa.** «-12.046374, -77.042793» no le
 *    dice nada a nadie; el punto en el plano contesta la pregunta de verdad,
 *    que es si eso está en la subestación o a treinta kilómetros.
 *
 * Todo va con `people.view_private_info`, el mismo permiso que destapa el
 * documento: el servidor manda `face_url` y `audit` en nulo a quien no lo
 * tenga, así que a un perfil de campo este botón ni le aparece.
 */
const props = defineProps({
    faceUrl:   { type: String, default: null },
    signature: { type: Object, default: null },
    name:      { type: String, default: '' },
});

const { formatDateTime } = useDateFormat();

const abierto = ref(false);
const auditoria = computed(() => props.signature?.audit ?? null);

/**
 * Las filas del rastro, ya resueltas: sólo se pintan las que tienen algo.
 *
 * `largo` marca las que traen debajo su versión completa desplegable. Sin eso
 * el resumen sería una pérdida de información, y de un rastro de auditoría no
 * se puede perder nada.
 */
const filas = computed(() => {
    const a = auditoria.value;

    if (!a) return [];

    return [
        { clave: 'signed_at', valor: a.signed_at ? formatDateTime(a.signed_at) : null },
        {
            clave: 'match',
            valor: a.match_percent !== null && a.match_percent !== undefined
                ? `${a.match_percent}%` : null,
        },
        { clave: 'ip', valor: a.ip },
        { clave: 'device', valor: acortarAparato(a.device_id), largo: a.device_id },
        // Si no se reconoce el navegador se enseña la cadena entera: más vale
        // un párrafo ilegible que una etiqueta que no dice nada.
        { clave: 'browser', valor: resumirAgente(a.user_agent) ?? a.user_agent, largo: a.user_agent },
        { clave: 'reason', valor: a.override_reason },
    ].filter((fila) => !!fila.valor);
});

/** Se despliega sólo lo que no aporta nada de más: el resumen ya lo dice todo. */
const ampliable = (fila) => !!fila.largo && fila.largo !== fila.valor;


// ── El mapa ──────────────────────────────────────────────────────────────────

const punto = computed(() => {
    const a = auditoria.value;

    return a?.latitude !== null && a?.latitude !== undefined
        && a?.longitude !== null && a?.longitude !== undefined
        ? { lat: a.latitude, lon: a.longitude }
        : null;
});

const coordenadas = computed(
    () => punto.value ? `${punto.value.lat}, ${punto.value.lon}` : null,
);

/**
 * El recuadro del mapa.
 *
 * OpenStreetMap incrustado: sin librería, sin clave y sin cuenta de nadie. El
 * `bbox` es un cuadrado pequeño alrededor del punto —unos 300 metros— y el
 * `marker` es la chincheta.
 *
 * **Sale a internet.** Es la contrapartida honesta de tener mapa: el navegador
 * de quien abre esta ficha le pide las baldosas a openstreetmap.org, y en esa
 * petición van las coordenadas de la firma. Por eso el iframe se monta sólo
 * cuando la ventana está abierta (`v-if`), y no al cargar el plan: sin abrir
 * la ficha de una firma no sale nada hacia fuera. Y si no hay internet —obra
 * cerrada, red del cliente— el recuadro queda en blanco y las coordenadas
 * siguen escritas debajo, que es lo que se copia y se pega en un informe.
 */
const mapaUrl = computed(() => {
    if (!punto.value) return null;

    const { lat, lon } = punto.value;
    const d = 0.0015;
    const bbox = [lon - d, lat - d, lon + d, lat + d].join(',');

    return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat},${lon}`;
});

/** Para abrirlo en grande, que es lo que se hace cuando algo no cuadra. */
const mapaEnlace = computed(() => punto.value
    ? `https://www.openstreetmap.org/?mlat=${punto.value.lat}&mlon=${punto.value.lon}#map=17/${punto.value.lat}/${punto.value.lon}`
    : null);
</script>

<template>
    <template v-if="faceUrl">
        <Tooltip :title="$t('work_plans.sign_audit_open')">
            <Button
                size="small"
                type="text"
                :aria-label="$t('work_plans.sign_audit_open')"
                @click="abierto = true"
            >
                <template #icon><CameraOutlined /></template>
            </Button>
        </Tooltip>

        <Modal
            v-model:open="abierto"
            :title="name || $t('work_plans.sign_audit_open')"
            :footer="null"
            :width="680"
            centered
            destroy-on-close
        >
            <div class="firmante">
                <div class="firmante__cara-col">
                    <img :src="faceUrl" class="firmante__cara" alt="">
                    <SignatureMark :signature="signature" :name="name" />
                </div>

                <div class="firmante__datos">
                    <dl v-if="filas.length" class="firmante__rastro">
                        <template v-for="fila in filas" :key="fila.clave">
                            <dt>{{ $t(`work_plans.sign_audit_${fila.clave}`) }}</dt>
                            <dd>
                                <span>{{ fila.valor }}</span>

                                <!-- El dato en crudo, a un clic. Ant lo pinta
                                     como «Ampliar»/«Contraer», que es
                                     exactamente lo que hace. -->
                                <TypographyParagraph
                                    v-if="ampliable(fila)"
                                    class="firmante__crudo"
                                    :ellipsis="{
                                        rows: 1,
                                        expandable: true,
                                        symbol: $t('work_plans.sign_audit_full'),
                                    }"
                                    :content="fila.largo"
                                />
                            </dd>
                        </template>
                    </dl>

                    <div v-if="punto" class="firmante__mapa">
                        <!-- `span` y no `dt`: fuera de una `dl` un `dt` es HTML
                             inválido, y el lector de pantalla lo anuncia como
                             término de una lista de definiciones que no hay. -->
                        <span class="firmante__mapa-titulo">
                            {{ $t('work_plans.sign_audit_coords') }}
                        </span>

                        <iframe
                            v-if="mapaUrl"
                            :src="mapaUrl"
                            class="firmante__plano"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                            :title="$t('work_plans.sign_audit_coords')"
                        />

                        <p class="firmante__coords">
                            <EnvironmentOutlined aria-hidden="true" />
                            {{ coordenadas }}
                            <a :href="mapaEnlace" target="_blank" rel="noopener noreferrer">
                                {{ $t('work_plans.sign_audit_map_open') }}
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </Modal>
    </template>
</template>

<style scoped>
/* Dos columnas: la cara manda a la izquierda y el rastro se lee a la derecha.
   En pantalla estrecha se apilan — esta ficha se abre desde una tablet. */
.firmante {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 20px;
    align-items: start;
}

.firmante__cara-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.firmante__cara {
    display: block;
    width: 220px;
    height: 220px;
    object-fit: cover;
    border-radius: 8px;
}

.firmante__datos { min-width: 0; }

/* Etiqueta y valor en dos columnas: aquí sí caben, porque lo que se desbordaba
   —el navegador y el UUID— ya viene resumido. */
.firmante__rastro {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 6px 14px;
    margin: 0;
    font-size: 0.8125rem;
}

.firmante__rastro dt {
    color: var(--color-text-muted);
    white-space: nowrap;
}

.firmante__rastro dd {
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
}

/* El crudo desplegable, un punto más apagado que el resumen que hay encima. */
.firmante__crudo {
    margin: 2px 0 0 !important;
    color: var(--color-text-dim);
    font-size: 0.75rem;
}

.firmante__mapa { margin-top: 16px; }

.firmante__mapa-titulo {
    display: block;
    margin-bottom: 6px;
    color: var(--color-text-muted);
    font-size: 0.8125rem;
}

.firmante__plano {
    display: block;
    width: 100%;
    height: 200px;
    border: 1px solid var(--color-border);
    border-radius: 8px;
}

.firmante__coords {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 6px 0 0;
    color: var(--color-text-muted);
    font-size: 0.75rem;
}

@media (max-width: 640px) {
    .firmante { grid-template-columns: 1fr; }
}
</style>
