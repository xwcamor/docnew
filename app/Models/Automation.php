<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenant;
use App\Traits\HasFavorites;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Automation — regla editable por el tenant que define cuándo y cómo
 * disparar una acción automática.
 *
 * El comando `automations:tick` corre cada minuto. Busca automations con
 * is_active=true y next_run_at<=now(), las despacha al queue y reprograma
 * next_run_at usando computeNextRunAt() según trigger_config.
 *
 * Helpers asociados:
 *   - Services\Automations\AutomationRunner ejecuta la lógica
 *   - Services\Automations\DataSourceRegistry resuelve data sources
 *   - Services\Automations\ActionRegistry resuelve actions
 */
class Automation extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenant, HasFavorites;

    protected string $auditModule = 'automations';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'is_active',
        'trigger_type', 'trigger_config',
        'data_source', 'data_filter',
        'action_type', 'action_config',
        'last_run_at', 'next_run_at', 'runs_count', 'failures_count',
        'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'trigger_config' => 'array',
        'data_filter'    => 'array',
        'action_config'  => 'array',
        'last_run_at'    => 'datetime',
        'next_run_at'    => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class)->orderByDesc('started_at');
    }

    /**
     * Calcula el próximo next_run_at según trigger_config. Soporta dos shapes:
     *   - { kind: 'cron', expression: '0 9 * * *' }
     *   - { kind: 'daily', time: '09:00', timezone: 'UTC' }
     *   - { kind: 'weekly', day: 1, time: '09:00' }  (day: 0=domingo .. 6=sábado)
     *   - { kind: 'monthly', day: 1, time: '09:00' } (day del mes)
     *
     * Devuelve null si trigger_config es inválido — la automation queda
     * pausada hasta que el usuario la corrija desde la UI.
     */
    public function computeNextRunAt(?\DateTimeInterface $from = null): ?\Carbon\Carbon
    {
        $from   = $from ? \Carbon\Carbon::instance($from) : now();
        $config = $this->trigger_config ?? [];
        $kind   = $config['kind'] ?? null;

        $expression = match ($kind) {
            'cron'    => $config['expression'] ?? null,
            'daily'   => $this->dailyToCron($config),
            'weekly'  => $this->weeklyToCron($config),
            'monthly' => $this->monthlyToCron($config),
            default   => null,
        };

        if (!$expression) return null;

        // La expresion cron debe interpretarse en la TZ del usuario/automation,
        // no en la del servidor. dragonmantank/cron-expression usa la TZ del
        // DateTime que recibe — si paso `now()` (UTC), "9:00 daily" dispara a
        // las 9:00 UTC = 3 AM en México. Convertimos `$from` a la TZ configurada,
        // calculamos, y volvemos a UTC para persistir.
        $tz = $config['timezone'] ?? 'UTC';
        try {
            $fromInTz = $from->copy()->setTimezone($tz);
        } catch (\Throwable $e) {
            // TZ inválida → fallback a UTC.
            $fromInTz = $from;
        }

        try {
            $cron = new CronExpression($expression);
            $next = $cron->getNextRunDate($fromInTz);
            return \Carbon\Carbon::instance($next)->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function dailyToCron(array $c): string
    {
        [$h, $m] = $this->parseTime($c['time'] ?? '09:00');
        return "{$m} {$h} * * *";
    }

    private function weeklyToCron(array $c): string
    {
        [$h, $m] = $this->parseTime($c['time'] ?? '09:00');
        $day = (int) ($c['day'] ?? 1);
        return "{$m} {$h} * * {$day}";
    }

    private function monthlyToCron(array $c): string
    {
        [$h, $m] = $this->parseTime($c['time'] ?? '09:00');
        $day = (int) ($c['day'] ?? 1);
        return "{$m} {$h} {$day} * *";
    }

    private function parseTime(string $time): array
    {
        $parts = explode(':', $time);
        return [(int) ($parts[0] ?? 9), (int) ($parts[1] ?? 0)];
    }

    /**
     * Schema de filtros avanzados ("Agregar filtro" inline). Lo consume el
     * InlineFilterBuilder en el frontend y el FilterApplier en el index.
     */
    public static function filterSchema(): array
    {
        return [
            ['key' => 'name',        'label' => __('automations.name'),        'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'description', 'label' => __('automations.description'), 'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',   'label' => __('global.active'),           'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at',  'label' => __('global.created_at'),       'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at',  'label' => __('global.updated_at'),       'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
