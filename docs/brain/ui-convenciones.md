# Convenciones de UI

← [Índice](00-INDICE.md)

> Estas convenciones se siguen en TODO módulo nuevo. La mayoría vive
> centralizada en `resources/css/app.css` o en componentes compartidos — el
> objetivo es que un cambio de estándar sea UN punto, no 21.

## El look SAP/Fiori (franjas)

- **Fondo de página**: gris `var(--sap-page-bg)` (light `#e9edf2`; en dark cae
  a `--color-page-bg`). Full-bleed con `margin: -24px; padding: 24px`.
- **Franja blanca del título** (full-bleed, sin bordes redondeados):
  - Índices: `.sap-index` (header `.mi-title`; Trafos usa `.tx-title`).
  - Forms: `.form-page sap-form` + `FormFooter floating`.
  - Fichas: `.show-page sap-show` — clase PROPIA: no usar `.sap-form` en Show
    (aplana cards y fuerza `.ant-col` a 100%). Incluye la ficha de trafos.
  - Papeleras: `.sap-form trash-page`.
- **Trampa del full-bleed**: NO poner `width: 100%` en el root — con ancho
  explícito los márgenes negativos desplazan el div pero no lo ensanchan
  (queda hueco a la derecha). Ancho auto.

## Franja blanca al pie (sticky bottom)

- `.bulk-bar` global: la barra de selección masiva de índices y papeleras va
  DESPUÉS de `</Card>`, hija directa del contenedor sap-*. **Sin wrapper
  `v-auto-animate`** (mata el sticky). Los `*BulkBar.vue` no llevan
  `<style scoped>`.
- En forms/EditAll el equivalente es `FormFooter floating` / `EditAllFooter`.

## Tablas

- **EditAll**: thead sticky `top: 44px` en desktop y `top: 0` en ≤768px — en
  móvil la tabla es su propio scroll container (`overflow-x: auto` fuerza
  también `overflow-y`) y el offset del viewport deja una franja blanca. Bug ya
  corregido; no reintroducir.
- **Papeleras**: `ResponsiveTable :view="'table'"` + `:scroll="{x:'max-content'}"`
  (nunca cards en móvil) + buscador en `.trash-toolbar` (derecha, bajo el título).
- **Paginación** como footer del card (fondo `--color-surface-alt` + borde
  superior), igual en índices, EditAll y papeleras.

## Contador de registros

El contador ("1,905 de 2,424 registros") vive SOLO en la toolbar de la tabla —
es información de la vista actual y cambia con tabs/búsqueda/filtros, que
están al lado. El subtítulo del header NO lo repite (se quitó la duplicación
2026-07-16). Números con separador de miles; el texto se arma en UN punto:
`useModuleListMeta.js`.

## Iconografía y acciones

- **Icono de módulo en headers**: tinte suave del color al 12%
  (`color-mix(in srgb, <color> 12%, transparent)`) con glifo en el color — NO
  relleno sólido (parece botón primario). Vive en `SectionHeader` +
  `.mi-title .page-header__icon` + `TransformersPageHeader`.
- **Show y Form usan el icono del sidebar** de su módulo. Delete usa
  `DeleteOutlined`.
- **Tooltips de acciones estándar**: Ver / Editar / Duplicar / Eliminar
  (claves `global.*`, cortas). El detalle de "queda en papelera" vive en la
  página de confirmación, no en el tooltip (solo el super gestiona la papelera).
- **PROHIBIDO `transform` en `:hover` de `.ant-btn`** (translate/scale): mueve
  el botón bajo el cursor y hace parpadear los Tooltip cerca del borde. El
  realce es solo `box-shadow`; el movimiento vive en `:active`.

## Tipografía de identificadores

Slug/código en fichas usan `<code>` pero heredan la tipografía de la app
(regla global: `.spec-cell__value code, .spec dd code,
.ant-descriptions-item-content code { font: inherit; ... }`). El mono se
reserva para contextos genuinamente de código (tokens de API, cron, claves de
permiso).

## Dark theme

- TODO por variables CSS: `html[data-theme="dark"]` redefine
  `--color-surface/-alt/page-bg/text/border/...`. Los componentes que usan
  `var(--...)` siguen solos. Hex claros hardcodeados = leak (barrido completo,
  no reintroducir).
- El modo vive en `localStorage['theme-mode']` ('auto'|'light'|'dark').
- OJO: el overlay del tour de onboarding (driver.js) oscurece la página entera —
  un screenshot "lavado" con tarjetita flotante es el tour, no un bug de tema.

## Esquemas de color

8 esquemas (`sap/slate/emerald/indigo/red/amber/teal/contrast`) vía
`html[data-scheme]` en `app.css` + `Profile/Show.vue`. Cambian primario,
acento, tintes y `--color-shell-bar` (topbar); el sidebar deriva su tinte de
esa var con `color-mix`.

## Charts (echarts)

- Puntos de línea RELLENOS (`symbol: 'circle'`) — el default `emptyCircle`
  (anillo) no se usa. Leyenda como línea sola: `icon: 'roundRect'`,
  `itemHeight: 3`.
- Tendencias individuales (`GasTrends`): grid de tarjetas; con >10 muestras
  cada tendencia pasa a fila completa (misma regla en el PDF).
- Los PNG del informe se capturan de instancias ocultas en
  `.tr-report-capture` (720px) — comparten componente y reglas con la pantalla.

## Validación de formularios

FormRequests Store/Update usan el trait `DerivesAttributesFromLang`
(`$attributeNamespace` = archivo de lang del módulo) → los mensajes de error
dicen el MISMO label que el form. Fallback global en
`resources/lang/{es,en}/validation.php → 'attributes'`. Módulo nuevo = agregar
trait + namespace.
