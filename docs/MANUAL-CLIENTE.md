# Manual de uso para el cliente

Guía paso a paso para usar el sistema desde la perspectiva del cliente final. Pensada para imprimir, compartir o adaptar al onboarding de cada empresa.

> Este manual es para el **usuario final del sistema** (administrador de la empresa o sus empleados). No tiene términos técnicos como Laravel, controllers, etc. Si eres developer trabajando en el proyecto, revisa [`USAGE.md`](USAGE.md) en su lugar.

---

## Índice

1. [Primeros pasos](#1-primeros-pasos)
2. [Si eres administrador de la empresa](#2-si-eres-administrador-de-la-empresa)
3. [Si eres empleado (usuario)](#3-si-eres-empleado-usuario)
4. [Funcionalidades que todos pueden usar](#4-funcionalidades-que-todos-pueden-usar)
5. [Preguntas frecuentes](#5-preguntas-frecuentes)
6. [Resolución de problemas comunes](#6-resolución-de-problemas-comunes)
7. [Glosario de términos](#7-glosario-de-términos)

---

## 1. Primeros pasos

### 1.1. Recibir las credenciales

El soporte de la plataforma te envía un correo con:
- **Dirección del sistema**: por ejemplo `https://miempresa.tudominio.com`
- **Tu correo de acceso**
- **Tu contraseña inicial**

### 1.2. Primer ingreso

1. Abre el navegador (Chrome, Firefox, Edge o Safari actualizado).
2. Ve a la dirección del sistema.
3. Verás la pantalla de inicio de sesión. Introduce tu correo y contraseña.
4. Haz clic en **Iniciar sesión**.

> 💡 Si te equivocas más de 5 veces, el sistema bloquea temporalmente tu cuenta por 15 minutos. Esto protege contra intentos malintencionados de adivinar contraseñas.

### 1.3. Cambiar tu contraseña inicial

**Esto es lo primero que tienes que hacer.** La contraseña inicial es solo para el primer acceso.

1. Una vez dentro, busca tu avatar (círculo con tu inicial) en la esquina superior derecha.
2. Haz clic en él → **Mi perfil**.
3. Ve a la pestaña **Cambiar contraseña**.
4. Introduce tu contraseña actual y la nueva (mínimo 8 caracteres, debe incluir letras y números).
5. Guarda.

### 1.4. Completar tu perfil

En la misma sección **Mi perfil** puedes:
- Subir una foto de perfil (JPG/PNG, máximo 2 MB).
- Cambiar tu nombre visible.
- Elegir el idioma de la interfaz: español o inglés.
- Definir tu zona horaria (las fechas del sistema se mostrarán en ese huso).

Los cambios se aplican inmediatamente.

---

## 2. Si eres administrador de la empresa

Como administrador eres el responsable principal de la cuenta de tu empresa. Te encargas de gestionar usuarios, permisos y los registros del negocio.

### 2.1. Crear empleados (usuarios)

1. En el menú lateral, ve a **Usuarios** → **Nuevo usuario**.
2. Completa los datos:
   - Nombre completo
   - Correo electrónico (será el usuario para iniciar sesión)
   - Contraseña inicial (puedes generar una al azar, el empleado la cambiará en su primer ingreso)
   - País e idioma
3. Asigna un **perfil** (rol) — por ejemplo "Editor de clientes" o "Visualizador".
4. Guarda. El empleado ya puede iniciar sesión.

**Importante**: comparte las credenciales con tu empleado por un medio seguro (no por correo público).

> El número máximo de usuarios que puedes crear depende de tu plan:
> - Plan **Free**: solo tú (1 usuario)
> - Plan **Basic**: hasta 5 usuarios
> - Plan **Pro**: hasta 25 usuarios
> - Plan **Enterprise**: sin límite

### 2.2. Crear perfiles (roles) personalizados

Los perfiles definen **qué puede hacer cada empleado**. Por ejemplo, puedes crear un perfil "Editor de clientes" que pueda crear y editar clientes pero no eliminarlos.

> Solo disponible en plan **Pro** o **Enterprise**.

1. En el menú lateral: **Perfiles** → **Nuevo perfil**.
2. Nombre del perfil (ej. "Editor de clientes").
3. Descripción (opcional).
4. Marca los **permisos** que tendrá:
   - `clientes.ver` — puede ver el listado y los detalles
   - `clientes.crear` — puede crear nuevos
   - `clientes.editar` — puede modificar
   - `clientes.eliminar` — puede eliminar (con motivo obligatorio)
5. Guarda.

Después, en el módulo **Usuarios**, edita a cada empleado y asígnale el perfil que corresponda.

### 2.3. Gestionar los registros de negocio (clientes, productos, etc.)

Tu empresa tiene un módulo principal de negocio (clientes) y posiblemente otros que te haya configurado el soporte (productos, ventas, inventario, etc.).

En cada módulo puedes:

| Acción | Cómo |
|---|---|
| **Ver el listado** | Menú lateral → módulo. Aparece con filtros, búsqueda y paginación. |
| **Crear un registro** | Botón **Nuevo** (arriba a la derecha) o atajo `Ctrl + N`. |
| **Editar** | Clic en el ícono lápiz en la fila correspondiente. |
| **Eliminar** | Clic en el ícono basura. Te pide un **motivo obligatorio** (mín. 3 caracteres). El registro pasa a la papelera. |
| **Filtrar** | Usa los campos arriba del listado o `Ctrl + F` para enfocar el buscador. |
| **Exportar** | Botón **Exportar** → elige formato (CSV / Excel / PDF / Word según tu plan). El export se procesa en segundo plano y recibirás una notificación cuando esté listo. |
| **Importar** | Botón **Importar** → descarga la plantilla → llénala → súbela. El sistema te muestra un preview antes de confirmar (disponible en plan Pro+). |

### 2.4. Recuperar registros eliminados

Cuando un usuario elimina un registro, **tiene 60 segundos para deshacer la acción** mediante el botón "Deshacer" del aviso que aparece arriba.

Pasados los 60 segundos, el registro queda en la papelera del sistema. **Solo el soporte de la plataforma puede recuperarlo** desde ahí. Si necesitas recuperar algo, contacta al soporte indicando:
- Qué registro (nombre o ID)
- Cuándo se eliminó (fecha aproximada)
- Quién lo eliminó (si lo sabes)

### 2.5. Ver el historial de cambios (auditoría)

> Solo disponible en plan **Basic** o superior.

En el menú lateral: **Auditoría**. Allí ves un historial inmutable de cada acción realizada:
- Quién hizo qué cambio
- En qué registro
- Cuándo
- Qué campos modificó (vista de diferencias rojo→verde)

Útil para investigar un cambio o auditar el uso del sistema.

### 2.6. Ver y cambiar tu plan

En el dropdown de tu avatar (esquina superior derecha) ves:
- **Plan actual** de tu empresa
- **Días restantes** hasta el próximo vencimiento

Para cambiar de plan (subir o bajar), **contacta al soporte** indicando qué plan quieres. La plataforma no permite cambiar el plan directamente desde la interfaz (es por seguridad y para coordinar facturación).

### 2.7. Recibir mensajes del soporte

El sistema tiene una **bandeja de entrada** (icono de sobre en el header) donde llegan mensajes del soporte:
- Anuncios de mantenimiento programado
- Cambios en los planes
- Avisos importantes

Si el mensaje permite respuestas, puedes contestar y dialogar con el equipo de soporte.

---

## 3. Si eres empleado (usuario)

Trabajas dentro de tu empresa con permisos específicos que tu administrador te asignó.

### 3.1. Tu acceso

- Solo ves los **módulos que tu administrador habilitó para tu perfil**.
- Dentro de cada módulo, solo ves los **registros de tu empresa** (no de otras).
- Las acciones disponibles (crear, editar, eliminar) dependen de tus permisos.

### 3.2. Día a día

1. **Iniciar sesión** con tu correo y contraseña.
2. Trabajar en los módulos que te corresponden (típicamente clientes, productos, ventas, etc.).
3. **Mantener tu perfil al día**: foto, contraseña, idioma, zona horaria.
4. **Revisar la bandeja de entrada** por si hay mensajes del soporte.

### 3.3. Lo que NO puedes hacer

- Crear nuevos usuarios (es función del administrador de tu empresa).
- Ver registros de otras empresas (cada empresa está aislada).
- Cambiar el plan de tu empresa.
- Recuperar registros eliminados pasados los 60 segundos (contacta a tu administrador o al soporte).

### 3.4. Si necesitas más permisos

Habla con el administrador de tu empresa. Él decide qué permisos asignar a tu perfil.

---

## 4. Funcionalidades que todos pueden usar

### 4.1. Búsqueda y filtros

En cada listado tienes:
- **Buscador rápido**: arriba del listado, busca por nombre o palabras clave.
- **Filtros avanzados**: por estado, fecha, categoría, etc. (varía por módulo).
- **Chips de filtros activos**: muestran qué filtros tienes aplicados. Clic en la "x" para quitarlos.

### 4.2. Vistas guardadas

> Disponible en plan **Basic** o superior.

Si siempre filtras por lo mismo (ej. "clientes activos del último mes"), guarda esa combinación de filtros + columnas como una **vista**:

1. Aplica los filtros y columnas que quieras.
2. Botón **Guardar vista** → ponle un nombre.
3. Después, accede a esa vista con un solo clic desde el menú de vistas guardadas.

### 4.3. Columnas personalizables

En cada listado puedes mostrar / ocultar columnas:

1. Botón **Columnas** (arriba del listado).
2. Marca / desmarca las que quieres ver.
3. Tu elección se guarda automáticamente para la próxima vez.

### 4.4. Favoritos

Marca como favorito los registros que más usas con la estrella `★`. Los favoritos aparecen siempre arriba del listado.

### 4.5. Exportar datos

Botón **Exportar** → elige formato según tu plan:

| Formato | Disponible en | Para qué |
|---|---|---|
| **CSV** | Todos los planes | Mejor opción para datasets grandes (sin límite de filas) |
| **Excel** | Basic+ | Hasta 25.000 filas. Conserva colores y formato |
| **PDF** | Basic+ | Hasta 5.000 filas. Ideal para imprimir o adjuntar |
| **Word** | Basic+ | Hasta 10.000 filas. Editable después |

> El export se procesa en **segundo plano**. Verás una notificación en la campanita 🔔 del header cuando esté listo, y también recibirás un correo con el enlace de descarga. El archivo está disponible por 24 horas; después se elimina automáticamente.

### 4.6. Importar datos (Basic+)

Sirve para cargar muchos registros de golpe desde un archivo Excel o CSV:

1. Botón **Importar**.
2. Descarga la **plantilla** (ya viene con los nombres correctos de columnas).
3. Llena la plantilla con tus datos.
4. Sube el archivo.
5. El sistema te muestra un **preview** con los registros válidos / con errores.
6. Si todo está bien, confirma. Si hay errores, los corriges en el archivo y vuelves a subir.

> Disponible en plan **Pro** o superior.

### 4.7. Notificaciones

El icono campanita 🔔 (arriba a la derecha) te avisa cuando:
- Un export está listo para descargar
- Una automatización se ejecutó (si aplica)

El icono sobre ✉️ te avisa cuando:
- Llegó un mensaje del soporte

Si hay novedades, el icono muestra un número con la cantidad de elementos sin leer.

### 4.8. Cambiar idioma e idioma de la app

Icono globo 🌐 (header) → elige idioma: español o inglés. La interfaz cambia inmediatamente y tu preferencia queda guardada.

### 4.9. Modo claro / oscuro

Icono monitor (header) → cicla entre **claro**, **oscuro** y **automático** (sigue la configuración del sistema operativo).

### 4.10. Atajos de teclado útiles

| Atajo | Acción |
|---|---|
| `Ctrl + N` | Crear un nuevo registro (en listados) |
| `Ctrl + F` | Foco en el buscador (en listados) |
| `Esc` | Cerrar modal / diálogo / cancelar acción |

---

## 5. Preguntas frecuentes

### Sobre el acceso

**No puedo iniciar sesión, dice "credenciales incorrectas"**
- Verifica que estés escribiendo el correo correcto (sin espacios al principio o final).
- Asegúrate de que la contraseña esté bien escrita (mayúsculas y minúsculas importan).
- Si tienes 5 intentos fallidos, espera 15 minutos.
- Si sigue sin funcionar, usa el enlace **¿Olvidaste tu contraseña?** del login.

**Olvidé mi contraseña**
- En la pantalla de login, clic en **¿Olvidaste tu contraseña?**
- Te pide tu correo electrónico.
- Recibirás un correo con un enlace para restablecerla (válido 60 minutos).
- Si no llega, revisa la carpeta de spam.

**Estoy bloqueado por intentos fallidos**
- Espera 15 minutos y vuelve a intentar.
- O bien usa el reset por correo.

### Sobre permisos y módulos

**No veo un módulo que mi compañero sí ve**
- Tu perfil no tiene permiso de ver ese módulo.
- Habla con el administrador de tu empresa para que te asigne los permisos.

**No puedo eliminar un registro**
- Tu perfil no tiene el permiso `eliminar`.
- Solo el administrador o un perfil con ese permiso puede eliminar.

**No veo la opción "Exportar PDF"**
- Tu plan no incluye exports avanzados. Necesitas plan **Basic** o superior.
- Habla con tu administrador o con el soporte de la plataforma.

### Sobre datos

**Borré algo por error**
- Tienes 60 segundos para usar el botón **Deshacer** del aviso que aparece arriba.
- Pasados los 60 segundos, contacta al soporte indicando qué registro recuperar.

**El export está tardando mucho**
- Los exports grandes (> 5.000 registros en PDF, > 25.000 en Excel) pueden tardar varios minutos.
- Recibirás un correo y verás la campanita 🔔 cuando esté listo.
- Si ya pasó 1 hora y no llega, contacta al soporte.

**Recibí un mensaje en la bandeja de entrada, ¿cómo respondo?**
- Abre el mensaje en la **Bandeja de entrada** (icono sobre del header).
- Si el mensaje permite respuestas, verás un editor de texto al final.
- Si no permite respuestas, solo es un anuncio (sin canal de retorno).

### Sobre el plan

**¿Qué plan tengo?**
- Clic en tu avatar (esquina superior derecha) → línea "Plan".

**Mi plan se acaba pronto**
- A 7 días o menos del vencimiento, recibirás un correo de aviso.
- Si quieres renovar, contacta al soporte.
- Si no renuevas, tu cuenta cae al plan Free automáticamente — sigues teniendo acceso pero con las limitaciones de ese plan.

**¿Cómo subo de plan?**
- Contacta al soporte indicando a qué plan quieres pasar.
- El cambio se aplica inmediatamente tras el pago.

---

## 6. Resolución de problemas comunes

| Problema | Solución |
|---|---|
| La página queda en blanco | Recarga con `Ctrl + Shift + R` (recarga forzada que ignora la caché). |
| No carga después de iniciar sesión | Cierra el navegador completamente y abre de nuevo. Si persiste, prueba en modo incógnito. |
| El sistema se ve raro / desordenado | Limpia la caché del navegador: `Ctrl + Shift + Del` → "Imágenes y archivos en caché" → Borrar. |
| Una imagen subida no aparece | Espera 5 segundos y recarga la página. Si persiste, vuelve a subirla. |
| Un export nunca llega | Verifica la carpeta de spam de tu correo. Si tampoco está, contacta al soporte. |
| Mensaje "Sin conexión" o "Error de red" | Verifica tu conexión a internet. Si la conexión está bien, el servidor puede estar caído — espera 5 minutos. |

### Cuándo contactar al soporte

- No puedes iniciar sesión tras intentar reset de contraseña.
- Borraste algo por error y pasaron los 60 segundos.
- Necesitas cambiar de plan.
- Una funcionalidad no funciona como esperas.
- Encuentras un error que no entiendes.

**Cómo contactar**: el correo del soporte aparece en el pie de página del sistema y en el dropdown de tu avatar.

---

## 7. Glosario de términos

| Término | Significado |
|---|---|
| **Administrador** | Usuario principal de tu empresa. Gestiona empleados, perfiles y los registros de negocio. |
| **Empleado** o **Usuario** | Persona dentro de tu empresa con permisos específicos. |
| **Perfil** (o **Rol**) | Conjunto de permisos. Por ejemplo "Editor de clientes" puede crear y editar, pero no eliminar. |
| **Permiso** | Acción concreta que un perfil puede ejecutar. Ejemplo: `clientes.editar`. |
| **Plan** | El nivel de servicio contratado por tu empresa. Define qué features están disponibles y cuántos usuarios admites. |
| **Workspace** | El espacio aislado de tu empresa dentro del sistema. Tu empresa no comparte datos con ninguna otra. |
| **Papelera** | Donde van los registros eliminados. Solo el soporte puede acceder a ella. |
| **Auditoría** | Historial inmutable de cada acción realizada en el sistema. Sirve para investigar quién hizo qué cambio. |
| **Suscripción** | Período pago de tu plan con fechas de inicio y vencimiento. |
| **Export / Exportar** | Generar un archivo (Excel, PDF, etc.) con los registros del listado para descargar. |
| **Import / Importar** | Subir un archivo con muchos registros de golpe para que el sistema los cree. |
| **Bandeja de entrada** | Donde llegan los mensajes del soporte de la plataforma. |
| **Notificación** | Aviso en la campanita 🔔 del header. Aparece cuando un export termina o una automatización se ejecutó. |
| **Soft-delete** | Eliminación reversible. El registro no se borra realmente; queda en la papelera. |
| **Force-delete** | Eliminación definitiva e irreversible. Solo el soporte puede ejecutarla. |
| **Vista guardada** | Combinación de filtros + columnas + orden que guardas para reusar después. |
| **Favoritos** | Registros marcados con estrella ★ que aparecen siempre arriba del listado. |

---

## 8. Para terminar

Este sistema está diseñado para ser **fácil de usar** sin necesidad de capacitación técnica. Si algo no encaja con tu intuición, **probablemente sea un error nuestro de diseño**, no tuyo. Avísanos al soporte para mejorarlo.

**Recuerda**:
- Cambia tu contraseña inicial en el primer ingreso.
- Mantén tu perfil al día (foto, idioma, zona horaria).
- Si va a eliminar algo, hágalo con cuidado — el motivo es obligatorio para auditoría.
- Si quieres recuperar algo eliminado hace más de 1 minuto, contacta al soporte.
- El soporte está para ayudarte — no dudes en escribirnos.

**Gracias por usar la plataforma.**

---

## Documentación relacionada (para el equipo técnico)

- [`USAGE.md`](USAGE.md) — versión con jerga técnica de este mismo manual (para developers)
- [`PERMISSIONS.md`](PERMISSIONS.md) — cómo se gestionan los roles internamente
- [`AUTOMATIONS.md`](AUTOMATIONS.md) — referencia técnica de las automatizaciones del workspace
- [`plan-features.md`](plan-features.md) — qué se desbloquea en cada plan
