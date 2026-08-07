<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Auditable — drop-in trait that logs created/updated/deleted/restored events
 * to the global `audit_logs` table.
 *
 * Usage on a model:
 *   class Region extends Model {
 *       use HasFactory, SoftDeletes, Auditable;
 *
 *       // Optional: friendly module label (defaults to plural snake_case of class).
 *       protected string $auditModule = 'regions';
 *
 *       // Optional: fields to exclude from before/after snapshots.
 *       protected array $auditExclude = ['updated_at', 'remember_token'];
 *   }
 */
trait Auditable
{
    /**
     * Per-model-instance flag set during a restore() call. Used to skip the
     * `updated` event that Laravel fires internally when restore() calls save()
     * — otherwise we'd log the same action twice (Modificado + Restaurado).
     *
     * Keyed by spl_object_id($model) so concurrent restores on different model
     * instances don't interfere.
     */
    private static array $auditRestoringFlags = [];

    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            self::writeAudit($model, 'created', null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            // Skip if this `updated` is the side-effect of a restore() — the
            // upcoming `restored` event will capture the action with full context.
            if (isset(self::$auditRestoringFlags[spl_object_id($model)])) return;

            $changes = $model->getChanges();
            if (empty($changes)) return;
            $original = collect($changes)->mapWithKeys(
                fn ($v, $k) => [$k => $model->getOriginal($k)]
            )->toArray();
            self::writeAudit($model, 'updated', $original, $changes);
        });

        static::deleted(function (Model $model) {
            // Distinguish soft delete vs force delete based on SoftDeletes presence.
            $event = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
                ? 'force_deleted'
                : 'deleted';
            self::writeAudit($model, $event, $model->getOriginal(), null);
        });

        // Mark the model as "currently restoring" so the inner `updated` event is skipped.
        if (method_exists(static::class, 'restoring')) {
            static::restoring(function (Model $model) {
                self::$auditRestoringFlags[spl_object_id($model)] = true;
            });
        }

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                unset(self::$auditRestoringFlags[spl_object_id($model)]);
                self::writeAudit($model, 'restored', null, $model->getAttributes());
            });
        }
    }

    protected static function writeAudit(Model $model, string $event, ?array $old, ?array $new): void
    {
        // Toggle global. Si super desactivó audit logging desde Settings,
        // no escribir nada. Setting cacheado por request, no penaliza writes.
        if (!\App\Models\Setting::getBool('features.audit_log_enabled', true)) return;

        $exclude = property_exists($model, 'auditExclude')
            ? (array) $model->auditExclude
            : ['updated_at', 'remember_token', 'password'];

        $clean = function (?array $payload) use ($exclude) {
            if ($payload === null) return null;
            return collect($payload)->except($exclude)->all();
        };

        // Si el evento `updated` solo cambió campos excluidos (ej. solo
        // module_tours), no merece audit entry — sería ruido sin info útil.
        if ($event === 'updated' && empty($clean($new))) return;

        $module = property_exists($model, 'auditModule') && $model->auditModule
            ? $model->auditModule
            : Str::plural(Str::snake(class_basename($model)));

        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => $event,
            'auditable_type' => get_class($model),
            'auditable_id'   => $model->getKey(),
            'module'         => $module,
            'old_values'     => $clean($old),
            'new_values'     => $clean($new),
            'url'            => self::resolveRecordUrl($model, $module),
            'ip_address'     => request()?->ip(),
            'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
            'created_at'     => now(),
        ]);
    }

    /**
     * Build the canonical URL of the record — strictly the read-only show page.
     * Audit links must NEVER point to an edit form: clicking on an audit row
     * should let the viewer inspect what changed, not modify the record.
     *
     * If the module doesn't have a `.show` route yet, we return null. The
     * audit table renders that as "—" instead of a misleading link.
     *
     * Models can override this by defining a `getAuditUrl(): ?string` method.
     */
    protected static function resolveRecordUrl(Model $model, string $module): ?string
    {
        if (method_exists($model, 'getAuditUrl')) {
            return $model->getAuditUrl();
        }

        try {
            return route("system_management.{$module}.show", $model);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
