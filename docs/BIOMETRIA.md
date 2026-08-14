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

Se puede desactivar por workspace con `docufiz.always_store_photo`, pero por defecto se guarda.

### Lo que ocupa, y por qué no se dispara

Guardar una foto por firma suena caro. Sin tocar nada lo es: a 500 firmas diarias y 122 KB por
captura salen unos **15 GB al año**, que en el disco de un droplet pequeño es un problema real.

Dos medidas lo bajan un orden de magnitud, y ninguna toca la evidencia como prueba:

| Medida | Efecto |
| --- | --- |
| Cada captura se reduce a **320 px** de lado mayor y se guarda en **WebP** | 122 KB → **24 KB** |
| La misma persona firmando varios formatos **del mismo plan y el mismo día** reutiliza la foto ya guardada | una foto en vez de cuatro |
| Deduplicación por `sha256` | dos capturas idénticas ocupan un archivo |

Las cifras son medidas, no estimadas: 122 KB de JPEG de 640×480 quedan en 24 KB. Y son el peor
caso, porque la imagen de la prueba es ruido puro; una cara real comprime bastante más.

Con las dos medidas, esas mismas 500 firmas diarias ocupan del orden de **1 GB al año** en lugar de
15. Las pruebas que lo fijan están en `tests/Feature/FieldWork/SignatureEvidenceTest.php`, para que
nadie las deshaga sin enterarse.

Lo que **no** cambia: sigue habiendo **una fila de evidencia por cada firma**. Lo que se comparte es
el archivo, no el registro, así que la trazabilidad de quién firmó qué y cuándo queda intacta.

320 px es suficiente para lo único que se le pide a esta foto: que un supervisor reconozca a la
persona al revisar una firma pendiente. No es una foto de archivo, es un acuse.

> **Cuidado al borrar.** Como varios registros pueden apuntar al mismo archivo, borrar una fila de
> `evidence_files` **no** puede borrar el archivo sin comprobar antes que nadie más lo usa. Hoy no
> hay ninguna pantalla que borre evidencias; cuando la haya, esa comprobación es obligatoria.

Para producción, la recomendación es que las evidencias vivan en almacenamiento de objetos (DO
Spaces) y no en el disco del droplet: crece solo, se respalda aparte y no tumba la aplicación cuando
se llena.

## Lo que se guarda

De la cara **no se guarda una imagen**: se guarda el **descriptor**, una lista de 128 números que
face-api.js calcula y con la que se puede comparar, pero no reconstruir el rostro.

- `person_biometrics.face_descriptor` — hasta 5 vectores de 128 valores (el enrolamiento captura 3),
  serializados como JSON y **cifrados en reposo**. La columna es `text`, no `json`.
- El consentimiento del trabajador se registra con fecha y es obligatorio antes de enrolar. El texto
  que aceptó (`consent_text`) se guarda entero y **también cifrado**.

### Por qué van cifrados

Un descriptor facial es un dato biométrico y **no se puede revocar**: a quien le filtran una
contraseña se le cambia la contraseña; a quien le filtran la cara, no. Estaban en claro, así que
quien abriera un backup se llevaba las caras enroladas de todo el padrón.

Cifrarlos no cuesta nada aquí porque **nunca se busca por ellos**: se leen enteros, para una
persona concreta, en la verificación 1:1. No hacen falta índices ciegos ni nada parecido (eso es
sólo para `people.num_doc`, ver `docs/SECURITY.md`).

Dos cosas que hay que tener claras: esto protege contra quien lee un backup, **no** contra quien
tiene el `APP_KEY`; y si el `APP_KEY` se pierde, las caras enroladas no se recuperan y hay que
volver a enrolar a todo el mundo. Migrar lo que ya está en la base:
`php artisan docufiz:cifrar-datos-sensibles`.

Y el historial: `Auditable` **excluye** `face_descriptor` a propósito, para que registrar una cara
no deje una segunda copia en `audit_logs`. `consent_text` sí queda ahí, pero cifrado.

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

Se pide un gesto **al azar** —girar la cabeza o asentir— y se comprueba con los 68 puntos faciales.
Lo que se mide no es la postura sino el **cambio**: hay que salir del centro **y volver**. Esa
segunda mitad es la que importa, porque una foto sostenida en ángulo pasa la primera pero no la
segunda.

La medida va en «anchos entre ojos», así que no depende de lo cerca que esté la persona de la
cámara. Y solo se mira la magnitud del movimiento, no el sentido: así no hay forma de equivocarse
con el espejado de la vista previa, que es el fallo clásico de pedir «gira a la derecha» y que el
usuario vea lo contrario en pantalla.

**Qué para y qué no.** Para una foto impresa y una pantalla quieta —el atajo fácil: la foto del DNI,
el compañero enseñando el móvil—. Un vídeo de la persona haciendo el gesto correcto lo pasaría. No
se vende como más de lo que es: la barrera de verdad contra eso es que la evidencia queda guardada y
un supervisor la mira.

**Si no lo supera, no se bloquea el trabajo.** La cara ya coincidió; lo que falta es el gesto. La
firma se registra por la vía de evidencia —foto guardada, `pending_review`— y cae en la bandeja del
supervisor. Es la misma regla de todo el sistema: en obra nunca se deja a nadie parado, se deja
rastro.

Se activa por workspace con `docufiz.face_liveness`.

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
