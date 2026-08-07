# Módulos y scaffold

← [Índice](00-INDICE.md) · Detalle completo: [CREATE-MODULE](../CREATE-MODULE.md)

## Crear un módulo

```bash
php artisan make:module {Name} --group=BusinessManagement
```

Genera ~50 archivos (controller, service, model, 9 FormRequests, 6 Jobs, 3
Exports, 1 Import, 6 páginas Vue, 13 componentes, config + i18n ×2 idiomas,
migration, factory). Auto-registra en `system_modules`, appendea rutas y
agrega entries en `config/polymorphic.php` + `config/purge.php`.

- **Clona `Brand`** (catálogo limpio: name + code + is_active + sort_order +
  tenant_id, traits `BelongsToTenantOrGlobal` + `Lockable`) — NO Customer.
- Campos custom: `--fields="price:decimal, stock:integer, sku:string?"`.
  Las FKs (`references`) se agregan a mano.
- `--no-tenant` lo hace catálogo global.
- El comando vive en `app/Console/Commands/MakeModuleCommand.php`.

## Post-scaffold manual

1. Sidebar: `resources/js/Layouts/AppLayout.vue` + `resources/lang/{es,en}/sidebar.php`
2. Permisos: `database/seeders/RolesAndPermissionsSeeder.php`
3. Plan features: `config/features.php`
4. Columnas custom de la migration (FKs, índices)
5. Si tiene FKs entrantes: array `dependents()` del modelo
6. API REST opcional (solo Customer la expone hoy, como patrón)
7. Seguir las [convenciones de UI](ui-convenciones.md)

## Customer = patrón de referencia (no plantilla)

La entidad más completa: jerarquía (ubicación→área→subestación), logo, API
REST, clientes asignados (`RestrictedToAssignedCustomers`), bulk async >200,
undo 60s, import 3-layer dedup, favoritos, saved views, plan gating. Para
features avanzadas se copia de ahí A MANO.

## Multi-tenancy

- `BelongsToTenant` / `BelongsToTenantOrGlobal`: cada workspace ve solo lo
  suyo; `tenant_id` null = global; super hace bypass (`HideSuperScope`).
- Defense-in-depth en requests: si no es super, se quita `tenant_id` del
  payload (ej. `StoreAutomationRequest::prepareForValidation`).
- Auth: Sanctum bearer tokens con abilities. Permisos: Spatie con traits
  custom — ver [PERMISSIONS](../PERMISSIONS.md).

## Locks

Trait `Lockable` + columnas de lock + rutas lock/unlock. Registro bloqueado:
sin editar/eliminar/bulk; el candado se muestra en la fila. No se ofrece
"Bloquear" en registros globales/compartidos que el usuario no posee.

## Soft-delete estándar

Eliminar pide MOTIVO (queda en el audit) y manda a papelera. La papelera
("Ver eliminados") es solo-super: restaurar individual/masivo y force-delete
con triple guard (nombre + motivo). Purga automática: `config/purge.php`.
