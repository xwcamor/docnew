# Migracion de datos desde la v1

Origen: base `doc_app_development` de `app_documentation` (MySQL 8). Cifras del volcado del
06-08-2026.

## Orden

| # | Origen → destino | Filas | Notas |
| --- | --- | ---: | --- |
| 1 | `languages`, `countries`, `settings` | 7 + 7 + 7 | `settings` gana `theme_scheme`, `face_threshold` y `face_timeout_seconds` |
| 2 | catalogos con `name_es/pt/en` → tabla + `translated_texts` | 16 tablas | tres filas de traduccion por registro |
| 3 | `companies`, `areas`, `locations`, `workstations`, `positions`, `jobs`, `work_types` | ~120 | |
| 4 | `users` + `user_details` → `users` | 26 | los datos personales se fusionan en la misma tabla |
| 5 | `workers` + `supervisors` + `hse_supervisors` → `people` | 421 → ~235 | **requiere decision manual**: 21 documentos coinciden entre `workers` y `supervisors`, 4 con `hse_supervisors`, 1 con `supervisors`+`hse_supervisors`, y hay 1 duplicado exacto (`47019239`) |
| 6 | `workers` → `person_company_links` | 391 | 95 personas quedan con 2 a 5 vinculos |
| 7 | firmas de `workers`/`supervisors` → `person_signatures` | 388 | `source: migrated` |
| 8 | `plans` | 3 722 | |
| 9 | `plan_workers` → `plan_people` | 9 186 | sin columnas de foto ni firma |
| 10 | `plan_approvals` | 11 166 | 2 filas tienen `approver_id` huerfano (Supervisor 7 y 32): se resuelven o se descartan |
| 11 | `worker_signature_events` → `signature_events` | 9 059 | `method: migrated` |
| 12 | marcadores `detected_by_IA`/`signed_by_IA` → eventos | 30 699 | `evidence_missing: true`. **No se inventa evidencia que no existe** |
| 13 | archivos reales de imagen | 4 189 refs / 4 034 archivos | se calcula `sha256` y se deduplica |
| 14 | `f1..f4_documents` | 14 435 documentos | ver abajo |

## Formatos

EPP (F3) e IHM (F4) se convierten en plantillas del motor con campos `person_checklist` y
`tool_checklist`. AST (F1) y PTF (F2) conservan sus tablas nativas en esta etapa y se exponen como
tipos de campo; migrarlos se decide despues, con datos de rendimiento.

## Reglas

1. Cada paso es un script idempotente que se puede volver a correr.
2. Se conserva el `id` de origen en una columna `legacy_id` para poder auditar.
3. Antes y despues de cada paso se comparan conteos y se guarda el resultado.
4. Nada se borra en la v1: la migracion solo lee.
5. La v1 sigue en produccion hasta que la v2 cubra el flujo completo del dia.
