# Decisiones de arquitectura

Documento que explica **por qué** se eligió cada tecnología en este proyecto.
Cuando dudes en 6 meses por qué algo está hecho así, ven aquí primero.

> Cada decisión es **revisable**. Si la realidad cambia, se actualiza el documento y se adapta el código.

---

## 1. Backend: Laravel 13 + PHP 8.3

**Por qué Laravel**:
- Framework PHP más maduro y con mayor ecosistema (Spatie, Sanctum, Horizon, Telescope, Octane, etc.).
- Curva conocida en el equipo.
- Comunidad enorme — cualquier problema ya está resuelto en algún sitio.

**Por qué PHP 8.3**:
- Última versión estable. JIT, tipado robusto, atributos, readonly classes.
- Soporte hasta noviembre 2027.

**Cuándo revisar**: si el proyecto evoluciona a microservicios con high-throughput específico (ej: streaming en tiempo real), considerar Node/Go/Elixir para esos servicios concretos. Laravel sigue siendo válido para el core.

---

## 2. Base de datos: PostgreSQL 16

**Por qué PostgreSQL y NO MySQL**:

| Razón | Detalle |
|---|---|
| **`unaccent` extension** | Búsquedas case + accent insensitive (`"Río"` matchea `"Rio"`). Crítico para nombres de personas/empresas. En MySQL solo es case-insensitive. La extensión se activa con `CREATE EXTENSION IF NOT EXISTS unaccent;` al crear la BD — paso obligatorio del setup. |
| **Partial unique indexes** | `CREATE UNIQUE INDEX ... WHERE deleted_at IS NULL` permite re-crear un registro con el mismo nombre tras un soft-delete. En MySQL queda UNIQUE total. |
| **`varchar_pattern_ops`** | Pattern index para `LIKE 'X%'` eficiente en queries de auto-complete. |
| **JSONB con índices GIN** | Custom fields del proyecto van en columnas JSONB. PostgreSQL puede indexar todo el JSON con una línea (`CREATE INDEX ... USING GIN(metadata)`). En MySQL hay que crear "generated columns" + index para cada campo individual — más trabajo, menos flexible. |
| **Reportes complejos** | Window functions y CTEs recursivas son más rápidas y maduras en PostgreSQL. Para el report builder futuro es ventaja. |
| **Full-text search nativo** | `tsvector + GIN` con ranking y soporte multi-idioma. MySQL `FULLTEXT` es más rígido. |
| **Tipos avanzados** | Arrays, ranges, UUID nativo, ENUM. Útil para datos no estándar. |
| **Concurrent index creation** | Crear índices sin bloquear la tabla en producción. |

**Trade-off**: la curva de aprendizaje es ligeramente mayor para alguien que viene de MySQL. Sintaxis de comillas dobles en lugar de backticks, GRANTs más explícitos en PG 15+.

**Cuándo revisar**: nunca, salvo que aparezca un requisito de hosting que solo soporte MySQL.

---

## 3. Frontend: Inertia.js + Vue 3 (NO Next.js separado)

**Las opciones evaluadas**:

| Opción | Pros | Contras | Veredicto |
|---|---|---|---|
| **Blade + AdminLTE** (lo que había) | Familiar, simple | Look anticuado, lento, jQuery 2010-style | ❌ Rechazado |
| **Filament** | UI hermosa, 0 JS, panel admin auto-generado | Te ata al patrón "Resource = CRUD". Limita custom fields y reportes | ❌ Rechazado |
| **Inertia + Vue 3** | UNA sola app, sin CORS, sin tokens en frontend, mantiene Laravel auth/permissions | Menos "puro" que un SPA real | ✅ **Elegido** |
| **API + Next.js separado** | Máxima escalabilidad, varios frontends posibles | 2 deploys, CORS, JWT, +400MB RAM solo para SSR | ⚠️ A futuro si hace falta |

**Por qué Inertia + Vue 3 ganó**:
- **1 sola app, 1 deploy, 1 dominio** — encaja en el VPS de 2GB RAM previsto.
- **Sin reescribir auth ni permisos** — los `Gate::allows()` y middleware de Laravel siguen funcionando.
- **Sin CORS, sin tokens, sin manejar auth en JS** — la sesión de Laravel hace todo.
- **Look idéntico a un Next.js** — mismo Tailwind + componentes Ant Design + AG Grid.
- **Path de migración a SPA real**: si en el futuro necesitas app móvil o un portal cliente separado, abres `routes/api.php` con Sanctum tokens encima de la misma app, sin reescribir.

**Por qué Vue 3 y no React**:
- Curva más suave para alguien que viene de Blade.
- `v-model`, `<script setup>`, single-file components → menos boilerplate que React.
- Ant Design Vue es estable y tiene la misma calidad que la versión React.

**Cuándo revisar**: si llega un equipo grande con experiencia React, o necesitas múltiples frontends consumiendo la misma API, considerar separar en API + Next.js.

---

## 4. UI library: Ant Design Vue (NO shadcn-vue ni Vuetify)

**Por qué Ant Design Vue**:
- Look "enterprise" (tipo SAP, Bloomberg, Salesforce) que el cliente esperará.
- Componentes pulidos, completos: forms con validación, tables, modals, datepickers, uploaders, etc.
- 100% gratis, MIT license, sin features pagos ocultos.
- Compatible con Tailwind 4 (con cuidado en el orden de imports).

**Alternativas evaluadas**:

| Lib | Por qué no |
|---|---|
| shadcn-vue | Look más "consumer SaaS" tipo Linear/Vercel. Menos enterprise. |
| Vuetify | Material Design — look Google/Android. No empresarial. |
| PrimeVue | Excelente, alternativa válida. Ant Design Vue es ligeramente más simple y la docs en español son mejores. |
| Element Plus | Bueno pero menos comunidad. Ant Design Vue gana por adopción. |

**Cuándo revisar**: si más adelante el look no encaja con el branding del cliente, PrimeVue es el siguiente candidato sin reescribir mucho.

---

## 5. Tablas: AG Grid Community (NO TanStack Table)

**Por qué AG Grid**:
- La tabla más usada en apps enterprise del planeta (SAP, Salesforce, NetSuite).
- Virtualización built-in: 10M filas sin lag.
- Filtros, sorting, agrupación, edición inline, export a CSV — todo en la versión Community gratis.
- Look "tipo Excel" inmediato (theme Quartz).
- Aprender una sola librería sirve para toda la app.

**Trade-off**:
- AG Grid Enterprise (~$1000/dev/año) tiene pivot tables y master/detail. **NO la necesitamos por ahora**. Si alguna vez se necesita, hay alternativas gratis (PrimeVue DataTable con pivots, Tabulator).
- Bundle relativamente grande (~200KB gzipped). Aceptable para un admin profesional.

**Alternativa evaluada**: TanStack Table — más liviano y flexible, pero requiere construir el render desde cero. Más trabajo que beneficio para nuestro caso.

---

## 6. Auth: Sanctum + Spatie Permission

**Por qué Sanctum**:
- Solución oficial de Laravel para tokens API.
- Soporta tokens con **abilities** (granularidad por capacidad).
- Funciona junto con la auth de sesión normal de Laravel — no hay que elegir.
- Path natural cuando agregues app móvil o integraciones de terceros.

**Por qué Spatie Permission**:
- Estándar de facto en el ecosistema Laravel.
- Soporta roles + permissions individuales + teams (multi-tenant).
- API limpia: `$user->can('permission')`, `@can('permission')`, etc.
- Se integra con `Gate::before` para super-admins.

**Decisión propia**: usar la tabla `system_modules` con `permission_key` para gestionar permisos de forma **declarativa**. Cuando se crea un módulo nuevo, se generan automáticamente sus 4 permisos (`index`, `create`, `update`, `destroy`). Esto evita que el desarrollador olvide registrar permisos manualmente.

Ver [`PERMISSIONS.md`](PERMISSIONS.md) para el detalle.

---

## 7. Build tool: Vite (no Webpack/Mix)

**Por qué Vite**:
- HMR ultra rápido (~50ms) en desarrollo.
- Builds de producción optimizados con tree-shaking automático.
- Es el default moderno de Laravel desde la versión 10.
- Soporta Vue 3 + TypeScript + Tailwind nativamente.

**Trade-off**: ya no es necesario, Vite es mainstream.

---

## 8. CSS: Tailwind 4 + componentes propios

**Por qué Tailwind 4**:
- Utility-first → menos CSS custom, menos archivos sueltos, menos peleas con cascade.
- Tailwind 4 introduce CSS-first config (sin `tailwind.config.js`, todo en CSS con `@theme`).
- Builds increíblemente rápidos (al final solo el CSS usado).

**Convivencia con Ant Design Vue**:
- Importar el reset de Ant Design **antes** de Tailwind para que las utility classes ganen.
- Ant Design Vue maneja sus propios estilos internamente; Tailwind se usa para layouts y customizaciones encima.

---

## 9. Multi-tenancy: tenant_id por columna

**Estrategia elegida**: **multi-tenancy lógico** — todas las tablas relevantes tienen `tenant_id` y se filtra en queries (vía global scopes de Eloquent).

**Alternativas evaluadas**:

| Estrategia | Pros | Contras | Veredicto |
|---|---|---|---|
| **Una BD por tenant** | Aislamiento perfecto | Costoso, difícil de mantener migraciones, no escala | ❌ |
| **Un schema PG por tenant** | Aislamiento bueno, mismo servidor | Migraciones complejas, JOINs cross-tenant difíciles | ❌ |
| **`tenant_id` en cada tabla** | Simple, escalable, queries simples con global scopes | Confianza en que los scopes nunca fallan (riesgo de leaks) | ✅ |

**Mitigación del riesgo**: tests automatizados (cuando los tengamos) que verifiquen que ningún query devuelva datos de otros tenants.

---

## 10. Storage: local en `storage/app/public` (por ahora)

**Por qué local y no S3/Spaces inicialmente**:
- Plan inicial: VPS de DigitalOcean 2GB ($12/mes). Sin servicios extra.
- Spaces costaría +$5/mes y agregaría latencia de red.
- Laravel filesystem es agnóstico: cuando llegue el momento, **una línea en `.env`** (`FILESYSTEM_DISK=spaces`) cambia todo el storage sin tocar código.

**Cuándo migrar a Spaces/S3**:
- Cuando el storage local supere ~30GB (SSD del Droplet se llena).
- Cuando haya múltiples Droplets balanceados (no se puede compartir disco local).
- Cuando se necesite CDN para archivos (videos, imágenes pesadas).

---

## 11. Localización: mcamara/laravel-localization

**Por qué este paquete y no `Lang::` solo**:
- Genera URLs prefijadas por idioma (`/es/login`, `/en/login`) — bueno para SEO.
- Redirección automática según `Accept-Language` del navegador.
- Switching de idioma sin perder la URL actual.

**Idiomas soportados**: español e inglés (`resources/lang/{es,en}/`).

---

## 12. Comando `setup:project`: drop+migrate+seed con guard

**Decisión**: tener un comando único que regenera la BD desde cero para desarrollo.

**Razón**:
- En desarrollo es común olvidar migraciones o cambiar seeders → recrear la BD es lo más limpio.
- Más rápido que recordar la combinación de `migrate:fresh --seed`.

**Guardia integrado**: si `APP_ENV=production`, el comando se rehúsa a correr. Protección de día 1, no después de un accidente.

---

## 13. Scaffold `make:module`: Customer como master template

**Decisión**: tener un comando que clone el módulo Customers entero (back + front + tests + config + i18n) hacia un módulo nuevo, con find-replace de identificadores.

**Por qué Customer es el master**:
- Tiene `BelongsToTenant` trait → cada workspace ve solo sus registros.
- Rutas con `permission:X.action` por acción (granular).
- `tenant_id` nullable + scope automático con super bypass.
- Cubre toda la infraestructura del sistema: audit log polimórfico, soft-delete + trash + restore + force-delete, bulk ops auto-async, exports (CSV/Excel/PDF/Word), imports con preview/commit, favoritos polimórficos, recent items, saved views, column selector, plan gating.

Clonar Customer garantiza que el módulo nuevo herede todas estas capacidades sin tener que reimplementarlas.

**Uso**:
```bash
php artisan make:module Product --group=BusinessManagement
```

Genera ~51 archivos con 2 campos base (`name` + `description`). Las columnas custom del dominio (price, stock, FKs) se agregan a mano post-scaffold editando la migration.

**Lo que el scaffold SÍ automatiza**:
- Routes append a `routes/business_management.php`
- Registro en `config/polymorphic.php` + `config/purge.php`
- Fila en tabla `system_modules` con `permission_key`

**Lo que NO automatiza (manual post-scaffold)**:
- Entrada en sidebar (`AppLayout.vue` + `lang/sidebar.php`)
- Permisos en `RolesAndPermissionsSeeder`
- Plan features específicos en `config/features.php`

Detalle completo en [README-DEV.md](../README-DEV.md#3-crear-módulos-nuevos-con-el-scaffold).

---

## Decisiones que se DEJARON pasar (deuda técnica consciente)

Cosas que sabemos que no son ideales pero no son urgentes:

| Tema | Por qué pasamos | Cuándo abordar |
|---|---|---|
| CI/CD | Solo, sin equipo, deploy manual es más rápido | Cuando entre el primer dev al equipo |
| Logs centralizados | `storage/logs/laravel.log` alcanza con 1 servidor | Cuando haya 2+ Droplets |
| Monitoreo (Sentry, etc.) | Sin clientes en producción aún. `.env.example` ya tiene claves listas, falta integrar el SDK | Antes del primer go-live con clientes |
| API REST en módulos de negocio | Hoy solo hay un módulo de catálogo expuesto vía API como patrón de referencia. Replicar en otros cuando se necesite | Cuando haya app móvil o integración con terceros |
| Login rate-limiting | Settings `security.max_login_attempts` y `security.lockout_minutes` ya sembrados pero sin wire-up | Antes del primer go-live público |

---

## Cómo agregar una nueva decisión a este documento

Cuando tomes una decisión técnica importante:

1. Agrega una sección numerada nueva.
2. **Por qué se eligió** (lista de razones concretas).
3. **Trade-offs** (qué se sacrifica).
4. **Cuándo revisar** (escenario que invalidaría la decisión).
5. Si reemplaza una decisión anterior, marca la antigua como `[REVISADO en sección N]` en lugar de borrarla — el historial es valioso.

> "Una buena documentación de arquitectura no es la que dice qué hace cada componente —
> es la que explica **por qué** existe."

---

## Documentación relacionada

- [`../README.md`](../README.md) — portada general del sistema
- [`PACKAGES.md`](PACKAGES.md) — inventario de librerías que materializan las decisiones de aquí
- [`STRUCTURE.md`](STRUCTURE.md) — cómo se organiza el código bajo estas decisiones
- [`PERMISSIONS.md`](PERMISSIONS.md) — cómo se implementa el modelo de acceso multi-rol
- [`plan-features.md`](plan-features.md) — cómo se gatean las features por plan
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — cómo se aplica el patrón Customer al crear módulos nuevos
