# Backlog y decisiones

← [Índice](00-INDICE.md)

> El estado FINO al día (qué se hizo en la última sesión) vive en
> `CLAUDE.md` (raíz del repo, fuera de este vault). Esta nota guarda lo
> estable: decisiones cerradas y el backlog real.

## Decisiones de diseño cerradas (NO sugerir cambiar)

- **NO Redis** — índices Postgres sub-1ms ya cubren; cache de queries = premature.
- **NO S3** — solo logos, imports y fotos de perfil; disk `local`.
- **NO webhooks (todavía)** — feature premium futura.
- **NO observers cross-módulo** — futuro.
- **NO code splitting agresivo** — 2.7MB de bundle aceptable hasta tener tráfico.
- **Git: siempre push a `main` directo** (pedido explícito del dueño).
- **IEEE consolidado en la edición 2019** — el método 1991 se eliminó del código.
- **La verificación contra el Ruby viejo está CERRADA** — donde difieren, gana
  el nuevo (validado a mano contra las normas). No re-litigar.
- **Pesos del HI = criterios K de Hitachi** (10/8/6/10; decidido 2026-07-16,
  antes 10/6/5/5 sin fuente). Ver [motor-diagnostico](motor-diagnostico.md).
- **La cabecera fiquis del PDF imprime el límite de NORMA** (Mín 25 / Máx 5),
  no el borde de alerta con tolerancia (27.5/4.5) — decidido 2026-07-16. La
  tolerancia ±10% sigue existiendo solo como banda ámbar de celdas.
- **Contador de registros solo en la toolbar** (sin duplicado en el título) +
  separador de miles — cerrado 2026-07-16.
- **PDF aprobado CONGELADO en disco** (acta inmutable; el array de muestras va
  al snapshot como auditoría; retención 2 años configurable) — decidido
  2026-07-16 con el dueño. Ver [informes-y-aprobaciones](informes-y-aprobaciones.md).
- **Candado de producción** (2026-07-26): `DB::prohibitDestructiveCommands`
  bloquea `migrate:fresh`/`db:wipe`/etc. con `APP_ENV=production`; en local se
  reconstruye libre. Ver [operacion-deploy](operacion-deploy.md).
- **Recálculo de flota AUTOMÁTICO al editar reglas** (2026-07-26): guardar en
  el editor de diagnóstico encola `RecalculateFleetCache` (delay 2 min con
  debounce; super → flota completa, admin → su workspace; colores/etiquetas
  no lo disparan). Cerró el hueco del admin sin acceso a consola. El comando
  `diagnose:fleet-cache` queda como respaldo manual.
- **Nombre comercial: TrafoDex** (decidido 2026-07-26). Vive en el setting
  `app.name` — cambiarlo NO requiere código. Se descartaron "Vitrafo" y
  "Dielektra" (empresas existentes del rubro) y "Trafo Health" (genérico, con
  la marca registrada "Transformer Health Products®" de Prolec cerca).
- **Eje Y de las tendencias acotado a los límites normativos** (2026-07-26,
  pedido del jefe): `utils/charts.js`. El eje NUNCA recorta el dato.
- **Español neutro estricto** en código y respuestas; sin emojis.

## Backlog real

> Lista ÚNICA de lo que queda abierto. Antes estaba desperdigada entre
> `CLAUDE.md`, `docs/origen-ruby/diseno/FIQUIS_auditoria.md` y
> `docs/CRONS-AND-SETTINGS.md`, y era imposible responder "¿qué falta?" sin
> releer todo. Al cerrar un punto se borra de acá, no se marca como hecho.

### 1. Esperando a terceros

- **OLTC tab** — esperando aclaración del jefe.
- **Separación de electrodos del D1816** — LO MÁS SERIO de esta lista. Los
  umbrales de rigidez se heredaron del Ruby viejo rotulados **2.0 mm**, sin
  registro del gap real con que se midió. D877 es siempre 2.54 mm por norma,
  pero D1816 admite 1 mm o 2 mm y los kV NO son comparables. Si el laboratorio
  mide a 1 mm hay que corregir la etiqueta **y** los umbrales, y eso mueve el
  diagnóstico de miles de muestras. Confirmar con el laboratorio.
  *Parcialmente encaminado (2026-07-29)*: las muestras que entran por la
  [API del laboratorio](../API-LABORATORIO.md) traen el gap explícito y se
  guarda en `fiquis.methods`. Lo HISTÓRICO sigue sin registro, así que la
  confirmación con el laboratorio sigue pendiente.
- **`pot100` de silicona = 0.2 %** — está más exigente que el límite a 25 °C
  (0.8 %), y eso va al revés: el factor de potencia sube con la temperatura, así
  que su límite debería ser más holgado. Número heredado del Ruby viejo. Hoy no
  perjudica a ninguna muestra (67 de 68 dan score 1), pero está mal o falta
  contexto.
- **`rig877` de vegetales = 40/47/50** — coincide exacto con el `t1` del D1816
  del mismo aceite. Huele a copia, no a un límite propio del método.
- **`pot100` mineral = 5 %** — es lo que tenía el viejo; relativamente laxo
  frente a límites modernos. El número debe salir de la norma o del
  laboratorio, nunca de criterio propio.

### 2. A debatir con el dueño (no solo codear)

- **Aceite sin reglas para una prueba**: el resumen del trafo no muestra nada y
  sale "no hay registros" — confuso. Hay que decidir el comportamiento
  esperado: ¿mensaje explícito "sin reglas para este aceite", caer a un
  estándar global, o un placeholder? (El caso de cromas ya tiene respaldo IEEE
  y NO rompe el índice; esto es solo presentación.)
- **Bandas graduadas para los métodos alternos** (D877, factor a 100 °C). Hoy
  puntúan con las tres bandas de su color (cumple 1 · tolerancia 3 · fuera de
  norma 4) porque la norma publica un límite de aceptación, no una gradación.
  Se decidió NO inventar el cuarto nivel. Reabrir solo si empiezan a aparecer
  muestras paradas dentro de la franja de tolerancia — hoy hay 2 en toda la
  base. Ver [FIQUIS_auditoria](../origen-ruby/diseno/FIQUIS_auditoria.md).

### 3. Trabajo identificado, sin bloqueo

- **Informe Word: celdas en rojo** cuando un valor pasa su límite, como en el
  PDF. Es la última diferencia conocida entre los dos informes.
- **Migración del delta del sistema viejo**. Los dumps llegan hasta ~2026-05-26
  (cromas) y ~2026-05-21 (fiquis y furanos), y el sistema viejo sigue
  recibiendo datos. Falta un `import:legacy-delta --desde=FECHA`, un comando
  que compare los catálogos (tipos de trafo, aceites, marcas) antes de
  importar, y **el dump de factor de potencia, que nunca se migró** (la tabla
  `fpots` está vacía: 0 filas).
- **`app:purge-soft-deleted` está programado dos veces** (03:00 en
  `routes/console.php` y 04:00 en `bootstrap/app.php`). Decidir cuál queda
  ANTES de producción. Ver [CRONS-AND-SETTINGS](../CRONS-AND-SETTINGS.md).
- **fpot sin alerta de celda** (mejora opcional): su tabla es un valor + la
  temperatura, sin grilla de parámetros con límite individual. Si se quisiera,
  colorear la celda del valor por severidad del semáforo.
- **`TenantResolver`** todavía habla de `empresa1.blog.test` y salta el
  subdominio `"blog"` — resto de la plantilla de la que salió el proyecto. Hoy
  es inofensivo porque **no está registrado en ningún lado**; limpiarlo o
  registrarlo bien antes de usar subdominios por workspace.
- **El azul SAP `#0A6ED1` en los exports del resto de los módulos** (~20). Los
  dos Excel de diagnóstico pasaron a la paleta del informe (`#354A5F` con borde
  `#2A3B4C`); los demás quedaron como estaban, a propósito. Unificar si se
  quiere coherencia.

**Seguridad — hallazgos de la revisión del 2026-07-27.** Los tres primeros
conviene cerrarlos ANTES de que el droplet vea internet. Detalle en
[SECURITY §6.6 y §6.7](../SECURITY.md).

- **`config/database.php` tiene `'sslmode' => 'prefer'` clavado** — hay que
  pasarlo a `env('DB_SSLMODE', 'prefer')` (+ `sslrootcert`) para poder exigir
  `verify-full` en producción. Sin eso la app acepta conectar en claro contra la
  BD. Lo pide la guía [DROPLET-POSTGRES-SECURITY](../DROPLET-POSTGRES-SECURITY.md).
- **`throttle` en `/r/{token}/pdf/{transformer}`** (`routes/web.php`): es la ruta
  pública más cara (renderiza el PDF con dompdf en vivo en cada llamada) y la
  única del grupo sin límite — `/code` y `/verify` sí lo tienen. En los shares
  link-only el token ES la credencial, así que un enlace reenviado alcanza para
  agotar el droplet a fuerza de PDFs.
- **Quitar `svg` del logo de Customer** (`StoreCustomerRequest` /
  `UpdateCustomerRequest`): un SVG lleva `<script>` → XSS almacenado servido
  desde el propio dominio. El resto de la app no lo acepta (perfil, firma,
  branding, foto de usuario), así que es un descuido. Puede estar ya bloqueado
  por la regla `image` de Laravel 11+; quitarlo igual para no depender de eso.
- **`sanctum.expiration` está en `null`** → los tokens de API no caducan nunca.
  Ponerle vencimiento + `sanctum:prune-expired`. OJO: rompe integraciones vivas,
  avisar antes (hoy solo Customer expone API).
- **CSV injection en los `Generate*CsvJob`**: prefijar `'` si el valor empieza
  con `=`, `+`, `-` o `@`. Severidad baja (el daño ocurre en la máquina de quien
  abre el CSV, no en el servidor), pero es hallazgo típico de cuestionario de
  seguridad de un cliente grande.

### 4. Pulido visual (subjetivo, verlo con el dueño)

- Apariencia de las tendencias de fiquis (`MultiAxisTrend`): colores, alto,
  ejes, leyenda — y las franjas de límite.

### 5. El grande: deploy

- **Deploy al droplet** + checklist de [operacion-deploy](operacion-deploy.md).
  Guía de la BD blindada, paso a paso (VPC, TLS, `pg_hba.conf`, roles mínimos,
  túnel SSH desde la laptop, backups cifrados):
  [DROPLET-POSTGRES-SECURITY](../DROPLET-POSTGRES-SECURITY.md). El cambio de
  código que exige (`sslmode`) está en el grupo 3, con el resto de seguridad.
- Tras el primer `php artisan migrate` en producción: correr **una vez**
  `php artisan diagnose:fleet-cache`. Los arreglos de datos (ceros de rigidez y
  factor anulados, sustitución del método alterno) cambian el índice de ~7
  transformadores, y en producción no se resiembra. En local NO hace falta:
  `setup:project` ya recalcula al sembrar.
- **Post-deploy legal (LPDP/ANPD)** — lo técnico ya está (aceptación versionada
  con registro+IP, aviso en el portal, derechos ARCO en Mi perfil). Falta:
  1. Redacción REAL de Términos y Política de Privacidad por un abogado de
     protección de datos. Lo que hay en `legal_management` son plantillas; al
     reemplazar el texto hay que subir el setting `legal.terms_version` para
     forzar la re-aceptación de todos.
  2. Registro del banco de datos ante la ANPD (MINJUS, Perú): declarar los
     bancos "usuarios del sistema" y "destinatarios de informes compartidos".

## Dónde mirar antes de "arreglar" algo

Muchas cosas que parecen bugs son decisiones documentadas:
- Dirección de los ratings (pruebas 4→0, HI 0→4): correcto, ver
  [motor-diagnostico](motor-diagnostico.md).
- "Condición 1" sin scores/pesos: el método IEEE clasifica por límite, no es
  el DGAF ponderado.
- Tolerancia ±10% en rigidez/factor de potencia: heredada literal del Ruby
  viejo (solo colorea, no puntúa).
- El overlay del tour oscurece screenshots (no es bug de dark theme).
