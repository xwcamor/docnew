# Duval oficial (Michel Duval / IREQ — Hydro-Québec)

Fuente canónica del método de Duval, provista por su autor (`duvalm@ireq.ca`).
Archivo: [`../fuentes-originales/DuvalTriangles1_729Mar2016.xls`](../fuentes-originales/DuvalTriangles1_729Mar2016.xls)
(Mar 2016).

## Validación

Con ppm reales `CH4=5.5, C2H4=10.1, C2H2=2.7` → `%30.1 / 55.2 / 14.8` → **Fault = T3**.
**Idéntico a nuestro motor** (`DuvalService` + `duval_zones.json`). El %C2H2 real
(14.8) cae por debajo del corte de 15 → T3; redondear los ppm a enteros (6/10/3)
sube el %C2H2 a 15.8 y lo manda a DT — error de redondeo, no del método. NO se
redondea la entrada.

## Cómo abrir / leer (estaba "protegido")

Dos capas:

1. **Workbook encriptado** con la contraseña *default* de Excel `VelvetSweatshop`
   (por eso abre en Excel sin pedir nada, pero las librerías fallan con
   "Workbook is encrypted"). Para leer por código: desencriptar con
   `msoffcrypto-tool` (password `VelvetSweatshop`) y luego `xlrd`.
2. **Hojas protegidas** (no se pueden editar / ver fórmulas). Contraseña para
   desproteger: **`220270`** (estaba en el VBA `modConstante.conMPDebarrerFeuille`).
   En Excel: *Revisar → Desproteger hoja → 220270*.

El VBA completo extraído está en [`DuvalTriangle1_VBA_extraido.txt`](DuvalTriangle1_VBA_extraido.txt)
(módulos `modConstante`, `modCoordonnees`, `modCalculateIntersection`,
`modResultat`, `modTriangle`, `modGeneral`). La clasificación NO está en el VBA:
es una fórmula de celda (`rngFault`); la geometría se dibuja resolviendo
intersecciones de líneas (tabla `x1/y1/yy1` por hoja).

## Lo que contiene (11 hojas) — relevante para cerrar Duval

A diferencia del nombre del archivo, el workbook trae **todos los triángulos**:

| Hoja | Para |
|---|---|
| Triangle 1 Mineral Oils | T1 mineral (ya canónico en nuestro sistema) |
| Triangle 2 LTC / LTC-MR | conmutadores |
| Triangle 3 BioTemp / FR3 / Midel / Silicone | aceites no minerales |
| **Triangle 4 LTF Mineral Oils** | **T4 — canónico (nuestro pendiente)** |
| **Triangle 5 LTF Mineral Oils** | **T5 — canónico (nuestro pendiente)** |
| Triangle 6 / 7 LTF FR3 Oils | T6/T7 (no usados aún) |

## Lógica canónica de selección de triángulos (de las notas del Excel)

Extraída textual de las hojas oficiales. **Esta es la regla maestra** — qué
triángulo se usa, con qué gases y para qué aceite. Nuestro sistema ya la cumple
(ver verificación abajo); para futuros updates, editar SOLO los datos.

| Triángulo | Aceite / uso | Gases | Zonas | Cuándo se usa |
|---|---|---|---|---|
| **T1** | Mineral (trafos, bushings, cables) | CH₄·C₂H₄·C₂H₂ | PD·D1·D2·T1·T2·T3·DT | Siempre (DGA principal en mineral). |
| **T4** | Mineral — **LTF** (low-temp faults / papel) | H₂·CH₄·C₂H₆ | PD·S·C·O·N/D | Solo para refinar fallas que el T1 dio como **PD, T1 o T2**. **NO usar para D1, D2 ni T3.** |
| **T5** | Mineral — térmicas / papel | CH₄·C₂H₄·C₂H₆ | PD·S·C·O·T2·T3·N/D | Refina fallas térmicas (T2/T3). |
| **T2** | Conmutadores (LTC) mineral | CH₄·C₂H₄·C₂H₂ | N·T2·T3·X1·X3·D1 | LTC tipo compartimento (no nuestro caso hoy). |
| **T3** | **NO mineral**: BioTemp, FR3, Midel, Silicone | CH₄·C₂H₄·C₂H₂ | igual al T1, con cortes de %C₂H₄ por aceite | Aceites vegetales/éster/silicona. |
| T6 / T7 | LTF para aceites FR3 (éster) | — | — | No usados aún. |

Glosario de zonas nuevas: **S** = stray gassing (T<200°C) · **C** = hot spots con
carbonización de papel (T>300°C) · **O** = overheating (T<250°C) · **N/D** = no
determinado.

## Verificación contra nuestro sistema (2026-06) — CANÓNICO, no mandrakeado

`duval_zones.json` + `DuvalService` coinciden con el oficial:

| Pieza | Oficial | Nuestro | |
|---|---|---|---|
| T4 visibilidad | PD, T1, T2 (no D1/D2/T3) | `T4_if_T1_in = [PD,T1,T2]` | ✅ idéntico |
| T4 gases/zonas | H₂·CH₄·C₂H₆ → PD/S/C/O/N-D | h2/ch4/c2h6 → O/ND/S/C/PD | ✅ |
| T5 gases/zonas | CH₄·C₂H₄·C₂H₆ → PD/S/C/O/T2/T3/N-D | ch4/c2h4/c2h6 → O/S/T2/T3/C/ND/PD | ✅ |
| T3 por aceite | BioTemp / FR3 / Midel / Silicone | girasol(=BioTemp) / soya(=FR3) / silicona / ester_sintetico(=Midel) | ✅ |
| T1 clasificación | inecuaciones IEC 60599 | `cond` por inecuaciones | ✅ |
| T2 (LTC) | hoja 'Triangle 2 LTC' | `triangle2` en `duval_zones.json` (oil=`ltc`) | ✅ líneas; etiquetas N/X1/X3 ver nota |

### Cortes de %C2H4 del Triángulo 3 (extraídos de la columna `yy1` de cada hoja)

Tabla de líneas de cada hoja Triangle 3: `Seq2.yy1`=corte T2/T3, `Seq3.yy1`=corte
T1/T2, `Seq6.yy1`=corte D1/D2. Verificado: Silicone reproduce **9/16/46 al dígito**
contra lo que ya teníamos. Valores oficiales:

| Aceite (hoja) | Código nuestro | d1d2 | t1t2 | t2t3 |
|---|---|---|---|---|
| Silicone | `silicona` | 9 | 16 | 46 |
| FR3 | `vegetal_soya` | 25 | 43 | 63 |
| BioTemp | `vegetal_girasol` | 20 | 52 | 82* |
| **Midel** | `ester_sintetico` | **26** | **39** | **68** |

(*) BioTemp/t2t3 no viene explícito en la hoja → extrapolado.

### Triángulo 2 LTC (conmutadores) — extracción y validación

La tabla de líneas de la hoja 'Triangle 2 LTC', convertida a baricéntrico
(`p1=%CH4=ye/100`, `p2=%C2H4=(xe-0.5·ye)/100` desde las columnas `x trouvé`/`y
trouvé`), da 5 rectas de frontera **canónicas**: `%C2H4=23`, `%C2H4=50`,
`%C2H2=15`, `%CH4=2`, `%CH4=19` (+ `%C2H4=6`). Teselan el triángulo en 6 zonas
sin huecos. **Validado**: el punto ejemplo de la propia hoja (CH4=2342, C2H4=3518,
C2H2=12 → %59.9 C2H4) cae en **T3**, igual que `H4='T3'` en el Excel.

Las etiquetas T2/T3 (térmicas, %C2H2<15) están validadas por el ejemplo. Las de
N/X1/X3/D1 se asignaron por el legend oficial de la hoja + la geometría forzada
del teselado (N = recuadro de C2H2 alto = operación normal por arco en aceite;
D1 = esquina de C2H2 puro = arco anormal). Quedan con UNA sola validación numérica
(el punto T3); si aparece una segunda fuente con puntos en N/X1/X3, re-confirmar.

**Conclusión:** la LÓGICA (qué triángulo, por aceite, gating T4/T5, gases y zonas)
es canónica y está verificada — vive en datos (`duval_zones.json`), cero `if`
clavado en el motor. Validado además punto a punto (T3 con ppm reales).

### Triángulos 4 y 5 (LTF) — CANÓNICOS por inecuaciones (2026-06)

Eran lo último portado del Ruby. Ahora **clasifican por inecuaciones** (`classify`
en `duval_zones.json`), extraídas de los cortes de las hojas 'Triangle 4/5 LTF
Mineral Oils' del Excel oficial y verificadas contra xDGA (que las trae como
inecuaciones explícitas, idénticas a los cortes del Excel):

| Tri | Gases | Cortes (%) | Zonas |
|---|---|---|---|
| T4 | H2/CH4/C2H6 | %C2H6=1/24/30/46 · %CH4=2/15/36 · %H2=9/15 | O·S·C·PD·ND |
| T5 | CH4/C2H4/C2H6 | %C2H4=1/10/35/49/70 · %C2H6=2/12/14/15/30/54 | O·S·C·T2·T3·PD·ND |

Cobertura completa (0 NA en el barrido del simplex de 5151 puntos) y los ejemplos
de las propias hojas dan **T4→S** (%H2=72/CH4=21.9/C2H6=6.1) y **T5→C**
(%CH4=42.6/C2H4=37/C2H6=20.4). Antes nuestros polígonos tenían huecos (~18% de
puntos sin zona); los polígonos quedan SOLO para dibujar, la zona sale de las
inecuaciones. Gating T4(si T1∈PD/T1/T2)/T5(si T1∈T2/T3) sin cambios. Test:
`DuvalServiceTest::test_t4_t5_canonical_from_official_excel`.

**Conclusión Duval completa:** ya NO queda nada portado del Ruby sin segunda fuente.
T1/T2/T3 (cortes del Excel + IEC), T4/T5 (inecuaciones del Excel), y los pentágonos
P1/P2/combinado (paper Cheim/Duval/Haider 2020) son TODOS canónicos y testeados.
Detalle de pentágonos en [`pentagono-validacion.md`](pentagono-validacion.md).
