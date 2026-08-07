# Operación y deploy

← [Índice](00-INDICE.md) · Runbook técnico: [DEPLOY](../DEPLOY.md) ·
[SECURITY](../SECURITY.md) · [BD blindada en el droplet](../DROPLET-POSTGRES-SECURITY.md) ·
[ENV](../ENV.md) · [TROUBLESHOOTING](../TROUBLESHOOTING.md)

## Stack de runtime

- Laravel 13 + PHP 8.3 + PostgreSQL 16 (extensión `unaccent`)
- Queue `database` (SIN Redis — decisión consciente) · Storage `local` (SIN S3)
- Prod: droplet de Digital Ocean — **todavía no configurado**
- Dev: Windows + Laragon. El usuario solo corre: `php artisan serve`,
  `npm run dev/build`, `php artisan queue:work`.

## Plan de migración local → producción (acordado con el dueño)

1. **En LOCAL**: reconstruir la BD con los datos del sistema viejo cuantas
   veces haga falta, hasta que quede probada:
   ```bash
   php artisan setup:project
   ```
   (Equivale a `migrate:fresh --seed` + verificación de que las reglas de
   diagnóstico quedaron cargadas.) Lo hace TODO: migraciones + catálogos +
   reglas + dumps legacy (`Legacy*Seeder` desde
   `database/seeders/data/*_legacy.sql`) + dedup de muestras + recálculo de
   salud (`RecalculateTransformerHealthSeeder` corre al final del
   `DatabaseSeeder`). No hace falta `diagnose:fleet-cache` después de un
   fresh (ya queda cacheado). Documentado en `README-DEV.md` §1.5.
2. **Actualizar SIN reconstruir** (BD viva, solo cambiaron reglas/pesos):
   ```bash
   php artisan db:seed --class=DiagnosticCatalogSeeder
   php artisan diagnose:fleet-cache
   ```
3. **Subir a producción**: cuando lo local esté probado, la BD viaja completa
   al droplet con `pg_dump` local → `pg_restore`/`psql` en el droplet (una
   sola vez). Alternativa: correr `migrate --force` + `db:seed --force` allá
   (los seeders son idempotentes y no pisan valores editados).
4. **En PRODUCCIÓN**: los comandos destructivos quedan BLOQUEADOS (ver abajo).

## Candado de producción (implementado 2026-07-26)

`AppServiceProvider::boot()` llama
`DB::prohibitDestructiveCommands(app()->isProduction())`:

- Con `APP_ENV=production`, Laravel **rechaza** `migrate:fresh`,
  `migrate:refresh`, `migrate:reset` y `db:wipe` — incluso con `--force`.
  Sale "This command is prohibited from running in this environment."
- `setup:project` tiene ADEMÁS su propio guard (se reactivó — estaba
  comentado): en producción se niega antes de tocar nada, incluido el
  `DROP DATABASE` crudo de MySQL que no pasa por artisan.
- En local/dev no cambia nada (ahí sí se reconstruye libremente).
- `migrate` normal (agregar tablas/columnas) SÍ funciona en prod — solo se
  bloquea lo que borra datos.
- Complemento obligatorio: **backups del droplet** (snapshot de DO o
  `pg_dump` en cron) — el candado evita el dedazo, no el desastre físico.

## Checklist post-deploy (cuando el droplet esté)

1. **`php artisan diagnose:fleet-cache`** una vez — recachea `ieee_condition`
   (ahora DGA Status 2019) y los campos de flota de todos los trafos.
2. **Legal / LPDP (Perú)** — lo técnico ya está implementado (aceptación
   versionada con registro+IP, aviso en portal, derechos ARCO en Mi perfil).
   Falta lo administrativo:
   - Redacción REAL de Términos y Política de Privacidad por abogado de
     protección de datos. Al reemplazar el texto: subir el setting
     `legal.terms_version` para forzar re-aceptación de todos.
   - Registro del banco de datos ante la ANPD (MINJUS): bancos "usuarios del
     sistema" y "destinatarios de informes compartidos".
3. Revisar [SECURITY](../SECURITY.md) (checklist de deploy, `.env`, `APP_KEY`).

## Comandos útiles

```bash
php artisan diagnose:cromas {id}     # diagnóstico de un trafo por consola
php artisan verify:legacy            # paridad vs el sistema viejo (cerrada)
php artisan diagnose:fleet-cache     # backfill del caché de flota
php artisan make:module {Name}       # scaffold de módulo
```

Tests:
```bash
php artisan test
```

## Monitoreo

[SENTRY](../SENTRY.md) para errores. Crons y settings globales:
[CRONS-AND-SETTINGS](../CRONS-AND-SETTINGS.md).
