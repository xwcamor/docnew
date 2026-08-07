<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SettingsSeeder — config global del sistema. Idempotente vía updateOrInsert
 * por `key`. Re-runable sin duplicar.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ─── Grupo: app ────────────────────────────────────────────────────
            ['key' => 'app.maintenance_mode',  'name' => 'Modo mantenimiento',       'type' => 'bool',   'value' => 'false', 'group' => 'app',      'description' => 'Bloquea acceso al sistema (excepto super). Muestra página 503 a usuarios normales.'],
            ['key' => 'app.support_email',     'name' => 'Email de soporte',         'type' => 'string', 'value' => 'soporte@example.com', 'group' => 'app', 'description' => 'Email mostrado al usuario para contacto (footer, página de mantenimiento, errores).'],
            ['key' => 'app.name',              'name' => 'Nombre de la aplicación',  'type' => 'string', 'value' => 'TrafoDex', 'group' => 'app', 'description' => 'Nombre comercial mostrado en login, header, emails y título del browser.'],
            ['key' => 'app.logo_url',          'name' => 'URL del logo',             'type' => 'string', 'value' => '', 'group' => 'app', 'description' => 'URL absoluta o relativa al logo de la marca. Si vacío se muestra solo el nombre.'],
            ['key' => 'app.default_locale',    'name' => 'Idioma por defecto',       'type' => 'string', 'value' => 'es', 'group' => 'app', 'description' => 'Idioma asignado a usuarios nuevos cuando no se especifica (es / en / pt).'],
            ['key' => 'legal.terms_version',   'name' => 'Versión de Términos y Privacidad', 'type' => 'string', 'value' => '1.0', 'group' => 'app', 'description' => 'Versión vigente de los Términos y la Política de Privacidad. Al SUBIRLA (ej. 1.1), todos los usuarios deberán aceptar de nuevo en su próxima sesión (la aceptación queda registrada con fecha y versión — LPDP).'],

            // ─── Grupo: features (toggles globales) ───────────────────────────
            ['key' => 'features.audit_log_enabled', 'name' => 'Audit log activo',    'type' => 'bool',   'value' => 'true',  'group' => 'features', 'description' => 'Si false, los modelos con trait Auditable no escriben en audit_logs. Útil para reducir writes en planes free.'],
            ['key' => 'features.subscription_enforcement_enabled', 'name' => 'Forzar suscripción activa', 'type' => 'bool', 'value' => 'false', 'group' => 'features', 'description' => 'Si true, tenants sin sub activa (o expirada) quedan bloqueados con página 403. Activar SOLO cuando tu billing esté listo en producción.'],
            ['key' => 'features.google_login_enabled', 'name' => 'Login con Google',  'type' => 'bool',   'value' => 'false', 'group' => 'features', 'description' => 'Si false, oculta el botón "Continuar con Google" del login. Requiere credenciales OAuth válidas en .env.'],

            // ─── Grupo: bulk operations (threshold global) ────────────────────
            ['key' => 'bulk.async_threshold',  'name' => 'Threshold async bulk',     'type' => 'int',    'value' => '200',   'group' => 'bulk',     'description' => 'Si una operación bulk afecta más de N registros, se dispatcha a queue en vez de correr inline. Bajar si el server es chico.'],

            // ─── Grupo: exports (límites globales) ────────────────────────────
            ['key' => 'exports.max_csv_rows',   'name' => 'Máx filas CSV',           'type' => 'int',    'value' => '0',     'group' => 'exports',  'description' => '0 = sin límite (streaming). CSV puede manejar millones de filas sin RAM extra.'],
            ['key' => 'exports.max_excel_rows', 'name' => 'Máx filas Excel',         'type' => 'int',    'value' => '25000', 'group' => 'exports',  'description' => 'Límite Excel — Spreadsheet en RAM. Más filas requieren usar CSV.'],
            ['key' => 'exports.max_pdf_rows',   'name' => 'Máx filas PDF',           'type' => 'int',    'value' => '500',   'group' => 'exports',  'description' => 'Límite PDF — formato presentable/imprimible. dompdf renderiza todo en RAM. Para volcados grandes usar Excel/CSV.'],
            ['key' => 'exports.max_word_rows',  'name' => 'Máx filas Word',          'type' => 'int',    'value' => '1000',  'group' => 'exports',  'description' => 'Límite Word — formato documento. PHPWord arma el DOCX en RAM. Para volcados grandes usar Excel/CSV.'],

            // ─── Grupo: downloads (limpieza de exports físicos) ───────────────
            ['key' => 'downloads.expire_after_hours', 'name' => 'Horas hasta expirar export', 'type' => 'int', 'value' => '24', 'group' => 'downloads', 'description' => 'Tras N horas de crearse, un export expira: el archivo se borra del disco y queda solo el registro de auditoría.'],
            ['key' => 'downloads.grace_hours', 'name' => 'Horas de gracia tras descarga',     'type' => 'int', 'value' => '24', 'group' => 'downloads', 'description' => 'Cuántas horas se mantiene el archivo físico después de que el usuario lo descargó (por si quiere volver a bajarlo).'],
            ['key' => 'downloads.stale_processing_minutes', 'name' => 'Minutos hasta marcar descarga atascada', 'type' => 'int', 'value' => '30', 'group' => 'downloads', 'description' => 'Si una descarga queda en "procesando" más de N minutos (el worker murió o no corre queue:work), se marca como fallida con un aviso. Debe ser mayor al timeout del job (10 min).'],
            ['key' => 'fleet_report.pdf_max_transformers', 'name' => 'Máx transformadores en PDF de flota', 'type' => 'int', 'value' => '50', 'group' => 'exports', 'description' => 'Tope de transformadores en el reporte de flota PDF (presentable). dompdf revienta con cientos; para toda la flota usar Excel.'],

            // ─── Grupo: notifications (bell + emails) ─────────────────────────
            ['key' => 'notifications.poll_interval_seconds', 'name' => 'Frecuencia de polling (seg)', 'type' => 'int', 'value' => '30', 'group' => 'notifications', 'description' => 'Cada cuántos segundos el frontend pregunta al backend si hay notificaciones nuevas. Subir reduce carga; bajar mejora reactividad. Default 30s es un buen balance.'],
            ['key' => 'notifications.email_enabled', 'name' => 'Emails de notificación activos', 'type' => 'bool', 'value' => 'true', 'group' => 'notifications', 'description' => 'Si false, las notificaciones se muestran solo en la campana del header, no envían email.'],

            // ─── Grupo: security ──────────────────────────────────────────────
            ['key' => 'security.session_lifetime_minutes', 'name' => 'Duración de sesión (min)', 'type' => 'int', 'value' => '120', 'group' => 'security', 'description' => 'Tiempo de inactividad antes de cerrar la sesión automáticamente. 120 = 2 horas.'],
            ['key' => 'security.max_login_attempts', 'name' => 'Máx intentos de login', 'type' => 'int', 'value' => '5', 'group' => 'security', 'description' => 'Intentos fallidos antes de bloquear temporalmente la cuenta (lockout).'],
            ['key' => 'security.lockout_minutes', 'name' => 'Duración del lockout (min)', 'type' => 'int', 'value' => '15', 'group' => 'security', 'description' => 'Cuánto dura el bloqueo de la cuenta tras superar el máximo de intentos.'],

            // ─── Grupo: uploads (tamaños máximos de archivos) ─────────────────
            ['key' => 'uploads.user_photo_max_mb',   'name' => 'Foto perfil máx (MB)',    'type' => 'int', 'value' => '2', 'group' => 'uploads', 'description' => 'Tamaño máximo de la foto de perfil de usuario. El form la rechaza si supera este valor.'],
            ['key' => 'uploads.tenant_logo_max_mb',  'name' => 'Logo workspace máx (MB)', 'type' => 'int', 'value' => '2', 'group' => 'uploads', 'description' => 'Tamaño máximo del logo del workspace. Mismo criterio que la foto de perfil.'],

            // ─── Grupo: audit ─────────────────────────────────────────────────
            ['key' => 'audit.retention_days', 'name' => 'Retención de audit logs (días)', 'type' => 'int', 'value' => '365', 'group' => 'audit', 'description' => 'Cuántos días se conservan los registros del audit log antes de ser purgados por el comando nocturno.'],
            ['key' => 'reports.frozen_retention_years', 'name' => 'Retención de PDF congelados (años)', 'type' => 'int', 'value' => '2', 'group' => 'downloads', 'description' => 'Años que se conserva el ARCHIVO del informe aprobado (acta congelada). Al purgarse el archivo se conservan el snapshot de datos y la huella sha256 para auditoría. 0 = nunca purgar.'],

            // ─── Grupo: diagnostics (coloreo de celdas por límite) ────────────
            ['key' => 'diagnostics.cell_alert_sev', 'name' => 'Filtro de alertas leves en celdas (%)', 'type' => 'int', 'value' => '0', 'group' => 'diagnostics', 'description' => 'Las celdas se pintan: verde (cumple) = sin color · ámbar = cerca del límite · rojo = pasó el límite. El ROJO (fuera de norma) SIEMPRE se muestra, no se puede ocultar. Este valor solo oculta el AMARILLO de precaución por debajo de esa severidad (0-100), para reducir ruido. 0 = mostrar todas las alertas. No cambia el diagnóstico, solo qué se resalta.'],
        ];

        foreach ($settings as $s) {
            $exists = DB::table('settings')->where('key', $s['key'])->exists();

            if ($exists) {
                // El setting YA existe: refrescar solo METADATOS (nombre, tipo,
                // grupo, descripción). NUNCA pisar 'value' ni 'slug': el value es
                // dato editable por el super — re-correr el seeder (dev o prod)
                // no debe resetear su configuración a los defaults de fábrica.
                DB::table('settings')->where('key', $s['key'])->update([
                    'name'        => $s['name'],
                    'type'        => $s['type'],
                    'group'       => $s['group'],
                    'description' => $s['description'],
                    'updated_at'  => now(),
                ]);
                continue;
            }

            DB::table('settings')->insert([
                'slug'        => Str::random(22),
                'key'         => $s['key'],
                'name'        => $s['name'],
                'type'        => $s['type'],
                'value'       => $s['value'],
                'group'       => $s['group'],
                'description' => $s['description'],
                'is_secret'   => false,
                'is_active'   => true,
                'created_by'  => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('settings_id_seq', COALESCE((SELECT MAX(id) FROM settings), 0) + 1, false)");
        }

        $this->command?->info('Settings seeded: ' . count($settings) . ' default config entries.');
    }
}
