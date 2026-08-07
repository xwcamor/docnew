# Duval en el sistema viejo (Ruby) — triángulos y pentágonos

> Trazabilidad extraída del repo Ruby viejo (`xwcamor/trapp`) para portar Duval a
> Laravel. Fuente: `app/models/chromatographical.rb`, `app/controllers/duval_management/*`,
> `app/views/duval_management/*`, `db/seeds.rb` (tabla `duvals`).
>
> **YA PORTADO** (junio 2026): motor `app/Services/Diagnostics/DuvalService.php` +
> zonas en `database/seeders/data/duval_zones.json` (editable) + UI
> `resources/js/Components/Transformers/DuvalTab.vue`. A diferencia del viejo, el
> diagnóstico se **calcula** (point-in-polygon) en vez de cargarse a mano. La regla
> de visibilidad de T4/T5 (sección 2) se respetó tal cual. Este doc queda como
> referencia del origen.

Duval es un método gráfico complementario a la cromatografía: ubica un punto
(porcentajes de gases) dentro de un triángulo o pentágono cuyas zonas indican el
**tipo de falla** (no la severidad — eso lo da el semáforo de cromas/HI). En el
sistema viejo el diagnóstico Duval **no se calculaba con código geométrico**: se
guardaba a mano en la tabla `chromatographical_duvals` (campos `triangle_diag_*` /
`pentagon_diag_*`). El front solo dibuja el polígono, ubica el punto y muestra el
texto de la zona guardada.

---

## 1. Las dos "vistas" (lo que el usuario recordaba como "2 tipos")

Cada gráfico (triángulo o pentágono) existe en dos variantes, elegibles con dos
botones ("Mostrar último Ensayo" / "Mostrar Ensayos"):

| Variante | Ruta / controller | Qué grafica |
|---|---|---|
| **Último ensayo** | `last_triangle_graphs`, `last_pentagon_graphs` | Solo el punto del **último** ensayo cromatográfico. |
| **Todos los ensayos** | `triangle_graphs`, `pentagon_graphs` | **Un punto por cada** ensayo del transformador (evolución histórica). |

Hay además variantes `report_triangle_graphs` / `report_pentagon_graphs` (mismos
gráficos embebidos en el PDF del informe) y `silicona_graphs` (gráficos de
contenido para aceite silicona). El motor de dibujo es el mismo.

**Popup al pasar el mouse**: en ambas variantes, al hacer hover sobre un punto se
abre un `div.info` con una tabla — fecha del ensayo + ppm de cada gas + su
porcentaje en ese triángulo. Lógica en
`duval_management/*/partials/_duval_js.html.erb` (detección por cercanía
`Math.abs(cPos - rPoint) < r`) y la tabla en `_triangle_graphs.html.erb` /
`_pentagon_graphs.html.erb`.

---

## 2. Los 3 triángulos (`duval_type_id = 1`)

Cada triángulo usa 3 gases distintos y tiene su propio juego de zonas. Los
porcentajes se calculan en `chromatographical.rb` (`tr1_*_percent`, `tr2_*`, `tr3_*`):

| # | id interno | `graph_type` | Gases (vértices) | Zonas posibles | Diagnóstico guardado en |
|---|---|---|---|---|---|
| **Triángulo 1** | `normal` | 1 | CH4 / C2H4 / C2H2 | PD, T1, T2, T3, D1, D2, DT | `triangle_diag_first` |
| **Triángulo 4** | `middle` | 4 | H2 / CH4 / C2H6 | PD, S, C, O, ND | `triangle_diag_second` |
| **Triángulo 5** | `last` | 5 | CH4 / C2H4 / C2H6 | PD, S, C, O, T2, T3, ND | `triangle_diag_third` |

(`normal`/`middle`/`last` son los `id` de los `<canvas>`; numeración Duval real =
1, 4 y 5.)

### Regla de visibilidad — CUÁNDO se muestra cada triángulo

Esto es lo que el usuario recordaba ("no siempre salían los 3"). La condición está
clavada en la vista `_triangle_graphs.html.erb` (idéntica en `last_` y en "todos")
y depende **del diagnóstico del Triángulo 1** (`triangle_diag_first`):

| Triángulo | Se muestra si… | Razón (metodología Duval) |
|---|---|---|
| **T1** (`normal`) | **siempre** | Es el triángulo base; siempre se evalúa primero. |
| **T4** (`middle`) | `triangle_diag_first ∈ {PD, T1, T2}` | T4 refina fallas de baja temperatura / descarga parcial (distingue stray gassing `S`, carbonización `C`, sobrecalentamiento `O`). |
| **T5** (`last`) | `triangle_diag_first ∈ {T2, T3}` | T5 refina fallas térmicas medias/altas. |

Código real (ERB):

```erb
<%# Triángulo 4 (middle) %>
<div class="col-xl-4" style="display:
  <% if @chromatographical_duval.triangle_diag_first == "PD"
        or @chromatographical_duval.triangle_diag_first == "T1"
        or @chromatographical_duval.triangle_diag_first == "T2" %>
  <% else %> none; <% end %>">

<%# Triángulo 5 (last) %>
<div class="col-xl-4" style="display:
  <% if @chromatographical_duval.triangle_diag_first == "T2"
        or @chromatographical_duval.triangle_diag_first == "T3" %>
  <% else %> none; <% end %>">
```

Consecuencias:
- Falla eléctrica pura (**D1**, **D2**, **DT**): solo se muestra **T1** (ni T4 ni T5).
- **PD** o **T1**: se muestran **T1 + T4**.
- **T2**: se muestran **los 3** (es el único caso con los 3 triángulos a la vez).
- **T3**: se muestran **T1 + T5**.
- Diagnóstico vacío (sin calcular): solo **T1**.

Zonas (de `db/seeds.rb`, tabla `duvals`):
- **T1** → PD=Descarga Parcial · T1=Térmica <300℃ · T2=Térmica 300–700℃ · T3=Térmica >700℃ · D1=Descarga baja energía · D2=Descarga alta energía · DT=Eléctrico+Térmico.
- **T4/T5** → PD · S=Pérdida de gas (stray gassing) <200℃ · C=Carbonización del papel >300℃ · O=Sobrecalentamiento <250℃ · ND=No determinado (+ T2/T3 en T5).

---

## 3. Los 3 pentágonos (`duval_type_id = 2`)

El pentágono usa **5 gases** (H2, CH4, C2H4, C2H6, C2H2) — `pn1_*_percent` en el
modelo. Hay 3 variantes de zonas:

| # | id `<canvas>` | `graph_type` | Qué resuelve | Diagnóstico guardado en |
|---|---|---|---|---|
| **Combinado** | `combine` | 3 | Pentágono 2 detallado: subdivide con/sin carbonización (T1-O, T1-C, T2-C, T2-O, T3-C, T3-H) + S, PD, D1, D2 | `pentagon_diag_first` |
| **Pentágono 1** | `normal` | 1 | Zonas principales: PD, S, T1, T2, T3, D1, D2 | `pentagon_diag_second` |
| **Pentágono 2** | `faultzone` | 2 | Zonas base de carbonización: PD, S, O, C, T3-H, D1, D2 | `pentagon_diag_third` |

### Visibilidad de los pentágonos — HONESTIDAD

A diferencia de los triángulos, en el código los **3 pentágonos se renderizan
siempre** (3 columnas fijas, sin `display:none` condicional). Lo único condicional
es el texto: si el `pentagon_diag_*` correspondiente está vacío, en lugar del
nombre de la zona muestra **"Error al Calcular"**:

```erb
<% if @chromatographical_duval.pentagon_diag_first.blank? %>
  Error al Calcular
<% end %>
```

Es decir: el recuerdo de "tampoco salían siempre los 3 pentágonos" en el código
**no corresponde a una condición de ocultamiento** como en los triángulos; lo que
variaba era que un pentágono mostrara su zona o el cartel de error cuando el
diagnóstico no se había cargado (los `triangle_diag_*` / `pentagon_diag_*` se
llenaban a mano). Para datos sin cargar, los 5 huecos quedaban en blanco / error.

---

## 4. Implicancias para el porte a Laravel (pendiente)

Cuando se porte Duval, respetar el principio rector (reglas en datos, no en código):

1. **Calcular el diagnóstico, no cargarlo a mano.** El viejo guardaba
   `triangle_diag_*` manualmente. El nuevo debería ubicar el punto en las zonas
   (polígonos en datos) y derivar la zona automáticamente desde los ppm del ensayo.
2. **Zonas como datos**, no como `if`: los polígonos (`_SegmentPos1/2/3` del JS
   viejo, en coordenadas baricéntricas `p1/p2/p3`) y las descripciones de la tabla
   `duvals` van a un JSON editable (estilo `cromas_rules.json`).
3. **Regla de visibilidad de T4/T5** (sección 2) es la única lógica "de flujo":
   T1 siempre; T4 si T1∈{PD,T1,T2}; T5 si T1∈{T2,T3}. Conviene dejarla explícita
   y documentada (es metodología Duval estándar, no un capricho del viejo).
4. **Geometría**: el punto se ubica con porcentajes baricéntricos
   (`gas / suma_gases_del_triángulo × 100`) y se prueba contiene-punto-en-polígono
   (`IsPointInPolygon` en el JS viejo). El pentágono usa los 5 gases sobre ejes
   pentagonales (centroide).
5. **Solo aceite mineral**: las zonas Duval del viejo están definidas para mineral
   (`duval_type_id` 1 y 2). Silicona tiene su propio set de gráficos de contenido
   (`silicona_graphs`), no triángulos de falla.

### Mapa de archivos del viejo (para extraer al portar)

```
app/models/chromatographical.rb         -> tr1/tr2/tr3 + pn1 *_percent (geometría)
app/controllers/duval_management/
  triangle_graphs_controller.rb          -> @duval_type1/4/5 = Duval.where(graph_type:)
  pentagon_graphs_controller.rb          -> @duval_type1/2/3
  last_*_graphs_controller.rb            -> variante "último ensayo"
app/views/duval_management/
  triangle_graphs/partials/
    _triangle_graphs.html.erb            -> REGLA DE VISIBILIDAD T4/T5 (clave)
    _data_js.html.erb                    -> _SegmentPos1/2/3 (polígonos de zonas)
    _duval_js.html.erb                   -> dibujo + popup hover
    _data_values.html.erb                -> serialización de puntos (todos los ensayos)
  pentagon_graphs/partials/...           -> equivalentes para pentágono
db/seeds.rb (≈línea 2847, tabla duvals)  -> nombres/descripciones de cada zona
```
