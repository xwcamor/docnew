import * as faceapi from '@vladmandic/face-api';

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
                    return { estado: 'reconocida', descriptor, distancia, foto: capturar(video) };
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

    return { cargarModelos, abrirCamara, cerrarCamara, verificar, capturar, enrolar };
}
