# Frontend — Inertia + Vue 3

**Qué es esto**: guía operativa para trabajar la capa de UI del proyecto.

**Para qué sirve**: entender cómo se conectan controllers Laravel ↔ páginas Vue vía Inertia, qué convenciones siguen los componentes, cómo usar Ant Design Vue, AG Grid y Tailwind, y cómo organizar páginas/componentes nuevos.

**Cuándo leerlo**: cuando creas o modificas una página Vue, o agregas un componente compartido nuevo en `resources/js/Components/Common/`.

---

## Stack

| Librería | Para qué |
|---|---|
| **Inertia.js** | Puente entre Laravel y Vue (no necesitas API ni tokens) |
| **Vue 3** | Framework reactivo |
| **Vite** | Bundler (HMR ultra rápido) |
| **Tailwind CSS 4** | Utilidades de estilo |
| **Ant Design Vue 4** | Componentes UI enterprise (botones, forms, modales, etc.) |
| **AG Grid Community** | Tablas tipo Excel (ordenables, filtrables, virtualizadas) |
| **Ziggy** | Acceso a `route()` de Laravel desde JS |
| **@vladmandic/face-api** | Cámara, 68 puntos faciales y descriptor de 128 números para la firma |

---

## Cómo funciona Inertia (concepto)

Inertia **no es un SPA puro**. Funciona así:

1. La primera petición (ej: `/dashboard`) recibe un HTML completo desde Laravel (el blade `app.blade.php`).
2. Vue toma el control del DOM.
3. Las navegaciones siguientes son **AJAX**: piden solo el JSON de la nueva página y Vue intercambia el componente sin recargar.

El controller no devuelve `view(...)`, devuelve `inertia(...)`:

```php
// Controller
public function index()
{
    return inertia('Users/Index', [
        'users' => User::paginate(),
    ]);
}
```

```vue
<!-- resources/js/Pages/Users/Index.vue -->
<script setup>
defineProps({
    users: Object,
});
</script>

<template>
  <div>
    <h1>Usuarios</h1>
    <ul>
      <li v-for="user in users.data" :key="user.id">{{ user.name }}</li>
    </ul>
  </div>
</template>
```

---

## Crear una página nueva

### Paso 1 — Componente Vue

`resources/js/Pages/MiModulo/MiVista.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { Card, Button } from 'ant-design-vue';

defineProps({
    items: Array,
});

const counter = ref(0);
</script>

<template>
  <div class="p-6">
    <Card title="Mi vista">
      <Button type="primary" @click="counter++">
        Click: {{ counter }}
      </Button>
    </Card>
  </div>
</template>
```

### Paso 2 — Controller

```php
public function show()
{
    return inertia('MiModulo/MiVista', [
        'items' => MiModelo::all(),
    ]);
}
```

### Paso 3 — Ruta

`routes/web.php`:
```php
Route::get('/mi-modulo', [MiController::class, 'show'])->name('mi.show');
```

Listo. Visita `/mi-modulo` y aparecerá Vue.

---

## Componentes Ant Design Vue (los más usados)

```vue
<script setup>
import {
  Button, Input, Select, DatePicker,
  Form, FormItem,
  Card, Table, Tag, Space, Modal,
  message, notification,
} from 'ant-design-vue';
</script>
```

**Documentación oficial**: https://antdv.com/components/overview

Ejemplos típicos:

### Form

```vue
<Form :model="formData" layout="vertical" @finish="onSubmit">
  <FormItem label="Email" name="email" :rules="[{ required: true, type: 'email' }]">
    <Input v-model:value="formData.email" />
  </FormItem>
  <Button type="primary" html-type="submit">Guardar</Button>
</Form>
```

### Modal

```vue
<Modal v-model:open="visible" title="Confirmar" @ok="onConfirm">
  ¿Estás seguro?
</Modal>
```

### Notificación / Toast

```js
import { message } from 'ant-design-vue';

message.success('Guardado correctamente');
message.error('Error al guardar');
```

---

## AG Grid (tablas)

Para listados con muchos datos (cientos o millones de filas).

```vue
<script setup>
import { ref } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';

const rowData = ref([
    { id: 1, name: 'Juan', email: 'juan@x.com' },
    { id: 2, name: 'Ana',  email: 'ana@x.com' },
]);

const columnDefs = [
    { field: 'id',    headerName: 'ID', width: 80 },
    { field: 'name',  headerName: 'Nombre', sortable: true, filter: true, flex: 1 },
    { field: 'email', headerName: 'Email',  sortable: true, filter: true, flex: 1 },
];

const defaultColDef = { resizable: true };
</script>

<template>
  <div class="ag-theme-quartz" style="height: 500px; width: 100%;">
    <AgGridVue
      :rowData="rowData"
      :columnDefs="columnDefs"
      :defaultColDef="defaultColDef"
      animateRows="true"
      style="width: 100%; height: 100%;"
    />
  </div>
</template>
```

**Features built-in (gratis con Community)**:
- Sorting (click en header)
- Filtros (icono de filtro al hover en header)
- Resize de columnas
- Virtualización (10M de filas sin lag)
- Edición inline (`editable: true` por columna)
- Export a CSV (`api.exportDataAsCsv()`)

---

## Navegación entre páginas

```vue
<script setup>
import { Link, router } from '@inertiajs/vue3';
</script>

<template>
  <!-- Link declarativo (preferido para enlaces simples) -->
  <Link :href="route('users.index')">Ir a usuarios</Link>

  <!-- Programático (cuando necesitas hacer algo antes) -->
  <button @click="router.visit(route('users.index'))">Ir</button>

  <!-- POST / PUT / DELETE -->
  <button @click="router.delete(route('users.destroy', user.id))">Eliminar</button>
</template>
```

---

## Forms con Inertia

`useForm` te da un objeto reactivo + helpers para enviar y manejar errores:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
});

const submit = () => {
    form.post(route('users.store'), {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
  <Form @finish="submit">
    <FormItem label="Nombre" :validate-status="form.errors.name ? 'error' : ''" :help="form.errors.name">
      <Input v-model:value="form.name" />
    </FormItem>
    <FormItem label="Email" :validate-status="form.errors.email ? 'error' : ''" :help="form.errors.email">
      <Input v-model:value="form.email" />
    </FormItem>
    <Button type="primary" html-type="submit" :loading="form.processing">
      Guardar
    </Button>
  </Form>
</template>
```

Si Laravel valida y devuelve errores, `form.errors.email` se rellena automáticamente.

---

## Acceso a datos globales (auth, flash)

Definidos en `app/Http/Middleware/HandleInertiaRequests.php`. Disponibles en cualquier componente:

```vue
<script setup>
import { usePage } from '@inertiajs/vue3';

const page = usePage();

// Usuario actual
const user = page.props.auth.user;

// Flash messages después de un redirect
const flash = page.props.flash;

// Idioma actual
const locale = page.props.locale;
</script>
```

---

## Layouts compartidos

Crea un layout reusable en `resources/js/Layouts/AppLayout.vue`:

```vue
<script setup>
import { Layout, LayoutHeader, LayoutSider, LayoutContent } from 'ant-design-vue';
</script>

<template>
  <Layout style="min-height: 100vh">
    <LayoutSider>
      <!-- Sidebar -->
    </LayoutSider>
    <Layout>
      <LayoutHeader>
        <!-- Top bar -->
      </LayoutHeader>
      <LayoutContent>
        <slot />
      </LayoutContent>
    </Layout>
  </Layout>
</template>
```

Uso en una página:

```vue
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
defineOptions({ layout: AppLayout });
</script>

<template>
  <div>Contenido de mi vista</div>
</template>
```

---

## Convenciones del proyecto

| Convención | Ejemplo |
|---|---|
| Páginas en PascalCase | `Pages/People/Index.vue`, `Pages/FieldWork/Sign.vue` |
| Componentes reutilizables | `Components/Common/ResponsiveTable.vue` |
| Layouts | `Layouts/AppLayout.vue`, `Layouts/AuthLayout.vue` |
| Composables (lógica reutilizable) | `Composables/useModuleFilters.js` |
| Llamar a `route()`, nunca a una cadena literal | `route('business_management.people.show', person.slug)`, no `/people/${id}` |
| Importar los componentes de Ant Design uno a uno | `import { Button } from 'ant-design-vue'` (no el `Antd` global, aunque esté disponible) |
| Permisos con `useAuth`, no leyendo `page.props` | `const { can } = useAuth()` — así se respeta el bypass del super |

---

## Los espacios sobrantes se quitan solos

`Utils/espaciosLimpios.js`, enganchado una vez en `app.js`. **No hay que hacer
nada en cada campo**: cubre los 129 archivos con `<Input>` y los 12 con
`<Textarea>` sin tocar ninguno.

| Cuándo | Qué quita |
|---|---|
| Mientras se teclea | los espacios del principio y los dobles de en medio |
| Al salir del campo | el espacio del final |
| En un área de texto, además | el doble Enter (nada de párrafos vacíos) y el espacio antes del salto de línea |

Mientras se escribe se deja pasar **un** espacio final —si no, no se podrían
separar dos palabras— y ese se va al salir. El cursor se conserva: se puede
corregir en medio de una frase sin que salte al final.

Por qué importa: «Carlos  Gamarra», « Carlos Gamarra» y «carlos gamarra » son la
misma persona escrita de tres formas y el sistema las trata como tres. Un
espacio al final además no se ve en pantalla, así que nadie lo borra, y el
índice único da por distintas a dos filas que se leen igual. En la base del
sistema anterior salieron nombres con dos y con seis espacios seguidos.

**Dónde NO se toca nada:** la contraseña (un espacio suyo es un carácter como
cualquier otro), el buscador de un desplegable, el selector de fecha, el campo
numérico de Ant Design y el editor de texto enriquecido. Para dejar fuera un
campo concreto: `data-espacios="libre"`.

Va en fase de captura, así que limpia el valor **antes** de que Vue lo lea: al
`v-model` le llega limpio y al servidor no sale lo sucio. Los extremos los
garantiza además `TrimStrings` de Laravel, que corre en todas las peticiones.

---

## Antes de escribir un componente, mira si ya existe

`Components/Common/` es la parte más rentable de la base heredada. Un listado
nuevo no debería escribir su propia tabla, su propia barra de filtros ni su
propio diálogo de exportación:

| Componente | Para qué |
|---|---|
| `ResponsiveTable` | Tabla en escritorio, tarjetas en móvil, con el mismo `columns.js` |
| `FilterBar` + `FilterChips` + `AdvancedFilterDrawer` | Filtros simples, chips de lo aplicado y el constructor de condiciones |
| `ExportDialog` / `ImportDialog` | Exportar por formato (con su gateo de plan) e importar con vista previa |
| `SavedViews` | Guardar una combinación de filtros y columnas |
| `ColumnSelector` | Qué columnas se ven, con preferencia por usuario |
| `RecordHistory` / `ActivityTimeline` | El historial de auditoría de un registro |
| `EntityShowActions` / `EntityShowTabs` | La cabecera y las pestañas de una ficha |
| `RotatePortraitOverlay` | Pide girar el dispositivo. Existe por las tablets en obra |

Y en `Components/FormFields/` viven los tipos compuestos del motor de formatos
—`RiskMatrixField` (la matriz severidad × probabilidad de AST y PTF),
`PersonChecklistField` (el EPP por trabajador), `CatalogSelect`—. Un tipo de
campo nuevo se añade ahí, no dentro de la página que lo usa.

---

## La cámara de firma

La lógica de reconocimiento no vive en la página, vive en el composable
[`useFaceVerify`](../resources/js/Composables/useFaceVerify.js): carga los
modelos, detecta la cara, mide el gesto del reto de vida a partir de los 68
puntos y calcula el descriptor.

Dos cosas que hay que tener claras al tocar `Pages/FieldWork/Sign.vue`:

1. **El navegador no decide.** Manda el descriptor y la foto; el servidor recalcula
   la distancia y decide. La retroalimentación en vivo es para el usuario, no una
   autorización. En el sistema anterior el navegador enviaba `is_approved=1` en un
   campo oculto y bastaba abrir las herramientas de desarrollo para firmar como
   cualquiera.
2. **`getUserMedia()` solo existe en contextos seguros**: HTTPS o `localhost`.
   Probar desde una tablet contra `http://192.168.x.x` no abre la cámara, y el
   error no dice eso con claridad.

El detalle del flujo está en [`BIOMETRIA.md`](BIOMETRIA.md).

---

## Atajos útiles

| Atajo | Comando |
|---|---|
| Recompilar assets en vivo | `npm run dev` |
| Build de producción | `npm run build` |
| Ver tamaño del bundle | `npm run build` te lo muestra al final |
| Forzar refresh de Inertia | `router.reload()` |
| Forzar refresh manteniendo solo un prop | `router.reload({ only: ['users'] })` |

---

## Referencias externas

- Inertia: https://inertiajs.com/
- Vue 3: https://vuejs.org/
- Ant Design Vue: https://antdv.com/
- AG Grid: https://www.ag-grid.com/vue-data-grid/
- Tailwind 4: https://tailwindcss.com/

---

## Documentación relacionada

- [`STRUCTURE.md`](STRUCTURE.md) — dónde viven `Pages/`, `Components/`, `Composables/`, `Layouts/`
- [`PACKAGES.md`](PACKAGES.md) — versiones exactas de Vue, Inertia, Antd, AG Grid, Tailwind
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — por qué Inertia en vez de SPA con API REST
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — qué páginas Vue genera el scaffold (Index/Show/Form/Delete/Trash/EditAll)
- [`PERMISSIONS.md`](PERMISSIONS.md) — cómo se usan `can()` y `canUsePlanFeature()` en el frontend
- [`BIOMETRIA.md`](BIOMETRIA.md) — qué hace exactamente la pantalla de firma y por qué
