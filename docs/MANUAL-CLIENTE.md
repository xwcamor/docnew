# Manual de uso de DOCUFIZ

Guía paso a paso para usar el sistema. Pensada para imprimir, compartir o adaptar a la puesta en marcha de cada empresa.

**DOCUFIZ es donde se llena y se firma la documentación diaria de seguridad en obra**: el AST, el Pare y Tome 5, la inspección de EPP, la de herramientas manuales y cualquier otro formato que use la empresa. Sustituye al papel: cada firma se hace con la cámara y queda guardada con la foto de quien firmó.

> Este manual es para el **usuario final** — administrador de la empresa, supervisor o trabajador. No tiene términos técnicos. Si eres desarrollador, revisa [`USAGE.md`](USAGE.md).

---

## Índice

1. [Primeros pasos](#1-primeros-pasos)
2. [Si eres administrador de la empresa](#2-si-eres-administrador-de-la-empresa)
3. [Un día de obra, paso a paso](#3-un-día-de-obra-paso-a-paso)
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

### 1.3. Cambiar tu contraseña inicial

**Esto es lo primero que tienes que hacer.** La contraseña inicial es solo para el primer acceso.

1. Una vez dentro, busca tu avatar (círculo con tu inicial) en la esquina superior derecha.
2. Haz clic en él → **Mi perfil**.
3. Ve a la pestaña de contraseña.
4. Introduce tu contraseña actual y la nueva.
5. Guarda.

### 1.4. Completar tu perfil

En la misma sección **Mi perfil** puedes:
- Subir una foto de perfil (JPG/PNG, hasta 2 MB).
- **Registrar tu firma manuscrita**, que es la que se imprime en los documentos.
- Cambiar tu nombre visible.
- Elegir el idioma: español o inglés.
- Definir tu zona horaria (las fechas se muestran en ese huso).

Los cambios se aplican de inmediato.

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
3. Asigna un **perfil**. Casi siempre uno de los cuatro que ya vienen listos (ver 2.2).
4. Guarda. La persona ya puede iniciar sesión.

**Importante**: comparte las credenciales por un medio seguro.

> **Un usuario no es lo mismo que un trabajador.** Un usuario es una cuenta que entra al sistema — normalmente los supervisores y poco más. Los trabajadores de la cuadrilla se registran en **Personas** (ver 2.3) y no necesitan cuenta: aparecen en el plan y firman con la cámara.

> El número máximo de usuarios depende de tu plan:
> - **Free** y **Basic**: 1 usuario
> - **Pro**: hasta 10 usuarios
> - **Enterprise**: sin límite
>
> Para una obra real necesitas plan **Pro** como mínimo: sin él no puedes dar de alta a tus supervisores.

### 2.2. Los perfiles que ya vienen listos

Un perfil define **qué puede hacer cada quien**. No hace falta inventarlos: el sistema trae cuatro, pensados para los papeles reales de una obra.

| Perfil | Para quién | Qué puede |
|---|---|---|
| **Supervisor de obra** | El que arma el plan del día | Crea el plan, registra a su cuadrilla, llena y firma los formatos, y revisa las firmas que quedaron dudosas. No elimina nada. |
| **Usuario de campo** | El trabajador que llena formatos | Llena y firma los formatos del plan al que está asignado. No crea planes ni da de alta personas. |
| **Auditor HSE (solo lectura)** | Quien audita | Ve y exporta todo, incluidas las fotos de firma. No modifica nada. |
| **Soporte (editor)** | Quien mantiene los datos | Crea y edita cualquier dato. No elimina y no firma. |

Si necesitas algo distinto puedes crear un perfil propio desde **Perfiles → Nuevo perfil**, marcando permiso a permiso. Solo en plan **Pro** o superior.

### 2.3. Preparar los maestros (una vez, en oficina)

Antes de que nadie salga a obra hay que dejar cuatro cosas cargadas:

**Empresas.** Las contratistas que ejecutan el trabajo, con su RUC.

**Personas.** Cada trabajador y cada supervisor. El alta **busca primero por documento**: si esa persona ya está registrada porque trabajó antes con otra contratista, el sistema **no crea otra ficha**, solo añade el vínculo con la empresa nueva. Así una persona que rota de contratista conserva su identidad, su enrolamiento facial y su firma.

**Enrolamiento facial.** Con la cámara, guiado por un círculo que se pone verde cuando la cara está bien encuadrada. Se toman tres muestras. **No se guarda ninguna foto**: solo una lista de números con la que después se compara. Requiere el consentimiento del trabajador, y queda registrado con fecha.

**Plantillas de formato.** Aquí se define cada formato: sus campos, sus secciones, su matriz de riesgo, sus listas de verificación. Un formato puede ser de tres tipos:

| Tipo | Para qué |
|---|---|
| Con campos | AST, Pare y Tome 5, EPP, IHM: se llena en pantalla |
| Solo foto | La "HOJA X": el papel existe y solo se le toma una foto |
| Mixto | Unos campos, más la foto del papel |

Y se indica **qué formatos exige cada tipo de trabajo**. Un formato nuevo es configuración: no hay que programar nada ni esperar a una actualización del sistema.

### 2.4. Trabajar con los listados

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

### 2.5. Recuperar registros eliminados

Cuando un usuario elimina un registro, **tiene 60 segundos para deshacer la acción** mediante el botón "Deshacer" del aviso que aparece arriba.

Pasados los 60 segundos, el registro queda en la papelera del sistema. **Solo el soporte de la plataforma puede recuperarlo** desde ahí. Si necesitas recuperar algo, contacta al soporte indicando:
- Qué registro (nombre o ID)
- Cuándo se eliminó (fecha aproximada)
- Quién lo eliminó (si lo sabes)

### 2.6. Ver el historial de cambios (auditoría)

> Solo el administrador de tu empresa entra aquí, en cualquier plan.

En el menú lateral: **Auditoría**. Allí ves un historial inmutable de cada acción realizada:
- Quién hizo qué cambio
- En qué registro
- Cuándo
- Qué campos modificó (vista de diferencias rojo→verde)

Útil para investigar un cambio o auditar el uso del sistema.

### 2.7. Ver y cambiar tu plan

En el dropdown de tu avatar (esquina superior derecha) ves:
- **Plan actual** de tu empresa
- **Días restantes** hasta el próximo vencimiento

Para cambiar de plan (subir o bajar), **contacta al soporte** indicando qué plan quieres. La plataforma no permite cambiar el plan directamente desde la interfaz (es por seguridad y para coordinar facturación).

### 2.8. Recibir mensajes del soporte

El sistema tiene una **bandeja de entrada** (icono de sobre en el header) donde llegan mensajes del soporte:
- Anuncios de mantenimiento programado
- Cambios en los planes
- Avisos importantes

Si el mensaje permite respuestas, puedes contestar y dialogar con el equipo de soporte.

---

## 3. Un día de obra, paso a paso

Esta es la parte que de verdad se usa todos los días.

### 3.1. Por la mañana — armar el plan (el supervisor, desde la tablet)

**Planes de trabajo → Nuevo.**

Se elige la empresa, el tipo de trabajo, la sede y el puesto, y se describe la tarea. Al guardar, el sistema **ya sabe qué formatos son obligatorios**, porque los trae el tipo de trabajo. No hay que acordarse de ninguno.

Después se añade la cuadrilla. Si alguien no está registrado, se le da de alta ahí mismo — y si esa persona ya existe porque trabajó con otra contratista, se reutiliza su ficha en vez de duplicarla.

### 3.2. Durante el trabajo — llenar los formatos

En el plan, la lista de formatos con su estado: **pendiente**, **borrador** o **confirmado**.

- **Formato con campos**: se llena en pantalla. Se puede guardar a medias y seguir después.
- **HOJA X**: se toma la foto del papel y se adjunta.

**Al confirmar, el sistema comprueba que no falte nada.** Si falta un campo obligatorio, o falta la foto en un formato de solo foto, no deja cerrar y dice exactamente qué falta.

Si más adelante la empresa publica una versión nueva de ese formato, **lo que ya está firmado no cambia**: cada entrega guarda la versión con la que se llenó.

### 3.3. La firma

Cada trabajador de la cuadrilla y cada aprobador firma desde la misma tablet.

1. La persona escribe su documento de identidad.
2. Se pone frente a la cámara. El sistema compara en vivo y avisa de cómo va.
3. Si reconoce, la firma queda hecha y verificada.

**¿Y si no reconoce?** Pasados unos segundos, el sistema **toma la foto igual, deja firmar y marca esa firma para que el supervisor la revise**. El trabajo en obra no se detiene nunca por un problema de cámara o de luz.

Solo hay una cosa que no ocurre jamás: **si no hay ninguna cara delante de la cámara, no se registra nada**. Ni firma ni foto.

También existe la firma manual, para casos excepcionales. Exige escribir un motivo, deja constancia de quién la autorizó, y solo la puede autorizar alguien con permiso de revisión.

### 3.4. Después — revisar lo que quedó dudoso

**Firmas → Bandeja de revisión.**

Lista las firmas que quedaron sin reconocer, con la foto que se capturó. El supervisor mira la foto y acepta o rechaza. Si rechaza, la aprobación se revierte.

Estas fotos **no son públicas**: solo las ve quien tiene permiso de revisión, y nunca hay un enlace que se pueda reenviar.

### 3.5. El resultado

El plan queda completo cuando todos sus formatos están confirmados y todas las aprobaciones obligatorias, aprobadas. De ahí sale el **PDF firmado** con las fotos de quienes participaron: el documento que la empresa conserva.

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
| **CSV** | Todos los planes | La mejor opción para volúmenes grandes: no tiene límite de filas |
| **Excel** | Basic+ | Hasta 25.000 filas |
| **PDF** | Basic+ | Hasta 500 filas. Es formato presentable, no volcado de datos |
| **Word** | Basic+ | Hasta 1.000 filas. Editable después |

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
- Si sigue sin funcionar, usa el enlace **¿Olvidaste tu contraseña?** del login.

**Olvidé mi contraseña**
- En la pantalla de login, clic en **¿Olvidaste tu contraseña?**
- Te pide tu correo electrónico.
- Recibirás un correo con un enlace para restablecerla (válido 60 minutos).
- Si no llega, revisa la carpeta de correo no deseado.
- Ese formulario admite pocos intentos por minuto. Si insistes muy seguido te dirá que esperes.

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
- Los archivos grandes pueden tardar varios minutos. Recibirás un correo y verás la campanita cuando esté listo.
- Si ya pasó una hora y no llega, contacta al soporte.

### Sobre la firma con la cámara

**La cámara no abre en la tablet**
- Casi siempre es porque se entró por una dirección que empieza en `http://`. Los navegadores solo dan acceso a la cámara si la dirección empieza en `https://`.
- Habla con el soporte para que te dé la dirección correcta.

**El sistema no me reconoce la cara**
- No pasa nada: pasados unos segundos toma la foto igual, te deja firmar y avisa a tu supervisor para que lo revise. **El trabajo no se detiene.**
- Si te ocurre siempre, pide que te vuelvan a enrolar: quizá el enrolamiento se hizo con muy poca luz.

**¿Guardan mi foto?**
- Del enrolamiento **no**: solo se guarda una lista de números con la que se compara. Con esos números no se puede reconstruir tu cara.
- De cada firma **sí** se guarda la foto del momento, porque es la prueba de quién firmó ese documento. Solo la ve tu supervisor o un auditor, y no hay ningún enlace que se pueda reenviar.
- Antes de enrolarte se te pide tu consentimiento, y queda registrado con la fecha.

**Cambiamos el formato AST. ¿Se estropean los que ya firmamos?**
- No. Cada documento guarda la versión del formato con la que se llenó. Lo firmado no cambia nunca.

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
| **Administrador** | Usuario principal de tu empresa. Gestiona cuentas, perfiles y los maestros. |
| **Usuario** | Una cuenta que entra al sistema. Normalmente los supervisores. |
| **Persona** (o **trabajador**) | Alguien que aparece en el plan y firma. **No necesita cuenta.** Una persona conserva su identidad aunque cambie de contratista. |
| **Perfil** (o **Rol**) | Conjunto de permisos. Por ejemplo "Usuario de campo" llena y firma, pero no crea planes. |
| **Permiso** | Acción concreta que un perfil puede ejecutar. Por ejemplo, firmar un formato. |
| **Plan de trabajo** | La tarea de un día concreto: empresa, tipo de trabajo, ubicación, cuadrilla y aprobadores. De él cuelgan los formatos. |
| **Formato** | Un documento de seguridad: AST, Pare y Tome 5, EPP, IHM o el que use tu empresa. |
| **Plantilla de formato** | La definición de un formato: sus campos y sus listas. Se configura una vez y se usa cada día. |
| **HOJA X** | Un formato del que solo se toma una foto del papel, porque no vale la pena pasarlo a campos. |
| **Enrolamiento** | Registrar tu cara una vez, para poder firmar después. No guarda ninguna foto, solo números. |
| **Bandeja de revisión** | Donde caen las firmas que el sistema no logró reconocer, para que el supervisor las mire. |
| **Evidencia** | La foto que se toma en el momento de firmar. Es la prueba de quién estuvo ahí. |
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
- Mantén tu perfil al día: foto, firma manuscrita, idioma y zona horaria.
- Si el sistema no te reconoce la cara, **no te detengas**: firma igual, queda marcado y tu supervisor lo revisa.
- Si vas a eliminar algo, hazlo con cuidado — el motivo es obligatorio y queda en la auditoría.
- Si quieres recuperar algo eliminado hace más de un minuto, contacta al soporte.

**Gracias por usar DOCUFIZ.**

---

## Documentación relacionada (para el equipo técnico)

- [`FLUJO.md`](FLUJO.md) — el mismo día de obra, con el detalle de qué hace el sistema en cada paso
- [`BIOMETRIA.md`](BIOMETRIA.md) — qué se guarda de la cara, cuándo y por qué
- [`USAGE.md`](USAGE.md) — versión técnica de este manual
- [`ACCESS-MODEL.md`](ACCESS-MODEL.md) — qué habilita cada permiso, en detalle
- [`plan-features.md`](plan-features.md) — qué se desbloquea en cada plan
