# Automatizaciones

Las **automatizaciones** son reglas que se ejecutan solas en horarios programados. Sirven para que el sistema haga tareas repetitivas sin intervención humana.

> **Quién las puede crear**: admin del workspace + super. Requiere plan `pro` o `enterprise` (feature `automations`).
>
> **Dónde están en la UI**: Sidebar → Automatizaciones (`/automation_management/automations`).

---

## 1. ¿Qué se puede automatizar?

Una automatización tiene 3 piezas:

```
┌─ TRIGGER (cuándo se dispara) ──────────────────────────┐
│  Schedule recurrente: diario / semanal / mensual / cron │
│  Ej: "todos los días a las 09:00 hora de Lima"          │
└─────────────────────────────────────────────────────────┘
                         ↓
┌─ DATA SOURCE (qué datos consulta) ─────────────────────┐
│  Customers / Users / Subscriptions                      │
│  + filtros: where condiciones + limit                   │
│  Ej: "customers con is_active=true creados hace >7 días"│
└─────────────────────────────────────────────────────────┘
                         ↓
┌─ ACTION (qué hacer con esos datos) ────────────────────┐
│  Email — manda mensaje a una lista de destinatarios     │
│  In-app notification — envía notificación al bell       │
└─────────────────────────────────────────────────────────┘
```

### Ejemplos prácticos

| Caso de uso | Trigger | Data source + filtro | Action |
|---|---|---|---|
| "Reporte semanal de nuevos clientes" | Lunes 09:00 | Customers creados últimos 7 días | Email al admin con lista |
| "Avisar usuarios inactivos" | Diario 08:00 | Users con `last_login_at < hace 30 días` | Email al admin |
| "Alerta de suscripciones por vencer" | Diario 09:00 | Subscriptions con `ends_at < hoy+7d` | Email + In-app notification |
| "Recordatorio de productos sin stock" | Diario 07:00 | Products con `stock = 0` | Email al admin |

---

## 2. Crear una automatización desde la UI

### Paso 1 — Ir al módulo

Sidebar → **Automatizaciones** → botón **Nueva automatización**.

> Si no ves la opción en el sidebar, tu plan no incluye automatizaciones. Solo `pro` y `enterprise` la tienen. Contacta al super para subir de plan.

### Paso 2 — Información básica

| Campo | Para qué |
|---|---|
| **Nombre** | Cómo identificas la regla (ej. "Reporte semanal de clientes nuevos") |
| **Descripción** | Notas internas para tu equipo (opcional) |
| **Activa** | Toggle on/off. Si está off, el trigger no se ejecuta |

### Paso 3 — Trigger (cuándo)

Hoy solo está disponible el tipo `schedule`. Configuraciones soportadas:

#### Opción A — Daily simple

```
Tipo: Daily
Hora: 09:00
Timezone: America/Lima (el de tu workspace por defecto)
```

Corre todos los días a la hora indicada.

#### Opción B — Cron expression (avanzado)

```
Tipo: Cron
Expression: 0 9 * * 1
```

Sigue el formato cron estándar. Ejemplos útiles:

| Expression | Cuándo corre |
|---|---|
| `0 9 * * *` | Todos los días a las 09:00 |
| `0 9 * * 1` | Lunes a las 09:00 |
| `0 9 1 * *` | El primer día de cada mes a las 09:00 |
| `0 */6 * * *` | Cada 6 horas (00:00, 06:00, 12:00, 18:00) |
| `0 9 * * 1-5` | Lunes a viernes a las 09:00 |

> El timezone para cron es el del workspace (configurable en Settings del workspace).

### Paso 4 — Data source (opcional)

Si tu acción necesita una lista de registros (ej. "todos los customers nuevos"), elige una fuente:

| Data source | De qué tabla saca datos |
|---|---|
| **Customers** | Tabla `customers` del workspace |
| **Users** | Tabla `users` del workspace |
| **Subscriptions** | Tabla `subscriptions` del workspace |

Si tu acción es solo un mensaje fijo (sin datos), puedes **dejar el data source vacío**. En ese caso, el email se enviará con el texto literal sin ningún registro.

### Paso 5 — Filtros del data source (opcional)

Filtra qué registros incluir. Cada filtro tiene 3 partes: **campo + operador + valor**.

Operadores disponibles:
- `=` — igual
- `!=` — distinto
- `>`, `<`, `>=`, `<=` — comparación numérica/fecha
- `contains` — contiene (texto)

Ejemplos:

```
Filtro 1: is_active = true
Filtro 2: created_at >= 2026-01-01
```

Todos los filtros se aplican en AND. No hay OR todavía (es feature futura).

**Limit**: cuántos registros máximo. Default 100, mínimo 1, máximo 1000.

### Paso 6 — Action (qué hacer)

#### Email

```
To: admin@empresa.com, otro@empresa.com    (1 o más, separados por coma)
Subject: Reporte de {date}
Body:
  Hola,
  
  Hoy hay {count} clientes nuevos:
  {list}
  
  Saludos
```

Variables disponibles en subject + body:
- `{count}` — cantidad de registros que matchearon el filtro
- `{list}` — bullet list "- nombre del registro" (hasta 50 registros)
- `{date}` — fecha actual en formato `Y-m-d`
- `{automation}` — nombre de esta automatización

#### In-app notification

Manda una notificación al bell del header de los destinatarios:

```
Recipients: admin_of_workspace | all_workspace_users | specific_user_ids
Message: Hay {count} clientes nuevos esta semana
```

### Paso 7 — Guardar

Click en **Crear**. La automatización queda con `is_active=true` por default y `next_run_at` calculado según el trigger.

---

## 3. Cómo se ejecuta (técnico, para entender)

```
Cron del SO (cada minuto)
       ↓
php artisan schedule:run
       ↓
[Laravel scheduler dispara:]
       ↓
php artisan automations:tick
       ↓
Busca automations con is_active=true AND next_run_at<=now()
       ↓
Por cada una → despacha RunAutomationJob al queue
       ↓
[Queue worker procesa el job:]
       ↓
1. Resuelve el DataSource (si hay)
2. Aplica el filtro (where + limit)
3. Resuelve el Action (Email o InApp)
4. Ejecuta la acción con los datos
5. Registra el resultado en automation_runs
6. Reprograma next_run_at según el trigger
```

**Lo crítico para que funcione en producción**:
- ✅ Cron del SO con `* * * * * php artisan schedule:run`
- ✅ Queue worker corriendo (`php artisan queue:work` via supervisor)
- ✅ SMTP configurado en `.env` (`MAIL_*`)
- ✅ Setting `notifications.email_enabled = true` (si el email_enabled está off, los emails NO se envían pero la automation se marca exitosa)

---

## 4. Ver historial de ejecuciones

En el detalle de cada automatización, tab **Ejecuciones**:

| Columna | Qué muestra |
|---|---|
| Fecha y hora | Cuándo se ejecutó |
| Status | `success` / `failed` / `running` |
| Records | Cuántos registros encontró el filtro |
| Resultado | Mensaje de éxito o error |

Si una ejecución falla, el `failures_count` del registro aumenta. Tras 5 fallos consecutivos la automation se **auto-desactiva** (`is_active=false`) para evitar spam, y se notifica al admin del workspace.

---

## 5. Pausar / Editar / Eliminar

### Pausar temporalmente

Listado → click en el toggle de la columna "Activa" → se vuelve `is_active=false`. El trigger ya no se dispara hasta que la vuelvas a activar.

Útil para vacaciones, mantenimiento, o si queremos verificar algo.

### Editar

Listado → click en el ícono lápiz. **Cambios comunes**:
- Cambiar el horario del trigger
- Cambiar los destinatarios del email
- Ajustar los filtros (ej. de "últimos 7 días" a "últimos 30 días")

> Editar **NO** dispara una ejecución inmediata. La próxima corrida será según el trigger nuevo (el `next_run_at` se recalcula al guardar).

### Eliminar (soft delete)

Listado → click en el ícono basura → motivo obligatorio (mín 3 chars).

La automation pasa a la papelera. Tienes 60 segundos para **deshacer** con el botón del toast.

Pasados los 60s:
- El admin del workspace **no puede recuperarla** (la papelera es super-only)
- Solo el super puede restaurarla desde Sidebar → Papelera (super) → Restaurar

---

## 6. Probar una automatización antes de programarla

Hoy no hay un botón "Ejecutar ahora" en la UI (feature futura).

**Workaround manual** (super only, desde la terminal del server):

```bash
php artisan tinker
>>> $automation = App\Models\Automation::find(123);
>>> app(App\Services\Automations\AutomationRunner::class)->run($automation);
```

Esto ejecuta la automatización inmediatamente, ignorando el `next_run_at`. Útil para probar nuevas reglas en dev.

---

## 7. Limitaciones actuales

| Limitación | Workaround / Notas |
|---|---|
| Solo trigger `schedule` | No hay event-driven (ej. "cuando se crea un Customer") ni webhook todavía. Feature futura. |
| Solo 3 data sources | Customers, Users, Subscriptions. Para sumar Products / Sales / etc., agregar la clase al `DataSourceRegistry`. |
| Solo 2 actions | Email + In-app notification. No hay webhook out, no hay export-to-file. |
| Filtros AND solamente | No hay OR ni grupos. Si lo necesitas, crea 2 automatizaciones separadas. |
| Sin "Ejecutar ahora" en UI | Workaround vía tinker (super only). |
| Sin "Dry-run preview" en UI | No puedes ver qué registros matchearán antes de ejecutar. Probar con un email a ti mismo primero. |
| Sin condicional "if records > 0 entonces enviar" | Si el filtro no retorna nada, igual se envía el email (con `{count}=0` y `{list}=—`). |

---

## 8. Cómo agregar un data source / action nuevo (técnico)

### Data source nuevo (ej. Products)

1. Crear `app/Services/Automations/DataSources/ProductsDataSource.php` que implemente `DataSourceContract`:

```php
namespace App\Services\Automations\DataSources;

use App\Models\Product;
use App\Services\Automations\Contracts\DataSourceContract;
use Illuminate\Support\Collection;

class ProductsDataSource implements DataSourceContract
{
    public function key(): string { return 'products'; }
    public function label(): string { return __('automations.ds_products'); }

    public function fields(): array
    {
        return [
            'name'       => ['type' => 'string', 'label' => 'Nombre'],
            'price'      => ['type' => 'number', 'label' => 'Precio'],
            'stock'      => ['type' => 'number', 'label' => 'Stock'],
            'is_active'  => ['type' => 'bool',   'label' => 'Activo'],
            'created_at' => ['type' => 'date',   'label' => 'Fecha de creación'],
        ];
    }

    public function fetch(?array $filter): Collection
    {
        $query = Product::query();
        // Aplicar filtro (FilterApplier ya maneja where + limit)
        return $query->get();
    }
}
```

2. Registrarlo en `DataSourceRegistry::register()`.

### Action nueva (ej. Slack webhook)

Igual que arriba, implementando `ActionContract` con `key()`, `label()`, `configSchema()`, `execute()`.

Detalle de la API en [`app/Services/Automations/Contracts/`](../app/Services/Automations/Contracts/).

---

## 9. Documentación relacionada

- [`USAGE.md`](USAGE.md) — manual de usuario general
- [`CRONS-AND-SETTINGS.md`](CRONS-AND-SETTINGS.md) — cómo funciona el scheduler de Laravel
- [`plan-features.md`](plan-features.md) — qué plan incluye `automations`
- [`PERMISSIONS.md`](PERMISSIONS.md) — quién puede ver/editar automatizaciones
