import * as faceapi from '@vladmandic/face-api';

/**
 * Los dos gestos del reto de vida. Se elige uno al azar en cada firma, para que
 * no se pueda preparar la respuesta de antemano.
 */
const GESTOS = ['girar', 'asentir'];

/** Cuanto hay que moverse para que cuente como gesto. */
const UMBRAL_GESTO = { girar: 0.28, asentir: 0.20 };

/** Y cuanto hay que volver para que cuente como vuelta al centro. */
const UMBRAL_CENTRO = { girar: 0.12, asentir: 0.09 };

/** Centro de una nube de puntos. */
function centro(puntos) {
    const n = puntos.length;

    return {
        x: puntos.reduce((s, p) => s + p.x, 0) / n,
        y: puntos.reduce((s, p) => s + p.y, 0) / n,
    };
}

/**
 * Mide el gesto a partir de los 68 puntos faciales, en unidades de "anchos
 * entre ojos" para que no dependa de lo cerca que esté la persona de la cámara.
 *
 *   girar   → cuánto se desplaza la punta de la nariz respecto al punto medio
 *             de los ojos. Al girar la cabeza, la nariz se va hacia un lado.
 *   asentir → dónde cae la nariz entre la línea de los ojos y la de la boca.
 *             Al asentir, esa proporción se mueve.
 *
 * El signo importa poco: lo que se comprueba es la magnitud del cambio, así que
 * da igual que la vista previa esté espejada o no. Esto evita el error clásico
 * de pedir "gira a la derecha" y que el usuario vea lo contrario en pantalla.
 */
function medir(landmarks, gesto) {
    const ojoIzq = centro(landmarks.getLeftEye());
    const ojoDer = centro(landmarks.getRightEye());
    const nariz = landmarks.getNose()[6] ?? centro(landmarks.getNose());

    const entreOjos = Math.hypot(ojoDer.x - ojoIzq.x, ojoDer.y - ojoIzq.y) || 1;
    const medioOjos = { x: (ojoIzq.x + ojoDer.x) / 2, y: (ojoIzq.y + ojoDer.y) / 2 };

    if (gesto === 'girar') {
        return (nariz.x - medioOjos.x) / entreOjos;
    }

    const boca = centro(landmarks.getMouth());
    const alto = (boca.y - medioOjos.y) || 1;

    // 0,5 es la nariz a media altura entre ojos y boca: la posición neutra.
    return (nariz.y - medioOjos.y) / alto - 0.5;
}

/**
 * Verificacion facial 1:1 en el navegador, portada de tenkofiz.
 *
 * Ojo con el reparto de responsabilidades: aqui solo se mide y se da feedback.
 * Quien decide si la firma vale es el servidor, que recibe el descriptor y
 * vuelve a calcular la distancia. Por eso el umbral que se usa abajo es solo
 * para el semaforo de la pantalla.
 *
 * Dos relojes independientes, como en el kiosco de asistencia:
 *   - sinCaraMs: no hay nadie delante  -> se cancela
 *   - sinMatchMs: hay cara pero no coincide -> se captura igual y se firma
 */
export function useFaceVerify(opciones = {}) {
    const SIN_CARA_MS = (opciones.sinCaraSegundos ?? 15) * 1000;
    const SIN_MATCH_MS = (opciones.sinMatchSegundos ?? 20) * 1000;
    const EVIDENCIA_MS = (opciones.evidenciaSegundos ?? 8) * 1000;
    const RETO_MS = (opciones.retoSegundos ?? 10) * 1000;
    const RETO_ACTIVO = opciones.retoDeVida ?? false;

    let modelosCargados = false;

    async function cargarModelos() {
        if (modelosCargados) return;
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri('/models'),
            faceapi.nets.faceLandmark68Net.loadFromUri('/models'),
            faceapi.nets.faceRecognitionNet.loadFromUri('/models'),
        ]);
        modelosCargados = true;
    }

    async function abrirCamara(video) {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
        });
        video.srcObject = stream;
        await video.play();
        return stream;
    }

    function cerrarCamara(stream) {
        stream?.getTracks().forEach((t) => t.stop());
    }

    function capturar(video) {
        const lienzo = document.createElement('canvas');
        lienzo.width = video.videoWidth;
        lienzo.height = video.videoHeight;
        lienzo.getContext('2d').drawImage(video, 0, 0);
        return lienzo.toDataURL('image/jpeg', 0.85);
    }

    async function detectar(video) {
        return faceapi
            .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks()
            .withFaceDescriptor();
    }

    /**
     * Corre el ciclo completo y devuelve que hacer.
     *
     * @returns {{estado: 'reconocida'|'captura'|'cancelada', descriptor?: number[], foto?: string, distancia?: number}}
     */
    async function verificar(video, referencias, umbral, alCambiar = () => {}) {
        const inicio = Date.now();
        let primeraCara = null;

        while (true) {
            const deteccion = await detectar(video);

            if (!deteccion) {
                alCambiar({ fase: 'buscando' });
                // Nadie delante de la camara durante demasiado tiempo.
                if (!primeraCara && Date.now() - inicio > SIN_CARA_MS) {
                    return { estado: 'cancelada' };
                }
            } else {
                primeraCara = primeraCara ?? Date.now();
                const descriptor = Array.from(deteccion.descriptor);
                const distancia = Math.min(
                    ...referencias.map((r) => faceapi.euclideanDistance(r, descriptor)),
                );

                alCambiar({ fase: 'comparando', distancia });

                if (distancia <= umbral) {
                    // Coincide la cara. Si el workspace pide reto de vida, hay
                    // que superarlo antes de dar la firma por verificada.
                    if (!RETO_ACTIVO) {
                        return { estado: 'reconocida', descriptor, distancia, foto: capturar(video) };
                    }

                    const reto = await retoDeVida(video, alCambiar);

                    if (reto.superado) {
                        return { estado: 'reconocida', descriptor, distancia, foto: capturar(video), reto: reto.gesto };
                    }

                    // No lo superó. No se bloquea el trabajo —esa regla no
                    // cambia—, pero la firma sale por la vía de evidencia y cae
                    // en la bandeja de revisión del supervisor.
                    const foto = await capturarEvidencia(video, alCambiar);

                    return foto
                        ? { estado: 'captura', descriptor, distancia, foto, reto: reto.gesto, retoFallido: true }
                        : { estado: 'cancelada' };
                }

                // Hay cara pero no coincide: se le da su tiempo antes de rendirse.
                if (Date.now() - primeraCara > SIN_MATCH_MS) {
                    const foto = await capturarEvidencia(video, alCambiar);
                    return foto
                        ? { estado: 'captura', descriptor, distancia, foto }
                        : { estado: 'cancelada' };
                }
            }

            await new Promise((r) => setTimeout(r, 250));
        }
    }

    /**
     * Ultima ventana: se acepta cualquier cara para dejar constancia de quien
     * estuvo delante. Sin cara no se guarda nada, esa regla no se toca.
     */
    async function capturarEvidencia(video, alCambiar) {
        const hasta = Date.now() + EVIDENCIA_MS;

        while (Date.now() < hasta) {
            alCambiar({ fase: 'evidencia' });
            const deteccion = await detectar(video);
            if (deteccion) return capturar(video);
            await new Promise((r) => setTimeout(r, 250));
        }

        return null;
    }

    /**
     * Reto de vida.
     *
     * Se pide un gesto al azar y se comprueba que ocurre de verdad. Lo que se
     * mide no es la postura sino el **cambio**: hay que salir del centro y
     * volver. Una foto impresa o una pantalla quieta no lo consiguen, porque no
     * se mueven; el gesto completo es lo que no pueden fingir.
     *
     * Honestamente: esto para una foto o una pantalla estática. Un vídeo de la
     * persona haciendo el gesto correcto lo pasaría. Es una barrera contra el
     * atajo fácil —la foto del DNI, el compañero enseñando el móvil—, no contra
     * un ataque preparado. La barrera de verdad contra eso es que la evidencia
     * queda guardada y un supervisor la mira.
     */
    async function retoDeVida(video, alCambiar = () => {}) {
        const gesto = GESTOS[Math.floor(Math.random() * GESTOS.length)];
        const hasta = Date.now() + RETO_MS;

        let salioDelCentro = false;

        while (Date.now() < hasta) {
            const deteccion = await detectar(video);

            if (!deteccion) {
                // Sin cara no hay reto: se deja de contar como progreso.
                alCambiar({ fase: 'reto', gesto, paso: 'encuadra' });
                await new Promise((r) => setTimeout(r, 200));
                continue;
            }

            const valor = medir(deteccion.landmarks, gesto);

            if (!salioDelCentro) {
                alCambiar({ fase: 'reto', gesto, paso: 'gesto' });
                if (Math.abs(valor) >= UMBRAL_GESTO[gesto]) {
                    salioDelCentro = true;
                }
            } else {
                // Segunda mitad: volver al centro. Es la que descarta una foto
                // sostenida en angulo.
                alCambiar({ fase: 'reto', gesto, paso: 'centro' });
                if (Math.abs(valor) <= UMBRAL_CENTRO[gesto]) {
                    return { superado: true, gesto };
                }
            }

            await new Promise((r) => setTimeout(r, 120));
        }

        return { superado: false, gesto };
    }

    /**
     * Enrolamiento guiado, como en el kiosco de asistencia: se pide mantener la
     * cara encuadrada y se toman varias muestras espaciadas.
     *
     * De la cara NO se guarda ninguna imagen: solo los 128 numeros con los que
     * despues se compara.
     */
    async function enrolar(video, muestras = 3, alCambiar = () => {}) {
        const descriptores = [];
        const inicio = Date.now();
        const LIMITE_MS = 45000;

        while (descriptores.length < muestras) {
            if (Date.now() - inicio > LIMITE_MS) {
                return { estado: 'agotado', descriptores };
            }

            const deteccion = await detectar(video);

            if (!deteccion) {
                alCambiar({ fase: 'encuadra', tomadas: descriptores.length, total: muestras });
                await new Promise((r) => setTimeout(r, 250));
                continue;
            }

            descriptores.push(Array.from(deteccion.descriptor));
            alCambiar({ fase: 'muestra', tomadas: descriptores.length, total: muestras });

            // Espaciado entre muestras: si se toman seguidas son casi identicas
            // y no aportan variedad de angulo ni de luz.
            await new Promise((r) => setTimeout(r, 1200));
        }

        return { estado: 'listo', descriptores };
    }

    return { cargarModelos, abrirCamara, cerrarCamara, verificar, capturar, enrolar, retoDeVida };
}
