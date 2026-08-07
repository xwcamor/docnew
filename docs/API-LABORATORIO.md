# API del laboratorio

Cómo el sistema de laboratorio (TR LAB) le manda a TrafoDex los resultados de
ensayo de aceite, y cómo TrafoDex los diagnostica.

> **Documento espejo del lado del laboratorio**:
> `labo_new/docs/migracion/04-INTEGRACION-TRAFODEX.md` y
> `06-TRAFODEX-LO-QUE-DEBE-CONSTRUIR.md`.

---

## 1. Para qué existe

El laboratorio es quien genera las muestras que este sistema diagnostica. Hoy
las escribe **directamente en la base**: abre una segunda conexión e inserta
filas en `chromatographicals`, `physicals`, `furanos` y `transformers`. Eso trae
cuatro problemas concretos, todos reales:

| Problema | Qué pasa hoy |
|---|---|
| Sin idempotencia | Reejecutar el asistente inserta las mismas muestras otra vez. El índice `(transformer_id, sample_date)` **no es único**, así que nada lo frena. |
| Emparejamiento por texto | Empareja el equipo con un `find_by` por número de serie: si hay repetidos toma el primero, y la muestra termina en el transformador equivocado. |
| Diagnóstico viejo | Al insertar por SQL no corre `HealthIndexService::evaluate`, así que el índice de salud y la caché de flota quedan desactualizados hasta que alguien ejecute `diagnose:fleet-cache`. |
| Acoplamiento de esquema | Cualquier `ALTER TABLE` de este lado puede romper el laboratorio sin aviso. |

Esta API los cierra: recibe el resultado, lo guarda en una transacción, corre el
mismo motor de diagnóstico que la interfaz web y devuelve el índice de salud
actualizado.

**El reparto no cambia**: el laboratorio dice si el aceite cumple el criterio de
aceptación de la norma; TrafoDex dice qué le pasa al transformador (DGAF, Duval,
IEEE C57.104-2019, índice de salud). Son dos preguntas distintas y no se mezclan.

---

## 2. El token

El token se crea desde la pantalla del workspace, la misma que ya existe para
los tokens de la API de clientes:

1. Entrar como **super** a `/system_management/tenants` y abrir el workspace.
2. Tarjeta **Tokens API** → *Crear token*.
3. Nombre (por ejemplo `TR LAB`), **abilities** y vencimiento opcional.
4. El token en claro se muestra **una sola vez**. Después solo se ven los
   primeros caracteres.

Abilities de esta integración:

| Ability | Habilita |
|---|---|
| `transformers:read` | `GET /api/v1/transformers/lookup` |
| `transformers:write` | `POST /api/v1/transformers` |
| `lab:write` | `POST /api/v1/lab-results` |

Recomendación: dar `lab:write` + `transformers:read`, y agregar
`transformers:write` solo si se acepta que el laboratorio dé de alta equipos que
este sistema no conoce.

Uso:

```http
Authorization: Bearer 12|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
Content-Type: application/json
```

El token pertenece al **usuario de sistema del workspace**
(`api+{slug}@system.local`), así que todas las consultas quedan filtradas a ese
workspace automáticamente. Un laboratorio nunca puede ver ni tocar la flota de
otro.

---

## 3. Qué se pide además del token (la respuesta a "¿alcanza con las abilities?")

**Sí, alcanza — y agregar otra capa sería peor.** El razonamiento, apoyado en lo
que ya hay en el código:

1. **Solo el super crea tokens.** La ruta `tenants/{tenant}/tokens` vive dentro
   del bloque `role:super` de `routes/system_management.php`. Ni el admin del
   workspace ni el laboratorio pueden emitirse uno.
2. **Las abilities son obligatorias al crearlo.** `TenantController::createToken`
   las valida como `required|array|min:1`; sin eso el token nacía con `['*']` y
   pasaba por encima de los `ability:` de cada ruta. Un token de laboratorio
   puede quedar limitado a exactamente lo suyo, y revocarlo es un clic.
3. **El aislamiento por workspace ya está.** `BelongsToTenant` filtra por el
   `tenant_id` del dueño del token en cada consulta, sin que el controlador
   tenga que acordarse.
4. **Un permiso de Spatie no compraría nada.** El usuario de sistema tiene el rol
   `api`, que no tiene permisos, y ninguna ruta de `/api/v1` usa el middleware
   `permission:`. Habría que sembrar un permiso y asignárselo a un rol que
   ninguna persona usa: burocracia sin efecto.
5. **Un ajuste por empresa tampoco.** Sería un segundo interruptor para lo que ya
   controla el token. Existir el token = la integración está habilitada;
   revocarlo = deshabilitada. Dos interruptores para una cosa terminan
   desincronizados.
6. **El plan ya gatea.** `plan_feature:api_access` (enterprise) cubre todo
   `/api/v1` y devuelve **402** al workspace cuyo plan no lo incluye.

**Divergencia deliberada con el documento del laboratorio**, que pedía una
feature de plan nueva llamada `lab_integration`: no se agregó. La fuente de
verdad de los planes es la tabla `plans` (`Plan::hasFeature` lee su columna
`features`, y `config/features.php` es solo respaldo). Una clave que no esté en
esa fila devuelve `false`, así que una feature nueva obligaría a editar **todas**
las filas de planes o el workspace recibiría 402 teniendo el plan correcto. Como
`lab_integration` iba a vivir en el mismo tier que `api_access`, se reusa
`api_access` y no hay nada que sembrar.

Lo que sí conviene sumar, y es operativo, no de código: usar un token **por
sistema** (no compartir el del laboratorio con otro integrador) y ponerle
vencimiento, para que la rotación sea obligatoria y no un buen propósito.

---

## 4. Endpoints

Base: `https://{trafodex}/api/v1`

Todos pasan por `auth:sanctum` + `throttle:api` (60 pedidos por minuto) +
`plan_feature:api_access`.

### 4.1 `GET /transformers/lookup` — buscar el equipo

Ability: `transformers:read`.

Antes de mandar resultados hay que saber a qué transformador corresponden. Este
endpoint devuelve **cero, uno o varios** candidatos; nunca elige. Si devuelve más
de uno o ninguno, la muestra queda en la bandeja de conciliación del laboratorio
y **decide una persona**. Ésa es la diferencia concreta con el `find_by` actual.

Parámetros (al menos uno): `serial`, `tag`, `customer` (nombre o id).
Coincidencia parcial e insensible a mayúsculas y acentos.

```http
GET /api/v1/transformers/lookup?serial=SN-1000&customer=Minera%20Andina
```

```json
{
  "data": [
    {
      "slug": "aBcDeFgHiJkLmNoPqRsTuV",
      "serial": "SN-1000",
      "tag": "TR-01",
      "customer":   { "id": 3,  "name": "Minera Andina" },
      "substation": { "id": 12, "name": "SE Norte" },
      "voltage_kv": 220.0,
      "power_mva": 50.0,
      "phases": "three",
      "health_index": 78.4,
      "health_rating": 3,
      "condition": "Bueno"
    }
  ]
}
```

El **`slug`** es lo que hay que guardar en `equipment.external_ref`: con él, los
envíos siguientes no vuelven a buscar por número de serie.

> `condition` es la palabra traducida y **editable** desde el editor de reglas.
> Para comparar en código usar `health_rating` (0 = peor … 4 = mejor).

### 4.2 `POST /transformers` — dar de alta un equipo

Ability: `transformers:write`. Acepta `Idempotency-Key` (opcional).

Solo cuando el laboratorio recibe la muestra de un equipo que TrafoDex no conoce
**y un operador lo confirmó**. Nunca automático: un transformador fantasma nacido
de un error de tipeo ensucia la flota, el tablero y la tendencia del equipo real.

```json
{
  "serial": "SN-2000",
  "tag": "TR-99",
  "customer_id": 3,
  "customer_substation_id": 12,
  "transformer_type": "potencia",
  "oil_type": "mineral",
  "voltage_kv_hv": 220,
  "voltage_kv_lv": 60,
  "voltage_kv_tv": 10,
  "phases": 3,
  "power_mva": 50,
  "manufacture_year": 1998
}
```

Respuesta `201` con el mismo objeto que devuelve la búsqueda.

Obligatorios: `serial`, `tag`, cliente (`customer_id` o `customer`), subestación
(`customer_substation_id` o `substation`), tipo (`transformer_type` o
`equipment_type`), `oil_type` y alguna tensión. El resto es opcional a propósito
—ver §5.4—.

`serial` + `tag` no se pueden repetir dentro del workspace. La serie sola sí
(una serie puede tener varios tags).

### 4.3 `POST /lab-results` — cargar resultados

Ability: `lab:write`. **Cabecera `Idempotency-Key` obligatoria.**

```http
POST /api/v1/lab-results
Idempotency-Key: 6b1f2c3d-8a4e-4f1b-9c77-0d2f5a1e9b34
```

```json
{
  "transformer": { "slug": "aBcDeFgHiJkLmNoPqRsTuV" },
  "lab": {
    "laboratory_code": "TRLAB",
    "report_number": "REP-LAB-2026-0001",
    "sample_code": "2026-0744",
    "sampled_at": "2026-07-21T09:30:00-05:00",
    "issued_at":  "2026-07-24T16:00:00-05:00"
  },
  "tests": [
    {
      "kind": "chromatography",
      "measured_at": "2026-07-21T14:00:00-05:00",
      "values": { "h2": 12, "o2": 21000, "n2": 61000, "ch4": 5,
                  "co": 340, "co2": 2100, "c2h4": 2, "c2h6": 3, "c2h2": 0 }
    },
    {
      "kind": "physicochemical",
      "measured_at": "2026-07-21T11:00:00-05:00",
      "values": { "acid": 0.002, "rig": 70, "ten": 49.8, "wat": 6, "pot": 0.1 },
      "methods": { "rig": { "standard": "ASTM D1816", "gap_mm": 2.0 },
                   "pot": { "standard": "ASTM D924",  "temp_c": 25 } }
    },
    { "kind": "furanos",      "measured_at": "2026-07-21T12:00:00-05:00", "values": { "fal": 120 } },
    { "kind": "power_factor", "measured_at": "2026-07-21T13:00:00-05:00", "values": { "pot": 0.15, "temp_c": 25 } }
  ]
}
```

Respuesta `201`:

```json
{
  "transformer": {
    "slug": "aBcDeFgHiJkLmNoPqRsTuV",
    "serial": "SN-1000",
    "tag": "TR-01",
    "health_index": 78.4,
    "health_rating": 3,
    "condition": "Bueno"
  },
  "created": [
    { "kind": "chromatography",  "id": 9134, "dgaf_score": 1.12, "dgaf_condition": "Muy Bueno" },
    { "kind": "physicochemical", "id": 4412, "score": 1.4, "condition": "Bueno" },
    { "kind": "furanos",         "id": 2210, "dp": 620, "condition": "Bueno" },
    { "kind": "power_factor",    "id": 1180, "condition": "Muy Bueno" }
  ],
  "warnings": []
}
```

Puntos del diseño:

- **Se identifica por código de analito, no por columna.** El cuerpo usa `h2`,
  `acid`, `rig`, `fal` — los mismos `analytes.code` del laboratorio. La
  equivalencia con las columnas vive en `config/lab_integration.php`: agregar un
  parámetro es una línea de configuración, no un cambio de contrato.
- **Un código desconocido devuelve 422**, no se descarta en silencio. Un dato
  medido que se pierde sin aviso es peor que un error: el informe del cliente
  sale con menos de lo que se pagó y nadie se entera.
- **Todo el envío es una transacción.** Si un ensayo falla no queda ninguno: es
  un informe, entra entero o no entra.
- **El diagnóstico corre aquí.** Al terminar se ejecuta
  `HealthIndexService::evaluate()`, el mismo que corre al guardar una muestra
  desde la web, que recalcula el índice de salud y la caché de flota del equipo
  (`fault_type`, `gassing_rate`, `paper_dp`, `paper_life_years`,
  `ieee_condition`). El job `RecalculateFleetCache` **no** se usa aquí: ése
  recorre la flota entera y existe para cuando cambian las reglas.
- **Una muestra por fecha y prueba**, la misma regla que aplica el formulario
  web. Un segundo envío con la misma fecha para la misma prueba da 422.
- `warnings` trae avisos que no impiden guardar. Hoy hay uno: la cromatografía
  de un aceite sin cuadro de reglas, que se diagnostica con el respaldo IEEE
  C57.104-2019.

#### Ensayos y analitos aceptados

| `kind` | Tabla | Analitos | Obligatorio |
|---|---|---|---|
| `chromatography` | `chromatographicals` | `h2` `o2` `n2` `ch4` `co` `co2` `c2h4` `c2h6` `c2h2` (ppm) | al menos uno |
| `physicochemical` | `fiquis` | `rig` `ten` `acid` `wat` `pot` (+ `rig877`, `pot100`) | al menos uno |
| `furanos` | `furanos` | `fal` `hme` `ace` `mfu` `fua` (ppb) | `fal` |
| `power_factor` | `fpots` | `pot` (o `value`) y `temp_c` | el valor |

> **Cuidado con `pot`**: en `physicochemical` es el factor de potencia del
> **aceite**; en `power_factor` es el del **aislamiento del transformador**. Los
> desambigua el `kind`, nunca el código suelto.

A diferencia del formulario web, la cromatografía **no** exige los nueve gases:
el motor usa peso dinámico y un informe real a veces no trae O₂/N₂. Lo que falta
no castiga.

---

## 5. Los desajustes entre los dos modelos, y qué se decidió

### 5.1 Veinte tipos de equipo contra tres

El laboratorio recibe muestras de 20 tipos de equipo (bujes, reactores,
electrobombas, intercambiadores). TrafoDex diagnostica **tres**: `potencia`,
`distribucion`, `horno`.

**Decisión: se acepta lo que se sabe diagnosticar y se rechaza el resto**, con un
422 que lista los códigos válidos.

Por qué no mapear al más parecido: el sistema viejo hacía *"si es mayor a 3 →
Potencia"*, y eso le aplica a un buje el cuadro de reglas de un transformador de
potencia. El resultado no es un dato aproximado, es un diagnóstico equivocado con
la misma pinta que uno bueno. Y no es irreversible: agregar un tipo aquí es
insertar una fila en `transformer_types` y darle su cuadro de reglas — cuando
exista el criterio para diagnosticarlo, se agrega y la API lo acepta sola.

### 5.2 Tres tensiones contra una

El laboratorio tiene `voltage_kv_hv`, `voltage_kv_lv`, `voltage_kv_tv`;
`transformers.voltage_kv` es un número.

**Decisión: se toma el máximo.** No es una simplificación cómoda: `voltage_kv` se
usa como **clase de tensión** para elegir los umbrales fisicoquímicos del IEEE
C57.106, y la clase de un equipo la define su devanado más alto. Guardar el
secundario haría que un 220/60 kV se juzgue con los límites de un equipo de
60 kV, que son más laxos.

Se aceptan las cuatro claves; con cualquiera alcanza.

### 5.3 Fases: entero contra texto

`transformers.phases` es un texto de lista cerrada (`single|two|three`); el
laboratorio usa un entero.

**Decisión: traducción exacta por tabla** (`config/lab_integration.php`), y un
valor fuera de 1/2/3 se **rechaza**. Nada de redondear al más cercano. También se
acepta el texto, por si el envío viene de otra fuente.

### 5.4 La subestación obligatoria y la jerarquía sin API

`transformers.customer_substation_id` es obligatorio en el formulario web, y la
jerarquía (cliente → sede → área → subestación) no tiene API. El laboratorio no
tiene de dónde sacar el id.

**Decisión: se acepta el id o el nombre dentro del cliente, y cuando no se puede
resolver, el 422 devuelve las subestaciones de ese cliente**:

```json
{
  "message": "La subestación SE Que No Existe no existe en este cliente…",
  "errors": { "substation": ["…"] },
  "available_substations": [ { "id": 12, "name": "SE Norte" } ]
}
```

Así la lista llega exactamente donde hace falta —la bandeja de conciliación,
donde hay una persona eligiendo— sin abrir un módulo de jerarquía entero por API.
La búsqueda (§4.1) también devuelve la subestación de cada candidato, así que
para dar de alta un equipo hermano el id ya está a mano.

**Lo que no se hace**: fabricar una subestación `"-"` como hacía el sistema
viejo. Ese placeholder es el que dejó la jerarquía llena de nodos vacíos.

### 5.5 El método de ensayo: metadato allá, columna aquí

El laboratorio manda un código (`rig`, `pot`) y describe el método aparte, en
`methods`. Acá el método **está en la columna**: `rig` es D1816 y `rig877` es
D877; `pot` es a 25 °C y `pot100` a 100 °C.

**Decisión: se rutea por el bloque `methods`.** Una rigidez con
`{"standard": "ASTM D877"}` se guarda en `rig877`. Sin esto se guardaría como
D1816 y se compararía contra un umbral que no le corresponde: los kV de una
norma y de la otra no son comparables.

Además, el bloque `methods` completo se guarda crudo en `fiquis.methods`. Eso
cierra hacia adelante un pendiente conocido: los umbrales de rigidez vienen del
sistema Ruby **sin registro de la separación de electrodos** y están rotulados
2.0 mm por deducción. De ahora en más el gap llega explícito por muestra. No
entra al diagnóstico —la columna elegida ya lo refleja—; es la constancia para
auditar el día que se revise ese rótulo.

### 5.6 El laboratorio emisor

`lab.laboratory_code` se busca en el catálogo de laboratorios del workspace por
código o por nombre. **Si no existe, se crea.** Rechazar una medición válida
porque falta una fila de catálogo es peor, y guardarla en null perdería la
trazabilidad del informe, que es justamente lo que se vino a arreglar.

---

## 6. Idempotencia

Es la parte más importante de esta API y la que hoy no existe.

### Cómo funciona

1. El laboratorio manda `Idempotency-Key` con el uuid de su mensaje de salida.
2. TrafoDex reserva la clave y ejecuta la petición.
3. La respuesta se guarda **dentro de la misma transacción que los datos**: o
   quedan las dos cosas o no queda ninguna.
4. Un reenvío con la misma clave y el mismo cuerpo devuelve **200** con la
   respuesta original, con la cabecera `Idempotent-Replay: true`, sin crear nada.

| Situación | Respuesta |
|---|---|
| Clave nueva | `201` con lo creado |
| Misma clave, mismo cuerpo | `200` con la respuesta original (`Idempotent-Replay: true`) |
| Misma clave, **otro** cuerpo | `409` `idempotency_key_reused` |
| Misma clave, petición todavía en vuelo | `409` `idempotency_in_progress` — reintentar en unos minutos |
| Sin cabecera | `400` `idempotency_key_required` |

**Una petición que falla libera su clave.** Si el envío se rechaza por validación
no se guarda nada, así que el laboratorio puede corregir el cuerpo y reintentar
con la misma clave. Solo se conservan las respuestas exitosas.

Las claves se guardan en `idempotency_keys` junto con el hash del cuerpo, el
token que las usó y su vencimiento (30 días, configurable en
`lab_integration.idempotency_ttl_days`; muy por encima del último reintento de la
cola del laboratorio, que es a las 6 horas). Las vencidas las borra
`php artisan api:purge-idempotency-keys`, agendado a diario.

### La segunda red: el número de informe

Además, el mismo informe no puede entrar dos veces en la misma prueba del mismo
workspace: hay un índice único **parcial** sobre
`(tenant_id, laboratory_id, report_number)` en las cuatro tablas de muestras.

Cubre un caso distinto al de la clave de idempotencia: **dos peticiones
legítimamente distintas** —claves distintas— que traen el mismo informe, típico
al reprocesar una cola vieja o al reenviar a mano desde la bandeja.

Es parcial por dos razones:

- `report_number IS NOT NULL` — hay unas 20.000 muestras históricas migradas del
  sistema Ruby, que no guardaba el número de informe. Quedan fuera del índice.
- `deleted_at IS NULL` — una muestra borrada no debe bloquear la recarga del
  mismo informe.

`laboratory_id` entra en la clave porque el correlativo lo lleva cada
laboratorio: dos pueden emitir su `REP-2026-0001`.

> Si la base ya tuviera informes repetidos cargados a mano, la migración **no
> falla**: avisa por consola y salta esa tabla. Hay que resolver los duplicados y
> volver a correrla.

---

## 7. Códigos de error

| Código HTTP | Cuándo | Qué hacer |
|---|---|---|
| `400` | Falta `Idempotency-Key` o es inválida | Corregir el cliente. No reintentar igual. |
| `401` | Token ausente, vencido o revocado | Renovar el token. |
| `402` | El plan del workspace no incluye `api_access` | Es comercial, no técnico. |
| `403` | Al token le falta la ability de esa ruta | Crear un token nuevo con la ability correcta. |
| `404` | La ruta no existe (error de URL) | Revisar el endpoint. Un slug que no existe en el workspace **no** da 404: da 422, porque es un problema del envío. |
| `409` | Clave de idempotencia reusada con otro cuerpo, o petición en vuelo | `reused`: usar una clave nueva. `in_progress`: reintentar en minutos. |
| `422` | Cuerpo inválido: equipo ambiguo o inexistente, tipo de equipo no diagnosticable, analito desconocido, fecha repetida, subestación sin resolver | **No reintentar sin cambios**: mandar a la bandeja de conciliación. |
| `429` | Más de 60 pedidos por minuto | Respetar el backoff. |
| `5xx` | Error del servidor | Reintentar con backoff exponencial. La idempotencia hace que sea seguro. |

Forma de los errores:

```json
{ "message": "Texto explicativo", "code": "idempotency_key_reused" }
```

o, cuando es de validación:

```json
{ "message": "…", "errors": { "tests.0.values.plutonio": ["…"] } }
```

---

## 8. Dónde vive cada cosa

| Pieza | Archivo |
|---|---|
| Rutas | `routes/api.php` (grupo `v1` → Laboratorio) |
| Controlador | `app/Http/Controllers/Api/V1/LabResultApiController.php` |
| Ingesta y diagnóstico | `app/Services/Lab/LabResultService.php` |
| Búsqueda, alta y traducción de modelo | `app/Services/Lab/LabTransformerService.php` |
| Mapa analito → columna, métodos, fases | `config/lab_integration.php` |
| Idempotencia | `app/Http/Middleware/EnforceIdempotency.php` + `app/Models/IdempotencyKey.php` |
| Validación del cuerpo | `app/Http/Requests/Api/V1/` |
| Respuesta del transformador | `app/Http/Resources/TransformerApiResource.php` |
| Mensajes | `resources/lang/{es,en}/lab_api.php` |
| Pruebas | `tests/Feature/Api/V1/LabResultApiTest.php` |

Migraciones que agrega esta integración:

- `..._create_idempotency_keys_table.php`
- `..._add_report_number_unique_to_samples.php`
- `..._add_methods_to_fiquis.php`

---

## 9. Fuera de alcance por ahora

- **El PDF firmado del laboratorio** (`POST /lab-results/{id}/documents`). Está
  diseñado del lado del laboratorio pero todavía no implementado aquí: necesita la
  tabla `sample_documents` polimórfica, su registro en `config/polymorphic.php` y
  `config/purge.php`, y una sección en la ficha del transformador. Cuando se
  haga: se recibe **solo el PDF emitido**, nunca el Word editable ni el borrador
  —un archivo editable no puede sostener la promesa de autenticidad, criterio ya
  establecido para el informe propio—.
- **Webhook de vuelta** al laboratorio cuando se recalcula el índice de salud.
  Los webhooks están cerrados como funcionalidad premium futura y no se adelantan
  por esta integración. Si el laboratorio necesita el índice, lo consulta.
- **Bandeja de conciliación**: es del lado del laboratorio. Esta API se limita a
  darle la información para decidir (candidatos, subestaciones disponibles) y a
  negarse a adivinar.
