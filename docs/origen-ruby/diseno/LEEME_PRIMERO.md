# TR APP — Contexto de diseño para Claude Code

El código del motor de cromatografía YA está instalado en el proyecto
(migraciones, modelos, seeders, ChromatographyEngine). Este paquete contiene
solo el CONTEXTO y el DISEÑO, para que Claude Code entienda el proyecto y
continúe la construcción.

## CÓMO USARLO

1. Copia `CLAUDE.md` a la RAÍZ de tu proyecto (Claude Code lo lee automáticamente).
2. Copia la carpeta `docs/` a la raíz de tu proyecto.
3. En Claude Code, di:
   "Lee el CLAUDE.md y los documentos en docs/diseno. Resúmeme el proyecto y el
    estado actual. El motor de cromatografía ya está construido; ayúdame a
    continuar con lo pendiente."

## QUÉ CONTIENE

- CLAUDE.md  -> resumen del proyecto, principio rector, arquitectura, estado.
- docs/diseno/
    - TR_APP_Arquitectura_Tecnica.docx  -> BD, escalabilidad, normas, pruebas.
    - TR_APP_Catalogo_Maestro_v2.xlsx   -> el detalle completo (198 reglas, normas,
                                           IEEE Tabla 4, factor de potencia, índice
                                           de salud, 20 criterios de Hitachi).
    - TR_APP_Decisiones_Diseno.docx     -> decisiones fijadas.
    - TR_APP_Plan_Migracion.docx        -> mapeo tablas viejas -> nuevas.
    - TR_APP_Catalogo_Condiciones.xlsx  -> extracción cruda del código viejo.

## ESTADO

HECHO: cromatografía completa (ya instalada en tu proyecto).
PENDIENTE:
1. Verificar diagnósticos contra el sistema viejo en trafos reales.
2. Demás pruebas (fiquis, furanos, factor de potencia) reusando el patrón.
   Generalizar ChromatographyEngine -> DiagnosticEngine por código de prueba.
3. Índice de salud combinado (Hitachi, peso dinámico).
4. Interfaz de edición agrupada de reglas.
5. Más adelante: Duval, migración de datos, reportes.

## PRINCIPIO QUE NO SE ROMPE
Todo lo que cambia (umbrales, pesos, aceites, normas, criterios) vive en DATOS,
nunca en código. El código solo tiene fórmulas.
