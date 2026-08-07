# Informe PDF y flujo de aprobación

← [Índice](00-INDICE.md) · Portal público: [COMPARTIR-REPORTES](../COMPARTIR-REPORTES.md)

## El informe consolidado (no rediseñar sin pedido explícito)

- Generación: `TransformerController::report()` + blade
  `business_management/transformers/pdf/report.blade.php` (dompdf).
- Los gráficos llegan del navegador como PNG (echarts `getDataURL`, instancias
  ocultas en `Show.vue`, contenedor `.tr-report-capture` de 720px); el resto se
  arma server-side. Con >10 muestras las tendencias van 1 por fila (misma regla
  que la pantalla).
- Tiene: carátula-dashboard (gauge del HI + veredicto por prueba + QR), límites
  normativos con celdas en rojo, tendencia POR GAS, Duval con veredicto por
  gráfico, Rogers/Doernenburg, metodología (norma desde el rule_set resuelto),
  secciones solo si la prueba tiene muestras, numeración dinámica.
- Verificación: código HMAC + QR → portal `/verify/{code}`
  (`ReportVerifyController`, valida contra audit `report_generated`).

### Trampas del blade

- Footer 100% canvas dompdf (`page_text`/`page_line`) — NO volver a
  `position:fixed`.
- NO usar `@php(...)` inline (el regex de Blade lo empareja mal); bloques
  `@php ... @endphp`.
- dompdf/Helvetica no tiene ≤ ≥ –: todo texto dinámico pasa por `$safe()`.

## Firmas

Bloque data-driven de N firmantes por workspace (tabla `report_signers`:
relation + cargo + usuario/externo + orden; gestor en `/workspace`). La IMAGEN
se estampa solo si el usuario tiene firma cargada Y activó auto-firma
(`users.auto_sign_reports`, consentimiento auditado); sin eso sale relación +
nombre + cargo. Cada emisión queda auditada (`report_generated`).

## Flujo de aprobación (modelo BATCH)

- Opt-in por tenant: `tenants.require_report_approval` (toggle en
  "Mi workspace"). Solo fuerza el flujo de solicitudes; NO controla firmas.
- Unidad = la SOLICITUD (`report_requests`) que agrupa N informes
  (`report_instances`, snapshot+códigos). Los firmantes (`report_approvals`)
  aprueban la solicitud UNA vez → se aprueban los N de golpe.
- Motor: `app/Services/Reports/ReportApprovalService.php`. Bandeja en
  `/approvals` (solo firmantes).
- Estados: in_review → approved | rejected. En revisión el PDF sale marcado
  "EN REVISIÓN" sin firmas.
- AUTO-COMPARTIR: si la solicitud nació de un "compartir", al emitirse se crea
  el `ReportShare` y se manda el enlace al cliente. La descarga del portal está
  gateada: con aprobación exigida solo sirve informes aprobados.
- **PDF CONGELADO (2026-07-16)**: al emitirse, el job `FreezeApprovedReports`
  renderiza cada informe UNA vez y lo guarda en `storage/app/frozen-reports/`
  (path + sha256 + fecha en `report_instances`). Las descargas (bandeja del
  solicitante y portal del cliente) sirven ese archivo exacto; el render en
  vivo queda solo como fallback en la ventana de segundos hasta que el job
  corre (requiere `queue:work`). El `snapshot` guarda además el ARRAY de
  muestras que respaldaron la firma (auditoría). Retención del archivo:
  setting `reports.frozen_retention_years` (default 2; 0 = nunca) — la purga
  diaria (`reports:purge-frozen`) borra solo el archivo y conserva snapshot y
  hash. Capturas de gráficos a pixelRatio 1.5 (~mitad de peso por PDF).

## Compartir y auditoría

- Cada envío se audita (`report_shared` en el log del trafo, con destinatario,
  alcance y vencimiento).
- El portal reutiliza los gráficos reales cacheados (`ReportChartCache`); el
  Show los repuebla en background si el caché está viejo (`chartCacheStale`).
