# Auditoría de fiquis (fisicoquímico) vs sistema Ruby viejo

> Fuente de verdad: `app/models/physical.rb` del repo viejo
> (https://github.com/xwcamor/trapp) + esquema `tr_app_development_schema.sql`.
> Norma: IEEE C57.106. Verificado el 2026-06-07.

## Campos (tabla `physicals` del viejo → `fiquis` nuevo)

| Viejo | Significado | Nuevo | Modo |
|---|---|---|---|
| `num_acid` | Número ácido (D974) | `acid` | scored |
| `num_pot`  | Factor de potencia 25°C (D924) | `pot` | scored |
| `num_pot2` | Factor de potencia 100°C (D924) | `pot100` | **limit** |
| `num_rig`  | Rigidez dieléctrica (D1816) | `rig` | scored |
| `num_rig2` | Rigidez dieléctrica (D877) | `rig877` | **limit** |
| `num_ten`  | Tensión interfacial (D971) | `ten` | scored |
| `num_wat`  | Contenido de agua (D1533) | `wat` | scored |

## Scoring (DGAF) — `suma_score_peso`

- Pesos (verificados verbatim): **rig 3, ten 2, ácido 1, agua 4, pot 3**.
- Total mineral/vegetal = 13; silicona = 11 (no mide tensión).
- Fórmula: `DGAF = Σ(score×peso) / Σ(peso)`, score 1=mejor..4=peor.
- Semáforo: <1.2 Muy Bueno · 1.2–1.5 Bueno · 1.5–2 Medio · 2–3 Malo · ≥3 Muy Malo.
- **`num_pot2` y `num_rig2` NO entran al `suma_score_peso`** (confirmado: solo
  aparecen en `ieee_num_pot2`/`ieee_color_num_pot2` y `ieee_num_rig2`/
  `ieee_color_num_rig2`). Tienen límite y color propios por celda, no puntúan.

## Umbrales de los puntuables (mineral, ejemplo media tensión)

rig 47/40/35 · ten 30/23/18 · ácido 0.04/0.10/0.15 · agua 20/30/40 — **coinciden**
con `fiquis_rules.json`. El port de los 5 puntuables se verificó fiel.

## Límites de los campos `limit` (umbral único + tolerancia ±10%)

- **rig877 (D877)** mayor=mejor: mineral 25, silicona 25, soya/girasol 40/47/50
  (baja/media/alta). Rojo < límite · amarillo hasta +10% · verde ≥ +10%.
- **pot100 (PF 100°C)** menor=mejor: mineral 5, silicona 0.2, soya/girasol 3.
  Rojo ≥ límite · amarillo desde −10% · verde < −10%.

## Diseño escalable

Todo vive en `database/seeders/data/fiquis_rules.json`:
- `params[key]`: weight (si scored), dir, unit, **astm** (la norma de la columna),
  **mode** (`scored` | `limit`), y `tol` (para limit).
- `tables[oil][clase][key]`: `[t1,t2,t3]` si scored, `[limite]` si limit.
- `display`: orden de columnas en la grilla.

`FiquisDiagnosisService` lee de ahí: `evaluate()` puntúa solo `param_order`;
`limitsFor()` colorea todos (4 bandas si scored, 3 si limit); `columnsFor()` arma
las columnas con su norma para el front. **Agregar un campo nuevo = una fila en
`params` + `tables` (+ una migración para la columna). Sin tocar código.**

## Decidido (2026-07-26): pot100 y rig877 NO puntúan — CERRADO

Se evaluó incorporarlos al DGAF y se decidió mantenerlos fuera, con dos razones:

1. **Contarían dos veces la misma propiedad.** rig877 y rig son la misma
   magnitud (rigidez dieléctrica) por dos métodos; pot100 y pot son el mismo
   factor de potencia a dos temperaturas. Sumándolos, esas dos propiedades
   pasarían del 46 % al 63 % del índice (rigidez 6/19 + PF 6/19), diluyendo
   acidez, agua y tensión interfacial, que son las que detectan la degradación
   química del aceite y del papel.
2. ~~**No aportan cobertura.** Sobre 7 476 muestras reales: 0 tienen solo D877
   sin D1816, y 0 tienen solo PF a 100 °C sin PF a 25 °C.~~ **FALSO — ver
   "Sustitución" abajo.** Ese conteo salió de una base donde el "no medido" del
   sistema viejo estaba guardado como **0**, no como NULL: las muestras que solo
   traían el método alterno se veían como si el principal valiera 0 kV. Al
   corregir los ceros aparecieron **626 con solo D877 y 104 con solo el PF a
   100 °C**. El razonamiento 1 (no sumar) sigue en pie; el 2 se cayó.

Lo que SÍ cambió: dejaron de ser invisibles. La traza del diagnóstico ("¿Por
qué este resultado?") los lista en un bloque **Parámetros de referencia** con
su valor, su límite y su estado (Cumple / Cerca del límite / Fuera de norma),
marcados "no puntúa".

## Sustitución (2026-07-27): el alterno OCUPA el lugar del principal

La misma nota de arriba lo anticipaba: «si el laboratorio empezara a reportar
SOLO el método alterno, la forma correcta sería sustituir». Pasó — nunca dejó de
pasar, solo que los 0 lo tapaban. Implementado en
`FiquisDiagnosisService::measurementFor()`:

- Si el principal midió, todo sigue igual: puntúa él y el alterno queda de
  referencia. **No se suman nunca** (el razonamiento 1 no cambió).
- Si el principal NO midió y el alterno sí, el alterno puntúa **en su lugar**,
  con el **peso de la propiedad** (rigidez 3, factor 3) y contra **su propia
  norma**. Una propiedad = un solo lugar en el índice.
- Si no midió ninguno de los dos, la propiedad queda fuera (peso dinámico), como
  siempre.

### Con qué bandas se puntúa el alterno

La norma publica para D877 y para el PF a 100 °C un **valor de aceptación**, no
una gradación de cuatro niveles. Así que:

1. Si en `tables` el alterno tiene `[t1,t2,t3]` (el laboratorio entregó sus
   cuatro niveles y el super los cargó), gradúa 1..4 como cualquier otro.
2. Si solo tiene el límite único —el caso normal— se puntúa con **las mismas
   tres bandas con las que ya se colorea su celda**: límite ± tolerancia.

   | Celda | Score | Ejemplo mineral, PF a 100 °C (límite 5, tol 10 %) |
   |---|---|---|
   | verde (cumple) | **1** | < 4.5 % |
   | ámbar (pegado al límite) | **3** | 4.5 – 5 % |
   | rojo (fuera de norma) | **4** | ≥ 5 % |

**No hay score 2 y es a propósito.** La norma no gradúa; inventar un cuarto
nivel sería darle al dato una precisión que no tiene.

**Por qué NO se derivan las bandas escalando las del principal.** Se probó
(factor = límite del alterno ÷ t3 del principal, mineral baja: 40/35/30 con
límite 25 → 33.33/29.17/25) y se descartó: el color de la celda y el score se
contradecían. Un mineral con PF a 100 °C de 3 % pinta la celda VERDE (< 4.5) y
esa derivación le daba score 3 = Malo. Sobre la base real las dos coincidían en
703 de 721 casos, y en los 18 que diferían la derivación era más severa **con la
celda en verde**. La coherencia entre lo que se ve y lo que puntúa vale más que
un nivel extra de gradación. Test que lo fija:
`test_alternate_score_always_agrees_with_the_cell_colour`.

### Efecto medido sobre la base real

660 muestras usan solo el método alterno. 625 no cambian de score, 4 empeoran
(las que escondían una propiedad fuera de norma) y el resto mejora. A nivel de
transformador, 97 tienen su última muestra en esa situación y **7 cambian de
condición** (6 Medio→Bueno, 1 Malo→Medio).

### El formulario ya no exige rigidez ni factor

Los cuatro campos (`rig`, `rig877`, `pot`, `pot100`) pasaron a **opcionales**
(`FiquiController::presenceRule`). Con uno cualquiera alcanza —el motor
sustituye— y con ninguno la propiedad simplemente no participa, que es el peso
dinámico de siempre.

Se probó pedirlos como "al menos uno del par" y se descartó: **472 ensayos no
tienen el factor por ningún método y 51 no tienen la rigidez**. Con esa regla,
corregir el agua de uno de esos ensayos habría obligado a inventar una medición
que nunca se hizo — la misma trampa que llenó de ceros la base vieja, en chico.
Los otros tres parámetros (tensión interfacial, número ácido, agua) sí se
exigen: no tienen método alterno y el ensayo siempre los mide. Test:
`test_editing_a_sample_without_either_method_is_not_blocked`.

## Pendiente / a revisar con el usuario

Los números que quedan por confirmar con el laboratorio (separación de
electrodos del D1816, `pot100` de silicona y de mineral, `rig877` de vegetales)
están en la lista única de pendientes:
[`docs/brain/backlog-decisiones.md`](../../brain/backlog-decisiones.md).
Duplicarlos acá era la razón de que nadie supiera cuál lista estaba al día.
