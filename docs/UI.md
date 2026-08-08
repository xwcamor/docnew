# Estándar de interfaz

Cómo se construye una pantalla en DOCUFIZ. **Es de obligado cumplimiento para
cualquier módulo nuevo**, y lo que no se puede comprobar leyendo lo comprueba
`tests/Feature/UiStandardTest.php`.

No es una guía de estilo abstracta: cada regla de aquí viene de algo que salió
mal y se tuvo que arreglar. Al final de cada sección está el caso real.

---

## 0. Para quién se construye

Un supervisor de obra, **con casco y guantes, en una tablet, a pleno sol y con
prisa**. No es informático, no ha ido a un curso y no va a leer un manual.

Esa persona es el criterio. Cuando dudes entre dos opciones, gana la que él
entiende sin preguntar. Si una pantalla necesita que alguien la explique, está
mal hecha aunque funcione.

---

## 1. Modal, página o asistente

| Patrón | Cuándo |
| --- | --- |
| **Diálogo (modal)** | Objeto simple: **hasta 8 campos**, un solo grupo, sin tablas dentro |
| **Página completa** | Varios grupos, tablas anidadas, campos que dependen unos de otros |
| **Asistente por pasos** | Cuando hay que **guiar**, no sólo recoger datos |

Los 8 campos son la guía de SAP Fiori, pero el número es lo de menos. Las tres
preguntas que deciden de verdad:

1. **¿Necesita scroll?** Entonces no es un diálogo. Y ojo: en una tablet de 10"
   caben menos de 8 campos, así que el corte real es la pantalla, no la cuenta.
2. **¿Lleva una tabla dentro?** Página completa. Sin excepción.
3. **¿Hace falta ver lo de detrás para rellenarlo?** No puede ser modal — el
   modal tapa justo el contexto que se necesita.

En DOCUFIZ: un **rol aprobador** (código, nombre es, nombre en, orden, activo)
es un diálogo de manual. Un **plan de trabajo** —empresa, tipo, sede, puesto,
área, fechas, descripción, más trabajadores y formatos— es página completa.

---

## 2. Las cosas se llaman como se llaman en obra

El nombre de una columna no es el nombre de un campo en pantalla.

| Mal | Bien | Por qué |
| --- | --- | --- |
| Cuadrilla | **Trabajadores** | «Cuadrilla» la inventó quien programaba. El sistema anterior decía `plan_workers` y el dueño del producto preguntó «¿qué carajos es cuadrilla?» |
| `SLUG`, `ID` | *(fuera de la vista por defecto)* | Son identificadores internos: no significan nada para quien usa la aplicación |
| `WORK_PLANS.NAME` | Código | Una etiqueta en mayúsculas con un punto en medio es **una clave sin traducir**, y es un fallo |

**Regla:** si una etiqueta necesita explicación, está mal puesta. Y si dudas de
una palabra, pregunta antes de escribirla en veinte sitios.

> *Caso real:* «Cuadrilla» llegó a la ficha del plan, a la pantalla de firma y a
> tres archivos de traducción antes de que nadie la cuestionara.

---

## 3. Tablet primero

- **Objetivos de toque de 44 px o más.** Con guantes, menos no se acierta.
- **Nada de scroll horizontal a 1024×768.** Si una tabla no cabe, cada fila pasa
  a ser una tarjeta. La tabla de 7 columnas del AST era exactamente eso.
- **A 768 px todo se apila** en una columna.
- El texto que hay que leer al sol quiere contraste, no elegancia.

---

## 4. La ficha: vista por defecto y vista larga

Una ficha abre con **lo que se necesita de un vistazo**, no con un volcado de
columnas:

- de qué va y para quién,
- cuándo,
- **cómo va** — cuántos de cuántos, qué falta,
- y qué hay que hacer ahora.

Lo técnico —`id`, `slug`, quién lo registró, fechas de creación— va en una
**segunda vista, con un botón para cambiar**, y la elección se recuerda.

> *Caso real:* la ficha del plan abría con «Información general»: veinte campos
> apilados con etiquetas en mayúsculas. Venía tal cual del generador de módulos.

---

## 4-bis. Las listas hermanas comparten componente

Cuando varias listas viven en la misma pantalla —las tres columnas del tablero
del plan: trabajadores, formatos, aprobaciones— **la fila es un solo componente**
(`WorkPlanBoardRow`), no tres plantillas parecidas.

No es purismo. Se escribieron por separado y en una captura se veía de golpe:

- Las aprobaciones llevaban una marca de estado a la izquierda; las otras dos,
  no. Tres anatomías de fila una al lado de otra.
- El estado se contaba de tres maneras: etiqueta verde con la hora, pastilla
  «Confirmado» sin hora ni icono, e icono más etiqueta.
- Los formatos no decían **cuándo** se confirmaron; las otras dos sí.

Nada de eso fue una decisión: fue que cada tarjeta se escribió un día distinto.
Con una fila compartida no pueden volver a separarse.

La anatomía, y vale para cualquier lista nueva de este tipo:

```
[marca] │ título            │ [estado con hora] [acciones]
        │ subtítulo         │
```

> *Caso real:* el dueño mandó una captura del tablero y escribió «no te das
> cuenta de que la UI y UX no tiene la misma coherencia». Tenía razón, y se veía
> sin conocer el código.

---

## 5. El estado se ve sin leer

Un color y una palabra. Nada de descifrar un booleano.

| Estado | Color |
| --- | --- |
| Terminado, conforme, firmado | verde |
| En curso, borrador, pendiente | naranja |
| Bloqueado, no conforme, vencido | rojo |
| Sin empezar, no aplica | gris |

El color **nunca va solo**: siempre acompañado de la palabra. Hay gente que no
distingue el rojo del verde, y al sol se pierde cualquier matiz.

---

## 6. Nada destruye evidencia

Un documento de seguridad puede acabar delante de un inspector. En DOCUFIZ:

- **No se quita del plan a quien ya firmó.** Su firma es la prueba de que estuvo.
- **No se quita un formato que ya tiene respuestas.**
- **Un plan cerrado es de sólo lectura.**

Y cuando se impide algo, **el botón sale deshabilitado y dice por qué**. Un
botón que falla al pulsarlo es peor que un botón que no está.

---

## 7. Traducciones

- **Las mismas claves en `es` y en `en`.** Siempre. Sin excepción.
- **El namespace nuevo va en `loadTranslations()`** de
  `app/Http/Middleware/HandleInertiaRequests.php`. Si no, el front no las recibe
  aunque estén perfectamente escritas.

> *Caso real:* `work_plans`, `people` y `companies` tenían sus traducciones bien
> puestas y no estaban en esa lista. Las cabeceras salían `WORK_PLANS.NAME`.

---

## 8. La barra de acciones

La franja blanca de Guardar/Cancelar **apoya en el borde inferior**, no flota
donde acabe el contenido.

Se consigue con `.sap-form` en columna elástica y `margin: auto` en la barra. El
alto que se descuenta de la ventana es `var(--shell-bar-h)` — **nunca un número
a ojo**.

> *Caso real:* se descontaban 110px, «un número que no correspondía a nada». Las
> páginas terminaban 66px antes del borde y en las listas cortas la barra de
> selección quedaba flotando a media pantalla.

---

## 9. Antes de dar un módulo por terminado

- [ ] Las etiquetas están en el idioma de la obra, no en el de la base de datos
- [ ] Ninguna cabecera sale en mayúsculas con un punto (= clave sin traducir)
- [ ] `es` y `en` tienen las mismas claves, y el namespace está en `loadTranslations()`
- [ ] La ficha abre con la vista útil; lo técnico está en la vista larga
- [ ] A 1024×768 no hay scroll horizontal; a 768 se apila
- [ ] Los objetivos de toque llegan a 44 px
- [ ] Cada estado tiene color **y** palabra
- [ ] Lo que no se puede hacer sale deshabilitado y explica por qué
- [ ] **Comprobado en un navegador de verdad, con datos reales** — no sólo
      `npm run build`
- [ ] `php artisan test` sigue en verde, `UiStandardTest` incluido

El penúltimo punto es el que más veces se saltó, y es el que encontró que el
listado de planes mostraba 366 páginas de filas en blanco.
