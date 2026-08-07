# El flujo, de punta a punta

Cómo se usa DOCUFIZ un día cualquiera, y qué hace el sistema en cada paso.

---

## 1. Antes del día (una vez, en oficina)

**Maestros → Empresas.** Se da de alta la contratista con su RUC.

**Maestros → Personas.** Se registra a cada trabajador y supervisor. Aquí está el cambio de fondo
respecto al sistema anterior: el alta **busca primero por documento**.

```
¿Existe alguien con ese DNI en este país?
 ├─ No           → se crea la identidad
 ├─ Sí           → NO se crea otra: solo se añade el vínculo con la empresa
 └─ Sí, borrada  → se restaura, con su historial
```

Un trabajador que rota de contratista conserva su identidad, su biometría y su firma. En el sistema
viejo cada alta creaba una persona nueva por empresa: 391 filas para 231 personas reales.

**Enrolamiento facial.** Con la cámara, guiado por un círculo que se pone verde cuando la cara está
bien encuadrada: 3 muestras de 128 valores cada una. **No se guarda ninguna foto**, solo los números
con los que después se compara. Requiere el consentimiento del trabajador.

**Maestros → Plantillas de formato.** Se define cada formato:

| Tipo | Para qué |
| --- | --- |
| `structured` | AST, PTF, EPP, IHM: campos, secciones, matriz de riesgo, checklists |
| `upload_only` | la "HOJA X": el papel existe, solo se le toma una foto |
| `hybrid` | unos campos más el papel adjunto |

Y se dice qué formatos exige cada **tipo de trabajo**. Un formato nuevo es configuración: no hay que
programar nada, que era lo que costaba entre 3 y 5 días por formato.

---

## 2. En la mañana (el supervisor, desde la tablet)

**Trabajo en obra → Planes de trabajo → Nuevo.**

Se elige empresa, tipo de trabajo, sede, puesto y se describe la tarea. Al guardar, el sistema ya
sabe qué formatos son obligatorios, porque los trae el tipo de trabajo.

Se añade la cuadrilla. Si alguien no está registrado, se le da de alta ahí mismo — y otra vez, si
esa persona ya existe en otra empresa, se reutiliza su identidad.

---

## 3. Durante el trabajo (llenar los formatos)

`field_work/work_plans/{plan}/forms`

Se ve la lista de formatos con su estado: pendiente, borrador o confirmado.

- **Formato con campos** → se llena el formulario. Cada respuesta se guarda en la columna que le
  corresponde a su tipo: los números como números, las fechas como fechas, la matriz de riesgo como
  JSON. No todo como texto.
- **HOJA X** → se toma la foto del papel y se adjunta. El archivo se deduplica por hash: la misma
  imagen no se guarda dos veces.

**Al confirmar, el servidor comprueba que no falte nada.** Si falta un campo obligatorio o, en un
formato de solo subida, falta el archivo, no deja cerrar y dice exactamente qué falta.

La entrega guarda **la versión de la plantilla** con la que se llenó: publicar una versión nueva del
formato no altera lo que ya se firmó.

---

## 4. La firma (el momento delicado)

`field_work/work_plans/{plan}/sign`

Cada trabajador de la cuadrilla y cada aprobador firma. El flujo, portado de tenkofiz:

```
La persona se identifica con su documento
        │
        ▼
El servidor entrega SOLO los descriptores de esa persona   ← verificación 1:1
        │
        ▼
La cámara compara en vivo y da feedback
        │
        ├─ 15 s sin ninguna cara en pantalla ──────────► se cancela, no se guarda nada
        │
        ├─ Coincide (distancia ≤ umbral) ──────────────► firma verificada
        │                                                 method: face_recognition
        │
        └─ 20 s con cara pero sin coincidir ──────────► captura de evidencia (8 s)
                                                          ├─ hay cara: toma la foto y DEJA FIRMAR
                                                          │   method: timeout_capture
                                                          │   pending_review: sí
                                                          └─ no hay cara: no se guarda nada
```

Tres reglas que no se negocian:

1. **La comparación la hace el servidor.** El navegador manda el descriptor y la foto; el servidor
   calcula la distancia contra la biometría enrolada, la guarda (`match_distance`, `threshold_used`)
   y decide. En el sistema viejo el navegador decidía y mandaba `is_approved=1` en un campo oculto:
   bastaba abrir las herramientas de desarrollo para firmar como cualquiera.
2. **La foto se guarda siempre que no hubo reconocimiento.** Antes, el 83 % de las fotos y el 96 % de
   las firmas eran la cadena `detected_by_IA` escrita en la columna del archivo. No había evidencia
   de nada.
3. **Nunca se bloquea el trabajo en campo.** Si no reconoce, se firma igual y queda marcado.

¿Y la firma manual? Existe, pero exige motivo, deja constancia de quién la autorizó, y solo la puede
autorizar alguien con permiso de revisión.

---

## 5. Después (el supervisor revisa)

`field_work/signatures/review`

La bandeja lista las firmas que quedaron pendientes, con la foto capturada, la distancia medida y el
umbral que se aplicó. El supervisor acepta o rechaza; **si rechaza, la aprobación se revierte**.

Las evidencias no son públicas: se sirven autenticadas y solo a quien tiene permiso de revisión.

---

## 6. El resultado

El plan queda completo cuando todos sus formatos están confirmados y todas las aprobaciones
obligatorias, aprobadas. Eso lo calcula el servidor (`WorkPlan::isComplete()`), nunca el formulario.

De ahí sale el PDF firmado con las fotos de quienes participaron, que es el documento que la empresa
necesita conservar.

---

## Permisos que gobiernan el flujo

| Permiso | Quién lo tiene | Qué habilita |
| --- | --- | --- |
| `work_plans.create` | supervisor, admin | armar el plan del día |
| `people.create` | supervisor, admin | dar de alta a la cuadrilla |
| `form_submissions.edit` | supervisor, usuario de campo | llenar los formatos |
| `form_submissions.sign` | supervisor, usuario de campo | firmar |
| `signature_events.review` | supervisor, auditor | resolver la bandeja y autorizar firmas manuales |

---

## Qué falta para poder usarlo

La lógica y las rutas están y están probadas. Faltan las **pantallas Vue** de llenado, firma y
bandeja de revisión, y el **kiosco de cámara** con face-api.js portado de tenkofiz. Ver
`docs/CHECKLIST.md`.
