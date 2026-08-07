# Migración de datos y control del corte

Origen: `doc_app_development` (MySQL 8, Rails). Destino: `docapp` (PostgreSQL 16, Laravel).
Cifras del volcado del 06-08-2026.

## Cómo se controla

1. **Un comando por paso**, idempotente: `php artisan docapp:migrate-legacy {paso}`.
2. **Trazabilidad**: cada fila migrada guarda `legacy_id` (y `legacy_table` donde puede venir de
   varias), así siempre se puede volver al origen.
3. **Conteos antes y después** de cada paso, guardados en `storage/app/migracion/{paso}.json`.
   Si un conteo no cuadra, el paso falla y no continúa.
4. **La v1 solo se lee.** Ningún script escribe en la base vieja.
5. **Ensayos completos** antes del corte: la migración se corre entera sobre una copia, se revisa y
   se descarta. Se repite hasta que salga limpia dos veces seguidas.
6. **Corte**: se congela la v1 (solo lectura), se corre la migración final, se verifica el checklist
   de corte y se abre la v2. La v1 queda accesible en solo lectura durante 3 meses.

## Orden de los pasos

| # | Origen → destino | Filas | Riesgo |
| --- | --- | ---: | --- |
| 1 | `countries`, `languages`, `settings` | 21 | bajo |
| 2 | catálogos (`positions`, `nationalities`, `areas`, `locations`, `workstations`, `work_types`) | ~120 | bajo — las columnas `name_es/pt/en` pasan a los archivos de idioma |
| 3 | `companies` | 22 | bajo |
| 4 | `users` + `user_details` | 26 | bajo — contraseñas se regeneran, no se migran hashes |
| 5 | `workers` + `supervisors` + `hse_supervisors` → `people` | 421 → ~235 | **alto, requiere decisión manual** |
| 6 | `workers` → `person_company_links` | 391 | medio |
| 7 | firmas → `person_signatures` | 388 | bajo |
| 8 | `plans` → `work_plans` | 3 722 | medio |
| 9 | `plan_workers` → `work_plan_people` | 9 186 | medio |
| 10 | `plan_approvals` → `work_plan_approvals` | 11 166 | medio — 2 filas tienen aprobador huérfano (Supervisor 7 y 32) |
| 11 | `worker_signature_events` → `signature_events` | 9 059 | bajo |
| 12 | marcadores `detected_by_IA`/`signed_by_IA` | 30 699 | medio |
| 13 | archivos de imagen reales | 4 189 refs / 4 034 archivos | medio |
| 14 | `f1..f4_documents` → `form_submissions` | 14 435 | **alto** |

## El paso 5, que es el delicado

La misma persona está repartida en tres tablas y repetida por empresa:

- 391 filas de `workers` para **231 documentos distintos**
- 95 personas aparecen en 2 a 5 empresas
- 21 documentos coinciden entre `workers` y `supervisors`, 4 con `hse_supervisors`
- 1 duplicado exacto: `47019239`, ids 10 y 217, misma empresa

El script agrupa por `(país, tipo de documento, número)` y genera un informe de conflictos cuando
los nombres no coinciden exactamente. **Esos conflictos se resuelven a mano antes de continuar**;
el script no adivina.

## El paso 12, que es el incómodo

30 699 referencias no son archivos: son las cadenas `detected_by_IA` y `signed_by_IA` que la v1
escribía en lugar de la foto. Se migran como `signature_events` con `method: 'migrated'` y
`evidence_missing: true`.

No se inventa evidencia que no existe. Que 4 de cada 5 firmas históricas no tengan foto es un dato
del sistema viejo, y hay que poder consultarlo tal cual.

## Checklist de corte

- [ ] Los conteos de los 14 pasos cuadran con el origen
- [ ] Cero registros huérfanos (`people`, `work_plans`, `form_submissions`, `signature_events`)
- [ ] Los 4 034 archivos de imagen existen en el almacenamiento nuevo y su `sha256` coincide
- [ ] Los PDF de 20 planes elegidos al azar salen iguales que en la v1
- [ ] Las 548 aprobaciones obligatorias pendientes siguen marcadas como pendientes
- [ ] Todos los usuarios pueden entrar y ven solo lo suyo
- [ ] La v1 queda en modo lectura y con aviso visible
