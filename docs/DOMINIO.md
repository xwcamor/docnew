# El dominio en una página

```
work_plan (la tarea del día)
 ├─ company            contratista que ejecuta
 ├─ work_type          define qué formatos son obligatorios
 ├─ work_location / workstation / work_area
 ├─ work_plan_people   trabajadores asignados
 ├─ work_plan_approvals aprobadores (supervisor, supervisor HSE)
 └─ form_submissions   los formatos llenados
       └─ form_answers / form_attachments
```

Cada firma —de trabajador o de aprobador— genera un `signature_event` con su `evidence_file`.
El resultado final es un PDF firmado con las fotos de quienes participaron.

## Los formatos

| Código | Nombre | Qué es |
| --- | --- | --- |
| AST | Análisis de Seguridad en el Trabajo | actividades → peligros → matriz severidad × probabilidad |
| PTF | Pare y Tome 5 | actividades/peligros + banco de preguntas |
| EPP | Equipo de Protección Personal | checklist por trabajador |
| IHM | Inspección de Herramientas Manuales | checklist por herramienta |
| HOJA X | cualquiera que defina el cliente | campos propios, o solo una foto del papel |

## Cambios de nombre respecto a la v1

| v1 (Rails) | v2 | Por qué |
| --- | --- | --- |
| `plans` | `work_plans` | en este SaaS `plans` ya son los planes de suscripción |
| `plan_workers` | `work_plan_people` | son personas, no un tipo aparte |
| `workers` + `supervisors` + `hse_supervisors` | `people` + `person_roles` | una identidad, varios roles |
| `locations` | `work_locations` | evita choque con el core del SaaS |
| `f1..f4_documents` | `form_submissions` | un solo modelo para todos los formatos |
