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

## 2-bis. Los ejemplos son de mentira

En un `placeholder`, en un texto de ayuda o en una plantilla de importación
descargable, **nunca va nada real**: ni un cliente, ni un documento, ni una
dirección.

| Para qué | Lo que se usa |
| --- | --- |
| Empresa, nombre corto | `ACME`, `GLOBEX` |
| Empresa, razón social | `Acme Servicios Generales S.A.C.` |
| Persona | `Juan Carlos`, `Pérez Gómez` |
| DNI | `12345678` |
| RUC | `20123456789` |
| Dirección | `Av. Principal 123, Lima` |
| Correo | `tu@empresa.com` |

Los números van en cuesta a propósito: nadie los confunde con uno de verdad.

> *Caso real:* los campos de empresa proponían «HITACHI» y «Hitachi Energy Perú
> S.A.», y la plantilla que se descarga traía esa fila más otra de LIMTEK con sus
> RUC. Son clientes reales del sistema anterior metidos dentro del producto:
> salen en la pantalla de cualquier otro cliente, viajan en un `.xlsx` que acaba
> en el correo de quien sea, y encima invitan a subirlo tal cual y dar de alta la
> empresa equivocada.

Los **comentarios del código** sí pueden nombrarlos: explican el caso real que
motivó cada decisión y no los ve ningún usuario. `UiStandardTest` los distingue.

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

Lo técnico —`id`, `slug`, quién lo registró— va en una **segunda vista, con un
botón para cambiar**, y la elección se recuerda. Las fechas del registro ni
siquiera eso: están en el Historial (§4-ter).

> *Caso real:* la ficha del plan abría con «Información general»: veinte campos
> apilados con etiquetas en mayúsculas. Venía tal cual del generador de módulos.

---

## 4-ter. La fecha de creación no se enseña, se consulta

`created_at` **no va en la ficha, ni en el listado, ni en los filtros**. Vive en
la pestaña **Historial**, al lado de quién lo creó y de la última modificación,
que es la traza del registro y está puesta ahí para eso.

No es una manía de colocación. Es que la fecha de alta no contesta ninguna de
las preguntas con las que se abre una pantalla: de un plan se quiere saber
cuándo se ejecuta, de una persona si tiene la biometría vigente, de una
contratista su RUC. Cuándo se tecleó el registro no cambia nada de eso, y aun
así se llevaba una columna de 180px en la tablet, una fila en el panel de datos
de la ficha y una entrada en el desplegable de filtros y en el de orden.

Lo que **sí** se queda, y no se toca:

| Dónde | Por qué |
| --- | --- |
| La pestaña Historial (`RecordHistory`, `ActivityTimeline`) | Es la traza de auditoría —«creado el X por Y»—, no un campo de la ficha |
| La papelera | Ahí la fecha sí dice algo: cuándo se borró el registro |
| Las listas blancas de orden del servidor (`in_array($sort, [… 'created_at' …])`) | Hay **vistas guardadas** de usuarios ordenando por esa clave. Quitarla de la pantalla ya la saca del menú; quitarla del servidor haría que esas vistas se cayeran al orden por defecto sin avisar |
| La clave `global.created_at` de `es`/`en` | La usa el Historial |

> *Caso real:* «en todos los módulos quita la fecha de creación de filtros, ver
> columna y del rightside, no aporta nada ese campo». Estaba en el
> `filterSchema()` de 24 modelos y servicios, en 23 `columns.js` y en dos fichas.

`UiStandardTest` lo comprueba: si vuelve a colarse en un `columns.js` o en un
`filterSchema()`, la prueba falla.

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

## 5-bis. Los colores salen de un nombre, nunca de un hex

**Ninguna pantalla escribe un color a mano.** Todos están declarados una vez en
`resources/css/app.css` y se usan por nombre. La razón no es de gusto: hay tema
claro y tema oscuro, y cuatro esquemas de color más que el usuario elige en su
perfil. Un `#0A6ED1` tecleado en un `.vue` se queda azul en todos ellos.

### La paleta

| Para qué | Token |
| --- | --- |
| Acción principal, enlaces, foco | `--color-primary` |
| Texto normal / apagado / muy apagado | `--color-text` · `--color-text-muted` · `--color-text-dim` |
| Fondo de tarjeta / alterno / hover / seleccionado | `--color-surface` · `--color-surface-alt` · `--color-surface-hover` · `--color-surface-selected` |
| Fondo de página (gris SAP) | `--sap-page-bg` |
| Bordes | `--color-border` · `--color-border-soft` · `--color-border-strong` |
| Barra superior | `--color-shell-bar` |
| Alto de esa barra | `--shell-bar-h` |

### Los estados, que son tres colores cada uno

Un estado se pinta como pastilla —texto, fondo y borde—, así que cada uno tiene
sus tres tokens: `--state-{ok,warn,bad,info,off}-{text,bg,border}`.

```css
.pill--ok { color: var(--state-ok-text); background: var(--state-ok-bg); border-color: var(--state-ok-border); }
```

Los mismos nombres valen en oscuro; lo que cambia son los valores, y cambian en
un solo sitio.

> *Caso real:* no había token de «correcto». Resultado: `#1D7A44` y `#137A43`
> tecleados 46 veces entre las páginas — **dos verdes distintos para el mismo
> estado**, según quién escribiera la pantalla. En total había **753 colores a
> mano en 77 ficheros**, y la mayoría eran tokens que ya existían: `#0A6ED1`
> aparecía 73 veces teniendo `--color-primary` desde el principio.

Y color **nunca solo**: cada estado lleva color *y* palabra (§5).

---

## 5-ter. Antes de escribir CSS, mira si ya está

El sitio de una regla depende de a cuántos afecta, y equivocarse es como
acabamos con cuatro barras de acciones distintas:

| Dónde | Para qué |
| --- | --- |
| `resources/css/app.css` | Todo lo que comparten dos o más módulos: `.sap-index`, `.sap-form`, `.sap-show`, `.sap-actionbar`, `.mi-*`, `.ff-*`, `.pill--*` |
| `<style scoped>` de un componente `Common/` | Lo propio de ese componente y de nadie más |
| `<style scoped>` de una página | Sólo lo que esa pantalla no comparte con ninguna otra |

Si estás a punto de copiar un bloque de CSS de otro fichero, **para**: eso es
una clase compartida que todavía no existe. Súbela a `app.css` y úsala en los
dos sitios.

### Componentes que ya existen — úsalos

`SectionHeader`, `FormFooter`, `DeleteFooter`, `EditAllFooter`,
`ResponsiveTable`, `ColumnSelector`, `FilterBar`, `ExportDialog`,
`ImportDialog`, `SavedViews`, `FechaHora`. Están en
`resources/js/Components/Common/`. Ninguna pantalla escribe su propia versión.

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

Hay **una sola** franja de acciones en todo el producto, y es la misma la use
quien la use: Guardar/Cancelar de un formulario, Eliminar de una ficha, Guardar
todo de «Editar todo» y las acciones masivas de un índice. Se llama
`.sap-actionbar` y vive en `resources/css/app.css`.

**Apoya en el borde inferior**, no flota donde acabe el contenido. El alto que
se descuenta de la ventana es `var(--shell-bar-h)` — **nunca un número a ojo**.

### Las medidas, que son las mismas para todos

| | |
| --- | --- |
| Alto | **57px**, declarado. `min-height: 1px de borde + 10 + 32 (alto de un botón) + 14` |
| Padding | `10px` arriba, `14px` abajo, y a los lados lo que sangre el contenedor |
| Fondo | `var(--color-surface)` |
| Borde | `1px solid var(--color-border-soft)` arriba, y sombra `0 -2px 10px rgba(0,0,0,.06)` |
| `z-index` | `6` |
| Botones | Tamaño por defecto (`middle`). **Nunca `small`**: 24px no llega al objetivo táctil |
| Orden | La acción primaria pegada al borde derecho, las demás a su izquierda |
| Información | «3 seleccionados», «2 cambios pendientes» → a la izquierda, en `.sap-actionbar__info` |

### Cómo se sangra hasta el borde

La barra va a ancho completo aunque cuelgue de un contenedor con relleno. No
cuentes los paddings de los antepasados a mano: el contenedor los declara y la
barra los consume.

```css
.sap-form, .sap-index { --bar-bleed-x: 24px; --bar-bleed-b: 24px; }
.sap-form .form-body  { --bar-bleed-x: 52px; --bar-bleed-b: 48px; }  /* 24 + 28 */
```

Y los **dos** márgenes hacen falta, cada uno para un caso que el otro no cubre:

- `margin-top: auto` — con poco contenido la página no scrollea, `sticky` no
  tiene de qué engancharse y la barra se queda a media pantalla con un pastizal
  gris debajo. El `auto` se come ese hueco.
- `margin-bottom` negativo — cuando sí scrollea, `sticky` no puede salirse de su
  caja y al final del scroll la barra aterriza por encima del borde y se
  despega.

Para que el `auto` tenga hueco que comerse, cada eslabón entre la página y la
barra tiene que ser columna elástica. Lo hace `:has(.sap-actionbar)` en
`app.css`; no hace falta tocar nada en la página.

> *Caso real (1):* se descontaban 110px, «un número que no correspondía a nada».
> Las páginas terminaban 66px antes del borde y en las listas cortas la barra de
> selección quedaba flotando a media pantalla.
>
El alto **se declara, no se deduce de lo que lleve dentro**. La del formulario
lleva dos botones y la del índice cuatro más un contador: dejándolo al contenido
medían 57 y 49, y son pantallas que se abren una detrás de otra.

> *Caso real (2):* llegó a haber **cuatro copias** de esta franja con números
> distintos — 20px de padding arriba en dos de ellas y 10px en las otras,
> botones `middle` en unas y `small` en otras, márgenes negativos solo en dos.
> Y entre los propios formularios: trece llevaban el footer dentro de
> `.form-body` y trece suelto, o sea dos anchos distintos dentro del mismo
> módulo. Medido en el navegador: 57px de alto en el formulario y 41px en el
> índice, en pantallas que el usuario abre una detrás de otra.

**No escribas otra.** `UiStandardTest` falla si aparece un `position: sticky` con
`bottom: 0` fuera de esta clase.

---

## 9. Antes de dar un módulo por terminado

- [ ] Las etiquetas están en el idioma de la obra, no en el de la base de datos
- [ ] Ninguna cabecera sale en mayúsculas con un punto (= clave sin traducir)
- [ ] `es` y `en` tienen las mismas claves, y el namespace está en `loadTranslations()`
- [ ] La ficha abre con la vista útil; lo técnico está en la vista larga
- [ ] La fecha de creación no sale ni en la ficha, ni en las columnas, ni en los
      filtros — su sitio es el Historial
- [ ] A 1024×768 no hay scroll horizontal; a 768 se apila
- [ ] Los objetivos de toque llegan a 44 px
- [ ] Cada estado tiene color **y** palabra
- [ ] Lo que no se puede hacer sale deshabilitado y explica por qué
- [ ] **Comprobado en un navegador de verdad, con datos reales** — no sólo
      `npm run build`
- [ ] `php artisan test` sigue en verde, `UiStandardTest` incluido

El penúltimo punto es el que más veces se saltó, y es el que encontró que el
listado de planes mostraba 366 páginas de filas en blanco.
