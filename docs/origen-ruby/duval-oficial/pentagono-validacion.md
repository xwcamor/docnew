# Validación de los pentágonos de Duval (P1 / P2 / combinado)

> Investigación 2026-06. Cierra (en parte) el pendiente que tenía el CLAUDE.md:
> "los pentágonos tienen vértices portados del Ruby viejo, NO verificados contra
> la fuente canónica".

> **Excel oficial de pentágonos (2026-06):** el usuario aportó el archivo del autor
> `DuvalPentagons12_Aug2016.xls` (hojas 'Alg 2' y 'Feuil1'). Copia **desprotegida**
> en [`DuvalPentagons_oficial_sin-proteccion.xls`](DuvalPentagons_oficial_sin-proteccion.xls)
> (se parchearon los flags PROTECT/PASSWORD/WINDOWPROTECT del stream BIFF; original
> en `../fuentes-originales/`). Es el complemento del Excel de triángulos — fuente
> primaria de Duval para los pentágonos, además del paper Cheim/Duval/Haider 2020.

## Cotejo contra el Excel oficial de Duval (hoja 'Alg 2')

La hoja 'Alg 2' trae el ALGORITMO completo de Duval: ejes de gas, proyección y las
coordenadas de zona del Pentágono 1 (cols B/C/D) y Pentágono 2 (cols AB/AC/AD).

- **Ejes de gas + proyección: IDÉNTICOS a los nuestros.** El Excel define H2 arriba
  (vertical), C2H2 a 18° (cos18/sin18), C2H4 a -54°=306°, CH4 a 234° (-cos54/-sin54),
  C2H6 a 162° (-cos18/sin18). El punto de cada gas = gas%·(cos,sin). Igual a
  `gas_angles_deg` y a `DuvalService::pentagon`.
- **Coordenadas de zona: sub-unidad idénticas.** PD idéntico. El resto difiere solo
  en redondeo (el Excel es Ago-2016; el paper 2020 REFINÓ esos mismos valores, que
  son los que usamos):

  | Punto | Excel 2016 | Nuestro (= paper 2020) |
  |---|---|---|
  | D1 sup. | (38,12) | (38,12.4) |
  | D1/D2 der. | (32,-6) | (32,-6.1) |
  | D2/T3 fondo | (24,-30) | (24.3,-30) |
  | T3 fondo | (23.2,-32.4) | (23.5,-32.4) |
  | T2/T3 fondo | (1,-32) | (1,-32.4) |
  | T1/T2 fondo | (-22.5,-32) | (-22.5,-32.4) |
  | S/T1 izq. | (-35,3) | (-35,3.1) |

  Diferencia máxima 0.4 en una figura de ~70 de alto (≈0.6%) — no cambia ningún
  diagnóstico. Es el mismo refinamiento 2014→2020 ya documentado abajo.
- **Única diferencia estructural:** la zona **S** del Excel incluye el recorte (notch)
  de PD en su contorno; la nuestra está simplificada (sin el notch) y por eso PD se
  evalúa PRIMERO en el motor (fix ya aplicado). El Excel resuelve lo mismo dibujando
  el notch dentro de S. (Mejora opcional: copiar el polígono S completo del Excel y
  quitar el reorden — puramente de dibujo, no de diagnóstico.)

**Conclusión:** nuestro pentágono YA es el algoritmo oficial de Duval. El Excel
(Ago-2016) lo confirma vértice a vértice; usamos los valores del paper 2020 que es
la refinación posterior del MISMO autor (más preciso y con T1 completo — en el Excel
la zona T1 del Pentágono 1 solo trae 2 vértices explícitos). Cotejo reproducible:
ver el dump de 'Alg 2'.

## Por qué no se pudo usar la fuente primaria directa

- El **Excel oficial de Duval** que tenemos (`DuvalTriangles_oficial_sin-proteccion.xls`)
  es **solo triángulos** — no trae pentágonos.
- Los papers de **Duval & Lamarre 2014** (donde se publican las coordenadas de los
  pentágonos) están tras paywall; los mirrors (MDPI, ResearchGate, PDFs académicos)
  devuelven **403** al fetch desde este entorno.

## Segunda fuente independiente: xDGA

Se usó **xDGA** (MIT, Carlos Gamez) como segunda digitalización independiente de la
MISMA figura publicada de Duval:
<https://github.com/engineers-tools/xDGA> →
`xDGA.CORE/Algorithms/DuvalPentagons/DuvalPentagon{One,Two}Rule.cs`.

Lógica: si dos digitalizaciones independientes (la nuestra, portada del Ruby viejo;
la de xDGA, en C#) coinciden, ambas son fieles a la figura de Duval.

## Resultados

| Pieza | Resultado |
|---|---|
| **Orden de ejes** | CANÓNICO. xDGA: `base("Duval Pentagon 1", H2, C2H6, CH4, C2H4, C2H2)` = H2 arriba (90°) + CCW C2H6/CH4/C2H4/C2H2 (162/234/306/18). **Idéntico** a `duval_zones.json → gas_angles_deg`. |
| **Fórmula del punto** | CANÓNICA. `gas%·(cosθ, sinθ)` + centroide por área con signo. Verificada al 2º decimal contra ejemplo publicado (H2=40/C2H6=120/CH4=200/C2H4=40/C2H2=0 → coords (0,10),(-28.53,9.27),(-29.39,-40.45),(5.88,-8.09),(0,0)). |
| **Zonas P1 (ours vs xDGA)** | **98.71%** de coincidencia de zona sobre 200k mezclas aleatorias. |
| **Zonas P2 (ours vs xDGA)** | **98.49%** sobre 200k. |
| **End-to-end** | El ejemplo publicado clasifica **T1** en AMBAS implementaciones. |

Reproducible: [`pentagono_validacion.py`](pentagono_validacion.py).

## Las discrepancias (~1.3-1.5%)

Son **slivers finos en bordes**, no zonas mal puestas:

- `T3 vs D2` (0.53%), `T1 vs D2`, `T3-H vs D2`: el cruce central. Nosotros usamos
  los vértices `(0,1.5)` y `(0,-3)` sobre el eje Y; xDGA usa el punto único `(-1,-2)`.
  Diferencia de ~1-2 unidades en una figura de ~70 de ancho (≈1.5%), en la región
  donde un punto está casi balanceado entre térmico y descarga (ambigua por diseño).
- `S vs PD` (0.2%): la franja delgada de PD `(0,24.5)-(0,33)` (1 unidad de ancho).
- El resto: bordes compartidos (medida cero, depende del orden de evaluación).

**En el T3, NUESTRO polígono es más limpio que el de xDGA**: el de xDGA
(`(-1,-2),(-6,-4),(1,-32.4),(-23.3,-32.4),(24,-30)`) es **auto-intersectante**
(llega al fondo-izquierdo `-23.3`, que en ambas implementaciones pertenece a T1/T2);
el nuestro es un polígono simple en la cuña fondo-derecha (hacia C2H4 = térmico
>700°C), que es la posición canónica del T3. → no conviene adoptar xDGA en bloque.

## Veredicto

Los pentágonos de nuestro sistema dejan de estar "sin verificar":

- Ejes y fórmula del punto: **canónicos** (verificado).
- Vértices de zona: **cross-validados** contra una implementación independiente,
  98.5-98.7% de equivalencia de diagnóstico; las diferencias son ruido de
  digitalización (±1-2 unidades) en slivers de borde ambiguos, y donde difieren
  notablemente (T3) el nuestro está mejor formado.

**Decisión:** se MANTIENEN nuestros vértices (validados y en T3 más limpios). NO se
adoptan los de xDGA en bloque (importaría su T3 auto-intersectante). Si en el futuro
se consigue la tabla de Duval & Lamarre 2014 sin bloqueo, hacer el último ajuste
fino del cruce central `(0,1.5)/(0,-3)` vs `(-1,-2)` — es lo único que mueve el ~1%.

## Validación adicional contra fuente AUTORITATIVA (primer H-J, Mar-2025)

El usuario consiguió el primer "Primer on Duval Pentagon and Triangle for DGA
Analysis" (H-J Family of Companies, Barry Beaster, Mar-2025), guardado en
[`../fuentes-originales/Primer_Duval_Pentagon_Triangle_HJ_2025.pdf`](../fuentes-originales/Primer_Duval_Pentagon_Triangle_HJ_2025.pdf).
Está basado en el paper oficial **Cheim/Duval/Haider 2020 (Combined Duval Pentagons,
Energies 13, 2859)** + **IEEE C57.155-2014 / C57.146**.

Trae **5 muestras de aceite MINERAL resueltas** (tupla `H2,C2H6,CH4,C2H4,C2H2`) con
su zona graficada en el **Pentágono 2** (Fig 4) y el **Triángulo 1** (Fig 5):

| # | Gases (H2,C2H6,CH4,C2H4,C2H2) | Pentágono 2 | Triángulo 1 |
|---|---|---|---|
| ① | (29, 264, 204, 17, 0)          | O    | T1 |
| ② | (555, 489, 1050, 3520, 29)     | T3-H | T3 |
| ③ | (754, 1127, 2647, 2590, 6)     | C    | T2 |
| ④ | (2070, 1127, 31879, 38192, 55) | T3-H | T3 |
| ⑤ | (6, 12, 46, 9, 0)              | C    | T1 |

**Nuestro motor da 5/5 en AMBOS** (Pentágono 2 y Triángulo 1). Esto valida de punta
a punta las zonas **O/C/T3-H** del Pentágono 2 — las que antes solo estaban
cross-validadas contra xDGA — directamente contra los ejemplos de la fuente.
Bakeado como test de regresión: `tests/Unit/DuvalServiceTest.php ::
test_hj_primer_worked_examples_mineral`.

> El primer también muestra pentágonos/triángulos para FR3 (Annex H C57.155) y
> silicona (C57.146) con sus propios ejemplos — útiles si a futuro se agregan los
> pentágonos de ésteres (hoy solo mineral).

## CIERRE DEFINITIVO — fuente PRIMARIA (Cheim/Duval/Haider 2020 + Duval&Lamarre 2014)

El usuario consiguió los papers originales (antes 403):
- **Cheim, Duval, Haider — "Combined Duval Pentagons: A Simplified Approach"**,
  Energies 2020, 13, 2859. → `../fuentes-originales/Cheim_Duval_Haider_2020_Combined_Pentagons_Energies13-2859.pdf`
- **Duval & Lamarre — "The Duval Pentagon"**, IEEE EI Magazine 2014. →
  `../fuentes-originales/Duval_Lamarre_2014_Pentagons_IEEE_EI_Magazine.pdf`

El paper trae las coordenadas EXACTAS (Figs 3, 4, 8, 9). Resultado del cotejo
vértice a vértice:

- **Cumbres** H2(0,40) C2H6(-38,12.4) CH4(-23.5,-32.4) C2H4(23.5,-32.4) C2H2(38,12.4):
  idénticas a las nuestras. Fórmula del centroide (Ec. 1-3) y ejemplos extremos
  (H2=100→(0,33.3); CH4=100→(-19.5,-26.9); C2H6=100→(-31.6,10.3)): idénticos.
- **NUESTROS vértices SON los canónicos.** En los puntos donde diferíamos de xDGA,
  el paper usa **LOS NUESTROS**, no los de xDGA:

  | Punto | Nuestro | Paper (Fig 3/4/8) | xDGA |
  |---|---|---|---|
  | central sup. | `(0,1.5)` | **(0,1.5)** ✓ | (-1,-2) ✗ |
  | central inf. | `(0,-3)` | **(0,-3)** ✓ | (fundido) |
  | D2/T3 fondo | `(24.3,-30)` | **(24.3,-30)** ✓ | (24,-30) ✗ |
  | D1/D2 der. | `(32,-6.1)` | **(32,-6.1)** ✓ | (32,-6.0) ✗ |
  | C/T3-C sup. | `(-3.5,-3.5)` | **(-3.5,-3.5)** ✓ | (-3.5,-3.0) ✗ |

  Conclusión: lo que en la sección anterior parecía "ruido de digitalización"
  era **xDGA estando ligeramente mal; nosotros coincidimos con el paper al dígito.**

- **5 casos REALES del paper (Tabla 1)**, transformadores con falla confirmada por
  inspección interna, validados contra P1, P2 y Combinado:

  | Caso | Inspección interna | P1 | P2 | Combinado | Motor |
  |---|---|---|---|---|---|
  | 1 | Chapas de hierro recalentadas | T1 | O | T1-O | ✓ |
  | 2 | Selector quemado (aceite) | T3 | T3-H | T3-H | ✓ |
  | 3 | Conexiones carbonizadas | T2 | C | T2-C | ✓ |
  | 4 | Papel carbonizado *(borderline C/T3-H)* | T3 | C/T3-H | T3-C/T3-H | ✓* |
  | 5 | Espiras carbonizadas | T1 | C | T1-C | ✓ |

  4/5 exactos en las tres geometrías; el Caso 4 es el que **el propio paper declara
  borderline** ("cayó en el borde entre C y T3-H, podría ir para cualquier lado") —
  nuestro centroide y el primer H-J lo ubican en T3-H. Test:
  `DuvalServiceTest::test_canonical_paper_cases_pentagons`.

### Fix encontrado en la investigación: zona PD del pentágono inalcanzable

Al cotejar contra el paper se vio que PD es un **notch recortado de S**. Nuestro
polígono S está simplificado (sin el recorte) y, como `locate()` gana el primer
match y S se evaluaba antes que PD, una muestra de corona (H2 dominante, centroide
≈ (0,30)) caía en **S** en vez de **PD**. Arreglado: **PD se evalúa PRIMERO** en
P1/P2/combine (`duval_zones.json`). Regresión: `test_pentagon_pd_zone_is_reachable`.

### Fix #2: centroide degenerado de un solo gas

El ejemplo realista del paper (H2=50/CH4=120/C2H2=30/C2H4=60/C2H6=80) da centroide
(-7.36,-5.80) vs (-7.35,-5.79) del paper — idéntico. Pero los casos extremos (un
gas al 100%, resto 0) no daban: el polígono de 5 puntos queda colineal (área 0) y
nuestro fallback promediaba los 5 (V/5). El valor canónico es **V/3** (el paper:
H2=100→(0,33.3), CH4=100→(-19.5,-26.9), C2H6=100→(-31.6,10.3)). Arreglado en
`DuvalService::centroid()`: si el área es ~0 y hay un único punto no nulo, devuelve
V/3. Pasa solo con muestras de un único gas (otros exactamente 0). Test:
`test_pentagon_centroid_matches_paper_examples`.

### Por qué xDGA difería — RESUELTO con el paper original 2014

El paper ORIGINAL **Duval & Lamarre 2014** (IEEE EI Magazine,
`../fuentes-originales/Duval_Lamarre_2014_Pentagons_IEEE_EI_Magazine.pdf`) publica
su propia tabla de coordenadas, y son las **redondeadas**:

| Punto | 2014 (original) | 2020 (refinado) | Nuestro | xDGA |
|---|---|---|---|---|
| central | (-1,-2) | (0,1.5)+(0,-3) | (0,1.5)+(0,-3) | (-1,-2) |
| D2/T3 | (24,-30) | (24.3,-30) | (24.3,-30) | (24,-30) |
| D1/D2 der | (32,-6) | (32,-6.1) | (32,-6.1) | (32,-6.0) |
| D1 | (38,12) | (38,12.4) | (38,12.4) | (38,12.4) |
| C/T3-C | (-3.5,-3) | (-3.5,-3.5) | (-3.5,-3.5) | (-3.5,-3.0) |
| fondo | (1,-32),(-22.5,-32) | (1,-32.4),(-22.5,-32.4) | (-32.4) | (-32) |

O sea: **xDGA implementó el Duval 2014; nosotros el Duval 2020.** Duval mismo refinó
las coordenadas entre un paper y otro. Ambas son canónicas; la nuestra es la última.
No había ningún error nuestro — solo dos ediciones del mismo método.

El ejemplo resuelto del 2014 (H2=31,C2H6=130,CH4=192,C2H4=31,C2H2=0 → centroide
publicado (-17.3,-9.1)) da en nuestro motor **(-17.30,-9.10)**. Centroide validado
contra los ejemplos de AMBOS papers.

### Veredicto final

Los pentágonos (P1/P2/combinado) quedan **VERIFICADOS contra la fuente primaria**,
no solo cross-validados. Ejes, fórmula, cumbres y vértices de zona = canónicos al
dígito. Ya NO queda el "ajuste fino del ~1%": ése era xDGA, no nosotros. Único
pendiente de Duval que sigue: vértices de T4/T5 (triángulos, extraíbles del Excel
oficial).
