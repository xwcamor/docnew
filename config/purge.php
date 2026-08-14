<?php

/*
|--------------------------------------------------------------------------
| Soft-delete purge policy per module
|--------------------------------------------------------------------------
|
| Define after how many days each module's soft-deleted records become
| eligible for hard-delete. Records younger than `days` are kept; older
| are physically removed when `app:purge-soft-deleted` runs.
|
| Per-module options:
|   - model:    FQCN of the Eloquent model (must use SoftDeletes)
|   - days:     grace window in days (counted since deleted_at)
|   - anonymize: if true, replaces PII fields with random data BEFORE
|                deleting (relevant for users/patients with sensitive cols).
|                List the columns to anonymize.
|   - chunk:    batch size for the delete (default 500). Tune for tables
|               with very large rows.
|
| Adjust these by use-case:
|   - Catalog data (regions, languages, countries): days = 365
|   - Transactional data (tenants, settings):       days = 90
|   - PII data (users, patients):                   days = 30 + anonymize
|   - Time-series append-only:                      days = configurable per business
|
| Set days = 0 in env-specific overrides to disable purging for a module.
*/

/*
|--------------------------------------------------------------------------
| Plazos de conservación — tres relojes distintos, no uno
|--------------------------------------------------------------------------
|
| `people`, `work_plans` y `form_templates` estaban los tres en 90 días. Ese
| número venía clonado del módulo del que se copió este producto; nadie lo
| eligió pensando en documentación de seguridad en obra. Y aunque alguien lo
| hubiera elegido, seguiría estando mal puesto: aquí conviven tres cosas que
| no aguantan el mismo reloj.
|
|   CATÁLOGO — una marca, un tipo de trabajo, una plantilla de formato.
|   Define cómo se trabaja, no documenta a nadie ni prueba nada. Borrar uno
|   pasado un tiempo prudencial no destruye evidencia: como mucho hay que
|   volver a darlo de alta.
|
|   FICHA DE PERSONA — el nombre, el documento y la foto de alguien real.
|   Los dos errores duelen en direcciones opuestas: conservarla de más es
|   guardar datos de una persona que ya no trabaja aquí, y borrarla de menos
|   deja sin identificar a quien firmó.
|
|   EVIDENCIA DE JORNADA — un plan de trabajo es quién estuvo en la obra ese
|   día, qué se verificó y quién lo firmó. Purgarlo NO libera espacio: borra
|   la prueba de que esa jornada se documentó. Si más adelante hay un
|   accidente, una inspección o un reclamo sobre ese día, lo que se puede
|   enseñar es lo que quede aquí — y lo que se purgó no vuelve.
|
| CUÁNTO hay que conservar cada cosa NO se decide en este archivo. Depende
| de la jurisdicción del cliente, del tipo de obra y de lo que firmen sus
| contratos, y ese dato no lo tenemos. Poner aquí un número con pinta de
| plazo legal sería peor que no poner ninguno: se lee como si alguien lo
| hubiera comprobado.
|
| Por eso los dos plazos que tocan a personas y a jornadas vienen en 0
| —purga DESACTIVADA— a propósito. Entre conservar de más y borrar evidencia
| según un número que nadie eligió, el defecto conserva. El catálogo sí trae
| plazo: ahí no hay nada que probar.
|
| QUÉ HAY QUE HACER: fijar los tres números con quien lleve el asunto en
| casa del cliente —su asesoría legal, su responsable de prevención, o quien
| firme los contratos de obra— y ponerlos en el `.env` del despliegue:
|
|   PURGE_DAYS_CATALOG=365
|   PURGE_DAYS_PERSON_RECORD=0     # 0 = no purgar. A fijar con el cliente.
|   PURGE_DAYS_WORK_EVIDENCE=0     # 0 = no purgar. A fijar con el cliente.
|
| Mientras sigan en 0, `app:purge-soft-deleted` los salta e imprime
| «skipping (days <= 0, deshabilitado)» en `storage/logs/purge.log`: la
| decisión pendiente queda a la vista cada noche, no escondida.
|
| Ojo con lo que mide el reloj: cuenta desde `deleted_at`, no desde la fecha
| de la jornada. Un plan de 2019 que alguien borró ayer empieza a contar
| ayer. Eso es lo que se quiere —el plazo protege del borrado accidental
| reciente— pero no sustituye a un archivado por antigüedad si el cliente
| pide justo lo contrario.
*/

$diasCatalogo        = (int) env('PURGE_DAYS_CATALOG', 365);
$diasFichaDePersona  = (int) env('PURGE_DAYS_PERSON_RECORD', 0);
$diasEvidenciaDeObra = (int) env('PURGE_DAYS_WORK_EVIDENCE', 0);

return [
    'modules' => [
        'regions' => [
            'model' => \App\Models\Region::class,
            'days'  => 365,
        ],
        'languages' => [
            'model' => \App\Models\Language::class,
            'days'  => 365,
        ],
        'countries' => [
            'model' => \App\Models\Country::class,
            'days'  => 365,
        ],
        'locales' => [
            'model' => \App\Models\Locale::class,
            'days'  => 365,
        ],
        'tenants' => [
            'model' => \App\Models\Tenant::class,
            'days'  => 90,
        ],
        'system_modules' => [
            'model' => \App\Models\SystemModule::class,
            'days'  => 365,
        ],
        'users' => [
            'model'     => \App\Models\User::class,
            'days'      => 30,
            // PII completa: nombre, email, foto, google_id + password (hash).
            // El campo `module_tours` es jsonb sin PII per-se, no se anonimiza.
            'anonymize' => ['name', 'email', 'photo', 'signature', 'google_id', 'password'],
        ],
        // ─── Negocio per-tenant ─────────────────────────────────────────
        'customers' => [
            'model'     => \App\Models\Customer::class,
            'days'      => 90,
            // Customer puede ser una persona física — anonimizamos identificadores.
            // `cod` es código interno del tenant pero puede contener DNI/CUIT/etc.
            'anonymize' => ['name', 'cod'],
        ],
        'roles' => [
            'model' => \App\Models\Role::class,
            'days'  => 180,
            // No PII — roles son metadata. Solo retention para mantener trazabilidad.
        ],
        'automations' => [
            'model' => \App\Models\Automation::class,
            'days'  => 90,
            // trigger_config / action_config pueden contener emails de destinatarios.
            // Anonimizamos action_config (json) reemplazando con marker; description
            // queda como string anodino. No usamos lista plana porque son jsonb.
            'anonymize' => ['description'],
        ],
        // ─── Catálogo de billing ────────────────────────────────────────
        'plans' => [
            'model' => \App\Models\Plan::class,
            'days'  => 365,
        ],
        'subscriptions' => [
            'model' => \App\Models\Subscription::class,
            'days'  => 365,
            // History de pagos — retención larga (audit + reportes históricos).
        ],
        'settings' => [
            'model' => \App\Models\Setting::class,
            'days'  => 365,
        ],
        'companies' => [
            'model' => \App\Models\Company::class,
            'days'  => 90,
        ],
        // Ficha de persona: nombre, documento y foto de alguien real. Ver el
        // bloque «Plazos de conservación» de arriba — viene desactivado a
        // propósito hasta que el cliente fije su plazo.
        'people' => [
            'model' => \App\Models\Person::class,
            'days'  => $diasFichaDePersona,
        ],
        // Evidencia de una jornada de obra. Purgar un plan borra la prueba de
        // quién estuvo, qué se verificó y quién lo firmó ese día. Desactivado
        // a propósito; ver el bloque de arriba.
        'work_plans' => [
            'model' => \App\Models\WorkPlan::class,
            'days'  => $diasEvidenciaDeObra,
        ],
        // Catálogo: la plantilla define cómo se llena un formato, no es el
        // formato lleno. Los formatos llenos (`form_submissions`) no se purgan
        // desde aquí — son evidencia y no tienen entrada en esta lista.
        'form_templates' => [
            'model' => \App\Models\FormTemplate::class,
            'days'  => $diasCatalogo,
        ],
        // Catálogo: quién puede aprobar y con qué regla. Configuración del
        // workspace, no rastro de nadie. La aprobación firmada de un plan vive
        // en `work_plan_approvals` y esta purga no la toca.
        'approver_roles' => [
            'model' => \App\Models\ApproverRole::class,
            'days'  => $diasCatalogo,
        ],
        'approval_rules' => [
            'model' => \App\Models\ApprovalRule::class,
            'days'  => $diasCatalogo,
        ],
        // Suma modulos nuevos aqui:
        // 'patients' => [...],
        // 'doctors'  => [...],
        // 'transformers' => [...],
    ],

    /*
    |--------------------------------------------------------------------------
    | Historial de cambios (`audit_logs`) — política de conservación
    |--------------------------------------------------------------------------
    |
    | `audit_logs` no se purgaba nunca. Es una tabla append-only sin
    | `deleted_at`, así que `app:purge-soft-deleted` ni la miraba, y en
    | `old_values`/`new_values` guarda **copias literales de las filas**: el
    | nombre, el documento y el teléfono de una persona quedaban ahí para
    | siempre, incluidos los de gente que ya se borró del padrón. Purgar la
    | ficha y dejar el historial es purgar a medias.
    |
    | Lo purga `app:purge-audit-logs`. Lo que sigue es la política, y el
    | porqué de cada número.
    |
    | ── Por qué no basta con «borrar lo viejo» ───────────────────────────
    |
    | Un historial que se borra entero deja de servir para auditar. La
    | pregunta que se le hace a esta tabla no es «¿qué decía el teléfono de
    | Fulano en marzo?», es «¿quién tocó esto, cuándo, y desde dónde?». Esas
    | cuatro columnas —user_id, event, auditable_id, created_at— no son datos
    | personales de nadie: son el rastro. Los datos personales están en el
    | contenido, en las dos columnas JSON.
    |
    | De ahí que haya DOS relojes y no uno, y que el primero no borre filas:
    |
    |   1. PODA DEL CONTENIDO (`redact_after_days`). Pasado el plazo se
    |      vacían `old_values`/`new_values` y la fila se queda. Se pierde el
    |      «de qué a qué», se conserva el «quién, qué, cuándo y desde dónde».
    |      Esto es lo que de verdad cierra el agujero de privacidad, y cuesta
    |      poquísimo en capacidad de auditar.
    |
    |   2. BORRADO DE LA FILA. Aquí sí desaparece el rastro, y por eso va
    |      mucho más tarde y con dos plazos distintos según lo que la fila
    |      cuente (abajo).
    |
    | ── Por qué los eventos de seguridad duran más ───────────────────────
    |
    | Un `updated` de un catálogo dice que alguien renombró un tipo de
    | trabajo. Un `login_lockout` dice que alguien estuvo probando
    | contraseñas contra una cuenta, y un `created` en `person_signatures`
    | dice que una persona firmó en obra. No se consultan en el mismo plazo
    | ni por el mismo motivo: lo primero se mira la semana siguiente si es
    | que se mira, y lo segundo se mira cuando hay una investigación, un
    | reclamo o una revisión de accesos — y esas llegan tarde por definición.
    |
    | Los eventos de consentimiento (`terms_accepted`, autofirma) son un caso
    | aparte y todavía más claro: no son telemetría, son la prueba de que el
    | usuario aceptó algo, y él mismo se los descarga desde su perfil
    | (ProfileController::exportData). Purgarlos le vacía esa pantalla.
    |
    | ── Los números, y qué son y qué no son ──────────────────────────────
    |
    | 180 / 365 / 1095 son valores de arranque razonados, NO plazos legales.
    | El razonamiento es este:
    |
    |   - 180 días de contenido: cubre de sobra la ventana en la que alguien
    |     va a querer ver «qué cambió exactamente» para deshacer un error o
    |     entender una discrepancia. Pasado medio año, lo que se consulta es
    |     el rastro, no el diff.
    |   - 365 días de rastro corriente: un año cierra el ciclo completo de
    |     revisiones internas y auditorías anuales. Coincide además con el
    |     valor que ya estaba sembrado en `audit.retention_days`, que es el
    |     único número de esta zona que alguien sí había puesto a conciencia.
    |   - 1095 días (3 años) de seguridad y firma: el triple, porque es el
    |     tipo de pregunta que aparece años después y porque estas filas son
    |     pocas y ligeras — conservarlas no pesa.
    |
    | Si el cliente tiene un plazo propio, manda el suyo. Los tres se ajustan
    | sin redeploy desde Ajustes (`audit.*`), y los de aquí son solo el
    | defecto de fábrica.
    */
    'audit_logs' => [
        // Se vacían `old_values`/`new_values` de las filas más viejas que
        // esto. La fila NO se borra. Setting: `audit.redact_payload_days`.
        'redact_after_days' => (int) env('AUDIT_REDACT_AFTER_DAYS', 180),

        // Los eventos cuyo contenido ES el hecho, y podarlo deja la fila sin
        // significado: en un intento fallido el contenido es el correo con el
        // que se probó (lo único que distingue un tecleo torpe de alguien
        // barriendo cuentas), y en un `purged` es cuántas filas se destruyeron
        // y con qué corte. Estos aguantan hasta que se borre la fila entera.
        'keep_payload_events' => [
            'login_failed',
            'login_lockout',
            'purged',
        ],

        // Borrado de la fila — rastro corriente (cambios de catálogo y de
        // negocio). Setting: `audit.retention_days`.
        'days' => (int) env('AUDIT_RETENTION_DAYS', 365),

        // Borrado de la fila — seguridad, firma y consentimiento. Nunca por
        // debajo de `days`: el comando aplica el mayor de los dos, para que
        // subir la retención corriente no acabe acortando la de seguridad.
        // Setting: `audit.security_retention_days`.
        'security_days' => (int) env('AUDIT_SECURITY_RETENTION_DAYS', 1095),

        // Qué cuenta como evento de seguridad, por nombre de evento.
        // Accesos y bloqueos los escribe RegistraElAccesoAlSistema; los de
        // consentimiento, ProfileController; `personal_data_exported` y
        // `account_deletion_requested` son el rastro de ejercicio de derechos
        // del propio usuario; `force_deleted` y `purged` son destrucciones de
        // datos, y borrar el registro de una destrucción es la única pérdida
        // de esta tabla que no se puede reconstruir con nada.
        'security_events' => [
            'login',
            'logout',
            'login_failed',
            'login_lockout',
            'login_blocked',
            'terms_accepted',
            'report_autosign_granted',
            'report_autosign_revoked',
            'personal_data_exported',
            'account_deletion_requested',
            'force_deleted',
            'purged',
        ],

        // Y qué cuenta como evento de seguridad por MÓDULO, sea cual sea el
        // evento. Son las cuatro tablas donde un `created` significa «alguien
        // firmó» o «alguien aprobó»: la firma de una persona en obra, el
        // evento facial que la respalda, la aprobación de un plan y el
        // formato llenado. Un `updated` aquí tampoco es rutina — es alguien
        // tocando una firma ya registrada.
        'security_modules' => [
            'person_signatures',
            'signature_events',
            'work_plan_approvals',
            'form_submissions',
        ],

        // Tamaño de lote. Más alto que el de los módulos con soft-delete
        // porque estas filas son pequeñas y pueden ser muchísimas.
        'chunk' => 1000,
    ],
];
