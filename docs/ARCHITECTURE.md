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
| **JSON con índices GIN** | El motor de formatos guarda en JSON lo que no cabe en una columna: la configuración de cada campo (`form_fields.config`), su regla de visibilidad, y las respuestas compuestas como la matriz de riesgo (`form_answers.value_json`). PostgreSQL indexa todo el JSON con una línea; en MySQL hay que crear una columna generada e indexarla campo por campo. |
| **Consultas de informe** | Window functions y CTEs recursivas son más rápidas y maduras en PostgreSQL. Cuenta para los listados agregados de planes y firmas por periodo. |
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

**Decisión propia**: usar la tabla `system_modules` con `permission_key` para gestionar permisos de forma **declarativa**. Al insertar la fila de un módulo, `SystemModuleObserver` crea sus **7 permisos canónicos** (`view`, `show`, `create`, `edit`, `delete`, `export`, `import`). Así el desarrollador no puede olvidarse de registrarlos: registrar el módulo *es* registrar los permisos.

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

## 10. Storage: disco local (por ahora), y las evidencias en disco privado

**Por qué local y no S3/Spaces inicialmente**:
- Plan inicial: un Droplet de DigitalOcean. Sin servicios extra.
- Spaces costaría más y agregaría latencia de red.
- El filesystem de Laravel es agnóstico: cuando llegue el momento, una línea en `.env` (`FILESYSTEM_DISK=spaces`) cambia el destino sin tocar código.

**Lo que no es negociable**: las **fotos de firma nunca van al disco público**. Se sirven autenticadas y solo a quien tiene `signature_events.review`, y dentro del PDF se incrustan como data-uri leyéndolas del disco, no por URL. Una cara no puede quedar detrás de un enlace que se pueda reenviar.

**Cuándo migrar a Spaces/S3**: en cuanto las evidencias empiecen a acumularse de verdad. Es lo único que crece solo, y en el disco de un droplet pequeño eso termina tumbando la aplicación. Los números están en [`BIOMETRIA.md`](BIOMETRIA.md). También hace falta si algún día hay más de un servidor: el disco local no se comparte.

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

## 13. Scaffold `make:module`: Brand como master template

**Decisión**: tener un comando que clone un módulo entero (back + front + tests + config + i18n) hacia uno nuevo, con find-replace de identificadores.

**Por qué `Brand` y no `Customer`**: Customer fue el primer patrón, pero acumuló dominio propio — código de cliente, jerarquía de ubicaciones, restricción por cartera asignada, capa de API. Clonarlo obligaba a *quitar* cosas en cada módulo nuevo. `Brand` es un master **limpio**: `name` + `code` + `is_active` + `sort_order`, con `BelongsToTenantOrGlobal` y `Lockable`, y nada más. El comando rechaza generar un módulo llamado `Brand` o `Customer`.

Por eso `Brand` sigue en el repositorio pese a no ser un módulo del dominio de DOCUFIZ: borrarlo deja el generador inservible. Ver `docs/PURGA.md`.

Lo que se hereda al clonar: audit log polimórfico, soft-delete con papelera, restaurar y borrado definitivo, masivas con paso a cola automático, exportaciones (CSV/Excel/PDF/Word), importación con vista previa, favoritos, vistas recientes, vistas guardadas, selector de columnas y gateo por plan.

**Uso**:
```bash
php artisan make:module WorkType --group=BusinessManagement --fields="code:string?, sort_order:integer"
```

Genera del orden de 50 archivos. Con `--fields` los campos del dominio se inyectan en la migración, el modelo, los FormRequests, la factory, las traducciones, `Form.vue`, `Show.vue` y `columns.js`; sin `--fields`, el módulo arranca solo con `name` y se completa a mano.

**Lo que el scaffold SÍ automatiza**:
- Añade el bloque de rutas a `routes/{group}.php`
- Registro en `config/polymorphic.php` + `config/purge.php`
- Fila en `system_modules` — y de ahí salen los 7 permisos, vía observer

**Lo que NO automatiza (manual después)**:
- Entrada en el sidebar (`AppLayout.vue` + `lang/{es,en}/sidebar.php`)
- Asignar los permisos nuevos a los perfiles en `RolesAndPermissionsSeeder`
- Plan features específicos, si el módulo es de pago

Detalle completo en [`CREATE-MODULE.md`](CREATE-MODULE.md).

---

## 14. Reconocimiento facial: `face-api.js` en el navegador, decisión en el servidor

**Decisión**: la cámara y el cálculo del descriptor viven en el navegador con `@vladmandic/face-api`; **la comparación que decide vive en el servidor**.

**Por qué en el navegador**: el vídeo no sale del dispositivo. Lo que viaja es un vector de 128 números y una foto, no un flujo de cámara. Además no hace falta ni GPU ni servicio externo: los tres modelos (~7 MB) están versionados en `public/models/` y no se descargan en tiempo de ejecución.

**Por qué la decisión en el servidor**: en el sistema anterior el navegador calculaba y mandaba `is_approved=1` en un campo oculto. Bastaba abrir las herramientas de desarrollo para firmar como cualquiera. Ahora el navegador manda el descriptor y la foto; el servidor recalcula la distancia contra la biometría enrolada, guarda `match_distance` y `threshold_used`, y decide. El navegador solo sirve para dar retroalimentación en vivo.

**Trade-off**: verificación **1:1**, no 1:N. La persona escribe su documento y el servidor devuelve solo los descriptores de esa persona. Es menos cómodo que un "ponte delante y te reconozco", y a cambio no hay ningún momento en que el navegador tenga la biometría de toda la plantilla.

**Cuándo revisar**: si aparece un requisito de identificación 1:N (marcación de asistencia sin escribir documento), esto ya no alcanza y hay que llevar la comparación entera al servidor con un motor nativo.

Detalle completo en [`BIOMETRIA.md`](BIOMETRIA.md).

---

## Decisiones que se DEJARON pasar (deuda técnica consciente)

Cosas que sabemos que no son ideales pero no son urgentes:

| Tema | Por qué pasamos | Cuándo abordar |
|---|---|---|
| CI/CD | Solo, sin equipo, deploy manual es más rápido | Cuando entre el primer dev al equipo |
| Logs centralizados | `storage/logs/laravel.log` alcanza con 1 servidor | Cuando haya 2+ Droplets |
| Monitoreo (Sentry, etc.) | Sin clientes en producción aún. `.env.example` ya tiene las claves, pero el SDK **no está instalado**: `sentry/sentry-laravel` no figura en `composer.json` | Antes del primer go-live con clientes |
| API REST en módulos de negocio | Hoy solo `customers` está expuesto en `/api/v1`, como patrón de referencia. Ningún módulo del dominio de obra tiene API | Cuando haya app móvil o integración con terceros |
| Login rate-limiting | Settings `security.max_login_attempts` y `security.lockout_minutes` sembrados pero sin conectar a nada | Antes del primer go-live público |
| Almacenamiento de evidencias | Las fotos de firma van al disco del droplet. Crecen solas y no se comparten entre servidores | Cuando el disco apriete o entre un segundo servidor. Ver `BIOMETRIA.md` |

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
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — cómo se clona `Brand` al crear módulos nuevos
- [`BIOMETRIA.md`](BIOMETRIA.md) — el detalle del reconocimiento facial y lo que cuesta guardar la evidencia
- [`DOMINIO.md`](DOMINIO.md) — el dominio de DOCUFIZ en una página
