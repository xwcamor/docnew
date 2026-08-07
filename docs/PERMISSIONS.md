# Roles y permisos

**Qué es esto**: cómo funciona la autorización del sistema — quién puede ver/hacer qué.

**Para qué sirve**: entender el modelo de roles (super / admin / user) + permisos custom de Spatie, el bypass del super, los gates de plan y las capas de protección. Lo usas cada vez que tengas que decidir "¿quién puede entrar a esta ruta?" o "¿por qué este usuario no ve este botón?".

**Cuándo leerlo**: al crear un módulo nuevo (para saber qué permisos sembrar), al diagnosticar un 403 inesperado, o al armar un rol custom para un workspace.

El sistema usa **Spatie Permission** + tabla `system_modules` propia para definir qué puede hacer cada usuario.

---

## Concepto general

```
system_modules → define qué módulos existen y su permission_key
       ↓
permissions    → claves concretas (ej: "work_plans.view", "work_plans.create")
       ↓
roles          → agrupan permissions (ej: "Supervisor de obra", "Usuario de campo")
       ↓
users          → reciben uno o más roles
```

Un usuario tiene acceso a una acción **solo si** uno de sus roles incluye el permiso correspondiente.

Lo que hace que esto no se olvide nunca es el observer: `SystemModuleObserver`
escucha el `created` de `SystemModule` y crea los permisos del módulo en el acto.
Registrar un módulo es lo único que hay que hacer; los permisos vienen detrás.

---

## Tablas involucradas

| Tabla | Origen | Propósito |
|---|---|---|
| `system_modules` | Custom del proyecto | Catálogo de módulos del sistema con su `permission_key` |
| `permissions` | Spatie | Permisos individuales (`work_plans.view`, `work_plans.create`, etc.) |
| `roles` | Spatie | Agrupaciones de permissions |
| `model_has_permissions` | Spatie | Permisos asignados directamente a usuarios (poco usual) |
| `model_has_roles` | Spatie | Roles asignados a usuarios |
| `role_has_permissions` | Spatie | Permissions que tiene cada rol |

---

## Flujo de creación de un módulo nuevo

1. **El super entra al panel "Módulos del sistema"**.
2. Crea un módulo: por ejemplo `WorkTypes`, `permission_key` = `work_types`.
3. `SystemModuleObserver` genera **7 permisos** en Spatie, los de
   `CANONICAL_ACTIONS`:
   `work_types.view`, `.show`, `.create`, `.edit`, `.delete`, `.export`, `.import`.
4. El admin del workspace ya puede crear perfiles con esos permisos y asignarlos.

Si después se **renombra** el `permission_key`, el observer renombra los siete
permisos en lugar de dejar huérfanos los viejos.

> **No se modifican migraciones** para agregar módulos. Todo es data en runtime.

### Los módulos core no generan permisos

Ni `tenants`, ni `regions`, ni `languages`, ni `countries`, ni `locales`, ni
`settings`, ni `system_modules` están en `SystemModulesSeeder`, y es deliberado:
sus rutas se protegen con `role:super`, no con `permission:*`. Crear permisos que
ninguna ruta consulta solo serviría para que un admin se los asignara a un rol y
creyera que le dan acceso.

Lo mismo con `audit_logs` (solo lectura, protegido por `role:super|admin`) y con
`dashboards` (protegido solo por `auth`).

### Los módulos que hay hoy

Lo que siembra `SystemModulesSeeder`, y por tanto los `permission_key` que
existen:

| Grupo | `permission_key` |
|---|---|
| Accesos | `users`, `roles` |
| Dominio de obra | `companies`, `people`, `work_plans`, `form_templates`, `form_submissions`, `signature_events` |
| Catálogos de obra | `work_types`, `work_locations`, `workstations`, `work_areas`, `positions`, `nationalities`, `approval_rules` |
| Heredado | `customers` |

`customers` sigue ahí a propósito: está entretejido con usuarios, roles,
automatizaciones y el buscador. `brands` no está en el seeder porque `Brand` es la
plantilla que clona `make:module`, no un módulo de negocio.

Además de los canónicos hay cuatro permisos que no salen de ningún módulo:
`form_submissions.sign`, `signature_events.review` y `comments.view/create/delete`.
Qué gobierna cada uno está en [`ACCESS-MODEL.md`](ACCESS-MODEL.md#3-permisos-transversales-no-salen-de-un-módulo-crud).

---

## Jerarquía de roles del sistema

Hay **tres roles del sistema** y cuatro perfiles globales. No existe un rol
llamado `user`: un usuario sin rol simplemente no tiene permisos, y quien trabaja
en obra lleva uno de los perfiles.

| Rol | Tenant | Acceso | Quién lo crea |
|---|---|---|---|
| **super** | `tenant_id = NULL` | Todo el sistema sin restricciones — saltea permisos, plan y el scope de tenant | `RolesAndPermissionsSeeder` |
| **admin** | Su workspace | Todos los permisos del sistema, filtrados a su `tenant_id`. No entra al core ni a la papelera | El super al dar de alta el workspace |
| **api** | Su workspace | Solo para el usuario de sistema que sostiene los tokens de API. No se asigna a humanos, y las capacidades reales las lleva cada token (abilities de Sanctum), no el rol | Se crea con el workspace |

Los perfiles que se asignan a la gente de obra —**Supervisor de obra**, **Usuario
de campo**, **Auditor HSE (solo lectura)** y **Soporte (editor)**— son roles
globales (`tenant_id = NULL`) que publica el super y que cada workspace asigna sin
poder editarlos. Su contenido exacto está en
[`ACCESS-MODEL.md`](ACCESS-MODEL.md#8-los-4-perfiles-globales-sembrados).

### Implementación del super

Vive en [`AppServiceProvider::boot()`](../app/Providers/AppServiceProvider.php):

```php
Gate::before(function ($user, $ability) {
    return $user->hasRole('super') ? true : null;
});
```

Eso hace que **cualquier** `Gate::allows()` o `can('...')` devuelva `true` para el
super sin mirar el permiso concreto. Devolver `null` (y no `false`) para el resto
es lo que deja que la comprobación normal siga su curso.

### Restricciones del admin de workspace

Los permisos del core no existen (ver arriba), así que un admin **no puede
asignárselos aunque quiera**: no aparecen en la lista porque nunca se crearon. Esa
es la protección, y es mejor que filtrar la lista en el formulario, porque no
depende de que nadie se acuerde de filtrarla.

---

## Ejemplos de uso en código

### En una ruta — la forma normal del proyecto

Prácticamente todo el gateo vive aquí, en `routes/*.php`, con el middleware
`permission:` de Spatie. La ruta es la fuente de verdad:

```php
Route::middleware('permission:work_plans.view')->group(function () {
    Route::get('work_plans',            [WorkPlanController::class, 'index'])->name('work_plans.index');
    Route::get('work_plans/{workPlan}', [WorkPlanController::class, 'show'])->name('work_plans.show');
});
```

Y cuando hacen falta dos condiciones, se apilan:

```php
Route::middleware(['permission:people.create', 'plan_feature:bulk_operations'])->group(function () {
    Route::post('people/import', [PersonController::class, 'import'])->name('people.import');
});
```

### En un controller

```php
public function index()
{
    $this->authorize('work_plans.view');
    return WorkPlan::paginate();
}
```

### En Vue

```vue
<template>
  <a-button v-if="can('work_plans.create')">Nuevo plan</a-button>
</template>
```

El helper `can()` viene del composable
[`useAuth`](../resources/js/Composables/useAuth.js), que lee los permisos de la
prop compartida. No hay que tocar `page.props` a mano.

---

## Permisos del usuario en el frontend

Para que Vue/Inertia sepa qué puede hacer el usuario, se comparten en `HandleInertiaRequests::share()`:

```php
'auth' => [
    'user' => $request->user() ? [
        'id'          => $request->user()->id,
        'name'        => $request->user()->name,
        'permissions' => $request->user()->getAllPermissions()->pluck('name'),
        'roles'       => $request->user()->getRoleNames(),
    ] : null,
],
```

Y desde Vue, siempre a través del composable — que además espeja el bypass del
super, cosa que un `includes()` a mano no hace:

```vue
<script setup>
import { useAuth } from '@/Composables/useAuth';

const { can, isSuper, canSeeAudit } = useAuth();
</script>

<template>
  <a-button v-if="can('people.create')">Nueva persona</a-button>
</template>
```

---

## Multi-tenancy y permisos

**El modo `teams` de Spatie está apagado** (`'teams' => false` en
`config/permission.php`), y no se usa. El aislamiento por workspace se resuelve de
otra forma: el modelo `Role` del proyecto tiene su propia columna `tenant_id`.

- `tenant_id = NULL` → **perfil global**. Lo ven todos los workspaces y lo pueden
  asignar a sus usuarios, pero solo el super lo edita. Los cuatro perfiles
  sembrados son de este tipo.
- `tenant_id = X` → perfil propio de ese workspace. Lo crea y lo edita su admin.

Los **permisos** en cambio son globales y únicos: `work_plans.view` es una sola
fila, compartida. Lo que cambia por workspace es qué roles la incluyen y a quién
se le asigna ese rol.

Esto es más simple que `teams` y encaja con el producto: los módulos son los
mismos para todos, lo que cambia es quién los usa.

---

## Comandos útiles

```bash
# Limpiar el caché de permisos (Spatie cachea los queries)
php artisan permission:cache-reset

# Ver todos los roles y sus permisos
php artisan tinker
>>> \Spatie\Permission\Models\Role::with('permissions')->get()->toArray();

# Asignar un rol a un usuario manualmente (en tinker)
>>> User::find(1)->assignRole('super');

# Volcar los permisos custom de los perfiles a un JSON (para versionarlos)
php artisan permissions:export
```

---

## Documentación relacionada

- [`ACCESS-MODEL.md`](ACCESS-MODEL.md) — qué habilita cada permiso, capa por capa, y los perfiles sembrados
- [`FLUJO.md`](FLUJO.md) — qué permiso gobierna cada paso del día de obra
- [`USAGE.md`](USAGE.md) — manual de uso del sistema por rol (super / admin / user)
- [`plan-features.md`](plan-features.md) — capa de plan (qué desbloquea cada tier)
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — qué permisos aparecen al crear un módulo nuevo
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — decisiones sobre Spatie + super bypass
- [`../README.md`](../README.md) — visión general de roles y capas de acceso
