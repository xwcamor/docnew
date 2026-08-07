# Firma con reconocimiento facial

Portado de **tenkofiz**, el sistema de marcación de asistencia, adaptado a firmar documentos.

## Las tres cosas que se guardan, y cuando

| Que | Cuando | Cuantas veces |
| --- | --- | --- |
| **128 numeros** (descriptor) | al enrolar | **una vez** en la vida del trabajador |
| **Firma manuscrita** | al enrolar o cuando cambie | una vez, versionada |
| **Foto real** | **cada vez que firma** | una por firma |

Cada una responde a algo distinto: el descriptor es para que **la maquina compare**, la firma
manuscrita es lo que **se imprime en el documento**, y la foto es **la prueba de quien estuvo ahi**.

### Por que la foto se guarda siempre

Se guarda tanto si el reconocimiento acierta como si no. En un documento de seguridad, la prueba
que sirve dentro de dos anios es la cara de quien firmo, no la distancia que midio el servidor.

El sistema anterior lo intentaba y no lo cumplia: de 9 012 fotos de trabajadores, **7 508 eran la
cadena `detected_by_IA`** y el archivo nunca existio. La intencion era correcta; el codigo no la
respetaba.

El coste es asumible: con el ritmo del sistema anterior —9 186 firmas al ano— son unos **500 MB
anuales**, y las imagenes se deduplican por hash.

Se puede desactivar por workspace con `docufiz.always_store_photo`, pero por defecto se guarda.

## Lo que se guarda

De la cara **no se guarda una imagen**: se guarda el **descriptor**, una lista de 128 números que
face-api.js calcula y con la que se puede comparar, pero no reconstruir el rostro.

- `person_biometrics.face_descriptor` — JSON con hasta 5 vectores de 128 valores (el enrolamiento
  captura 3).
- El consentimiento del trabajador se registra con fecha y es obligatorio antes de enrolar.

## Enrolamiento

Guiado: un círculo en pantalla se pone verde cuando la cara está bien encuadrada, se mantiene 5
segundos y se toman 3 muestras. Solo se guardan los descriptores.

## Verificación 1:1

1. La persona escribe su documento.
2. El servidor devuelve **solo los descriptores de esa persona** (nunca los de todos).
3. El navegador calcula la distancia euclidiana contra esas muestras y da feedback en vivo.
4. El servidor **vuelve a validar** la distancia contra el umbral antes de aceptar la firma. El
   navegador no es la autoridad.

## Cuando no reconoce — dos relojes y una captura

Este es el comportamiento que pediste, tal como funciona en tenkofiz:

```
La persona se pone frente a la cámara
 ├─ No hay ninguna cara en pantalla ──── 15 s ──> se cancela, no se registra nada
 ├─ Hay cara pero no coincide ────────── 20 s ──> pasa a captura de evidencia
 │                                                 └─ busca cualquier cara hasta 8 s
 │                                                    ├─ la encuentra: toma la foto y deja firmar
 │                                                    └─ no la encuentra: no se registra nada
 └─ Coincide ──────────────────────────────────────> firma verificada
```

La firma resultante queda con:

| Caso | `method` | `used_ai` | `pending_review` | Foto |
| --- | --- | --- | --- | --- |
| Reconocida | `face_recognition` | sí | no | no hace falta |
| Por tiempo de espera | `timeout_capture` | no | **sí** | **sí, se guarda** |
| Manual autorizada | `manual` | no | sí | sí, con motivo y quién autorizó |

Regla que se hereda tal cual: **sin cara en cámara nunca hay firma ni foto**. Y regla nueva: la
firma por tiempo de espera **no bloquea el trabajo en campo**, pero cae en una bandeja de revisión
del supervisor.

## Reto de vida (liveness)

Se pide un gesto aleatorio de cabeza (girar o asentir), calculado con los 68 puntos faciales. Una
foto impresa o una pantalla no lo superan. Se puede desactivar por configuración.

## Ajustes por empresa

Umbral de coincidencia (0,35 a 0,65), segundos de cada reloj, reto de vida sí/no, geolocalización
opcional u obligatoria. El umbral y los tiempos los cambia solo el super-administrador, y el cambio
queda auditado.

## Modelos

Tres modelos de face-api.js en el repositorio (~6,8 MB): detector, 68 puntos faciales y
reconocimiento. No se descargan en tiempo de ejecución.

## Diferencias con tenkofiz

| tenkofiz | DOCUFIZ |
| --- | --- |
| Marca asistencia | Firma un documento de seguridad |
| El fallback completa la marca | El fallback **firma pero deja el registro pendiente de revisión** |
| Umbral 0,50 | Arranca en 0,50 y se ajusta con las distancias reales que registre el sistema |
| Purga evidencias a los 90 días | Las evidencias de firma son documentación legal: se conservan |
