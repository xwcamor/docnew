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
permissions    → claves concretas (ej: "users.index", "users.create")
       ↓
roles          → agrupan permissions (ej: "admin", "editor", "viewer")
       ↓
users          → reciben uno o más roles
```

Un usuario tiene acceso a una acción **solo si** uno de sus roles incluye el permiso correspondiente.

---

## Tablas involucradas

| Tabla | Origen | Propósito |
|---|---|---|
| `system_modules` | Custom del proyecto | Catálogo de módulos del sistema con su `permission_key` |
| `permissions` | Spatie | Permisos individuales (`users.index`, `users.create`, etc.) |
| `roles` | Spatie | Agrupaciones de permissions |
| `model_has_permissions` | Spatie | Permisos asignados directamente a usuarios (poco usual) |
| `model_has_roles` | Spatie | Roles asignados a usuarios |
| `role_has_permissions` | Spatie | Permissions que tiene cada rol |

---

## Flujo de creación de un módulo nuevo

1. **Super-admin entra al panel "Módulos del sistema"**.
2. Crea un módulo: ejemplo `Pacientes`, `permission_key` = `patients`.
3. El sistema **automáticamente** genera 4 permisos en Spatie:
   - `patients.index` (listar)
   - `patients.create` (crear)
   - `patients.update` (editar)
   - `patients.destroy` (eliminar)
4. El admin del cliente puede ahora crear roles que incluyan estos permisos y asignárselos a sus usuarios.

> **No se modifican migraciones** para agregar módulos. Todo es data en runtime.

---

## Jerarquía de roles del sistema

| Rol | Tenant | Acceso | Quién lo crea |
|---|---|---|---|
| **super** | `tenant_id = NULL` (sin tenant) | Todo el sistema sin restricciones — bypasea Gates, plan features y multi-tenant scope | Seeder inicial (`RolesAndPermissionsSeeder`) |
| **admin** (cliente) | Tenant del cliente | Todo lo que el plan de su workspace permita, scoped a su `tenant_id`. NO ve módulos system-level (catálogos super) | Super al alta del workspace |
| **user** | Tenant del cliente | Solo lo que el admin del cliente le asigne via permisos directos o roles custom | Admin del workspace |
| **api** | Tenant del workspace | Solo para system_users (tokens API). Rol técnico, no se asigna a humanos | Auto-creado al crear el workspace |

### Implementación del super

En `AuthServiceProvider::boot()` (o en un Provider similar):

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::before(function ($user, $ability) {
        return $user->hasRole('super') ? true : null;
    });
}
```

Eso hace que **cualquier** `Gate::allows()` o `@can('...')` retorne `true` automáticamente para super-admins, sin importar el permiso específico.

### Restricciones del admin de cliente

Los permisos críticos del sistema NO deben aparecer en la lista de permisos asignables a roles que no sean `super`. Esto se maneja en el frontend (filtrar la lista de permisos) y en el backend (validar al guardar el rol).

Permisos críticos a proteger:
- `system_modules.*`
- `languages.*`
- `regions.*` (parcialmente — depende del modelo)
- `tenants.*`

---

## Ejemplos de uso en código

### En un controller

```php
public function index()
{
    $this->authorize('users.index');
    return User::paginate();
}
```

### En un blade / vue

```php
@can('users.create')
    <a href="{{ route('users.create') }}">Crear usuario</a>
@endcan
```

```vue
<template>
  <a-button v-if="$page.props.auth.user.permissions.includes('users.create')">
    Crear usuario
  </a-button>
</template>
```

### En una ruta

```php
Route::get('users', [UserController::class, 'index'])
    ->middleware('can:users.index');
```

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

Y desde Vue:

```vue
<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const can = (permission) => page.props.auth.user?.permissions?.includes(permission) ?? false;
</script>

<template>
  <a-button v-if="can('users.create')">Nuevo usuario</a-button>
</template>
```

---

## Multi-tenancy y permisos

Si activas `'teams' => true` en `config/permission.php`, Spatie usa `team_foreign_key` para aislar roles por tenant:

- El rol `admin` puede existir **una vez por tenant**.
- Cada tenant tiene su propio set de roles independiente.

Para esto, antes de cualquier query Spatie debe saber el tenant actual. Se hace con un middleware:

```php
// app/Http/Middleware/SetSpatieTeam.php
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

public function handle($request, Closure $next)
{
    if (Auth::check()) {
        app(PermissionRegistrar::class)->setPermissionsTeamId(Auth::user()->tenant_id);
    }
    return $next($request);
}
```

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
```

---

## Documentación relacionada

- [`USAGE.md`](USAGE.md) — manual de uso del sistema por rol (super / admin / user)
- [`plan-features.md`](plan-features.md) — capa de plan (qué desbloquea cada tier)
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — qué permisos sembrar al crear un módulo nuevo
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — decisiones sobre Spatie + super bypass
- [`../README.md`](../README.md) — visión general de roles y capas de acceso
