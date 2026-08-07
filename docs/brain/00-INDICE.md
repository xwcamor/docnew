# TR APP — Segundo cerebro del proyecto

> **Cómo usar esto**: abre la carpeta del repo (o `docs/`) como vault en
> [Obsidian](https://obsidian.md) — no requiere plugins ni configuración. Estas
> notas son el mapa; el detalle vive en los documentos enlazados. Se versiona
> con git como el resto del código.
>
> **Regla de mantenimiento**: cada decisión o cambio grande se refleja aquí (o
> en el doc enlazado que corresponda) en la misma sesión en que se hace.
> `CLAUDE.md` (raíz del repo, fuera de este vault) es la memoria de trabajo
> del agente (estado + pendientes al día); estas notas son el conocimiento estable.

---

## Mapa

### El dominio: diagnóstico de transformadores
- [Motor de diagnóstico](motor-diagnostico.md) — el corazón: reglas en datos,
  normas, servicios, semáforos, índice de salud.
- [Procedencia de las reglas](../origen-ruby/README.md) — de dónde salió cada
  número (Ruby viejo, Excel de Duval, papers, normas IEEE/IEC).
- [Informe PDF y aprobaciones](informes-y-aprobaciones.md) — el informe
  consolidado, firmas, flujo de aprobación batch, compartir.
- [API del laboratorio](../API-LABORATORIO.md) — por dónde entran los
  resultados de ensayo: endpoints, idempotencia, los desajustes de modelo con
  el sistema de laboratorio y qué se decidió en cada uno.

### El SaaS base
- [Módulos y scaffold](modulos-scaffold.md) — cómo se crea un módulo, qué
  automatiza `make:module`, el patrón Customer, multi-tenancy.
- [Convenciones de UI](ui-convenciones.md) — franjas, barras, tooltips,
  tipografía, dark theme, esquemas de color. Lo que se sigue en módulos nuevos.

### Operación
- [Deploy y post-deploy](operacion-deploy.md) — el runbook del droplet y el
  checklist legal (LPDP/ANPD).
- [Seguridad y secretos](../SECURITY.md) · [Variables de entorno](../ENV.md) ·
  [Troubleshooting](../TROUBLESHOOTING.md)

### Estado y decisiones
- **[Backlog y decisiones](backlog-decisiones.md) — LA lista de lo que falta.**
  Decisiones cerradas (no re-litigar) y todo lo abierto en un solo lugar:
  lo que espera al laboratorio, lo que hay que debatir, el trabajo
  identificado, el pulido visual y el deploy con su checklist legal. Si la
  pregunta es "¿qué queda?", se responde acá y en ningún otro archivo.

### Referencia rápida existente (docs/)
- [STRUCTURE](../STRUCTURE.md) — arquitectura de carpetas
- [USAGE](../USAGE.md) — uso general
- [CREATE-MODULE](../CREATE-MODULE.md) — scaffold en detalle
- [PERMISSIONS](../PERMISSIONS.md) — roles y permisos
- [AUTOMATIONS](../AUTOMATIONS.md) — automatizaciones
- [COMPARTIR-REPORTES](../COMPARTIR-REPORTES.md) — portal público
- [CRONS-AND-SETTINGS](../CRONS-AND-SETTINGS.md) — tareas programadas
- [MANUAL-CLIENTE](../MANUAL-CLIENTE.md) — manual de usuario final
- [PACKAGES](../PACKAGES.md) · [SENTRY](../SENTRY.md) · [DEPLOY](../DEPLOY.md)
