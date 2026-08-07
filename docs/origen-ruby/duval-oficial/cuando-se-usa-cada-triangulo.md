# Cuándo se usa cada triángulo de Duval (selección de método)

> Referencia aportada por el usuario (2026-06), importante para la metodología:
> **Tecnura / Universidad Distrital (Colombia), 2014** — artículo sobre los casos
> de aplicación de los triángulos de Duval.
> http://www.scielo.org.co/scielo.php?script=sci_arttext&pid=S0121-11292014000100010
>
> (No se pudo descargar el texto completo — el sitio devuelve 403 al fetch
> automático. Si se consigue el PDF, agregar acá las tablas/figuras puntuales.)

## Regla maestra (de las notas oficiales del Excel de Duval)

Extraída textual de las hojas "Warning" del Excel oficial
(`DuvalTriangles_oficial_sin-proteccion.xls`). **Esta es la fuente canónica** de
qué triángulo se usa, con qué gases y para qué caso. Nuestro sistema ya la cumple
(gating en `duval_zones.json` + `DuvalService`).

| Triángulo | Cuándo se usa | Gases | Aceite | Zonas |
|---|---|---|---|---|
| **T1** | SIEMPRE — DGA principal del tanque. | CH₄·C₂H₄·C₂H₂ | Mineral | PD·D1·D2·T1·T2·T3·DT |
| **T4** | Solo para REFINAR fallas que el T1 dio como **PD, T1 o T2** (baja temperatura / papel). **NO usar para D1, D2 ni T3.** | H₂·CH₄·C₂H₆ | Mineral | PD·S·C·O·ND |
| **T5** | Solo para REFINAR fallas **térmicas T2 o T3** del T1. **NO usar para D1, D2.** | CH₄·C₂H₄·C₂H₆ | Mineral | PD·S·C·O·T2·T3·ND |
| **T2** | CONMUTADORES (LTC) tipo compartimento. NO es el tanque. Para LTC de botella de vacío usar el T1. | CH₄·C₂H₄·C₂H₂ | Mineral | N·T2·T3·X1·X3·D1 |
| **T3** | Aceites **NO minerales** (silicona, ésteres FR3/BioTemp/Midel). | CH₄·C₂H₄·C₂H₂ | No mineral | igual al T1, cortes %C₂H₄ por aceite |

### Notas oficiales clave

- **T4 y T5 son complementarios del T1, no lo reemplazan**: primero se clasifica
  con T1, y según la zona resultante se entra (o no) a T4/T5 para más detalle.
- **"Si T4 y T5 no coinciden → probablemente hay una MEZCLA de fallas."** (nota
  textual del Excel).
- **Zona C**: la probabilidad de que haya carbonización de papel es ~80%, no 100%.
- **Zona PD de T4**: algunos aceites nuevos pueden dar stray gassing dentro de PD;
  verificar con ensayos de stray gassing en laboratorio.

### Cómo lo implementa nuestro sistema (gating)

En `duval_zones.json → visibility`:
- `T4_if_T1_in = [PD, T1, T2]` (idéntico a la nota oficial).
- `T5_if_T1_in = [T2, T3]`.

T1 siempre visible; T2 (LTC) y T3 (no mineral) se eligen por el caso/aceite, no
por la zona del T1. Todo es DATO editable — cero `if` clavado en el motor.

Detalle de coordenadas/clasificación: [`README.md`](README.md) y
[`pentagono-validacion.md`](pentagono-validacion.md).
