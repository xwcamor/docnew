<?php

namespace App\Services\Automations\Actions;

use App\Models\Automation;
use App\Models\User;
use App\Notifications\AutomationTriggered;
use App\Services\Automations\Contracts\ActionContract;
use Illuminate\Support\Collection;

/**
 * InAppNotificationAction — crea notificaciones in-app (bell icon).
 *
 * action_config esperado:
 *   {
 *     "recipients": "tenant_admins" | "specific_users",
 *     "user_ids":   [1, 2, 3],   // solo si recipients = specific_users
 *     "title":      "Tienes {count} productos pendientes",
 *     "body":       "Hola, hay {count} cosas que revisar. Fecha: {date}"
 *   }
 *
 * Variables soportadas en title y body: {count}, {list}, {date}, {automation}.
 */
class InAppNotificationAction implements ActionContract
{
    use \App\Services\Automations\Concerns\RendersTemplate;

    public function key(): string
    {
        return 'in_app_notification';
    }

    public function label(): string
    {
        return __('automations.action_in_app');
    }

    public function configSchema(): array
    {
        return [
            'recipients' => ['type' => 'recipients_picker', 'label' => __('automations.action_in_app_recipients'), 'required' => true],
            'user_ids'   => ['type' => 'user_ids',          'label' => __('automations.action_in_app_user_ids')],
            'title'      => ['type' => 'string',            'label' => __('automations.action_in_app_title'),   'required' => true],
            'body'       => ['type' => 'text',              'label' => __('automations.action_in_app_body'),    'required' => true],
        ];
    }

    public function execute(Automation $automation, ?Collection $data): string
    {
        $config = $automation->action_config ?? [];

        $users = $this->resolveRecipients($automation, $config);
        if ($users->isEmpty()) {
            throw new \RuntimeException('In-app notification action sin destinatarios.');
        }

        // Eager-load tenant para que AutomationTriggered pueda meter
        // tenant_name en data — usado por el bell como badge para super.
        $automation->loadMissing('tenant');

        $recordsMatched = $data?->count() ?? 0;

        // Renderiza el title/body que configuró el usuario, interpolando
        // {count}/{list}/{date}/{automation}. Es el mensaje que verá en la
        // campana; si lo dejó vacío, AutomationTriggered cae al nombre + estado.
        $vars  = $this->templateVars($automation, $data);
        $title = trim($this->interpolate((string) ($config['title'] ?? ''), $vars));
        $body  = trim($this->interpolate((string) ($config['body'] ?? ''), $vars));

        $notification = new AutomationTriggered(
            $automation,
            $recordsMatched,
            customTitle: $title !== '' ? $title : null,
            customBody:  $body !== '' ? $body : null,
        );

        foreach ($users as $user) {
            $user->notify($notification);
        }

        // Las notificaciones quedan SIN LEER a propósito: el sentido de esta
        // acción es avisar en la campana (badge). Se purgan con el cron
        // `automations:purge-old-notifications` (12h después de creadas).
        return sprintf('Notificados %d usuario(s)', $users->count());
    }

    /**
     * Resuelve los destinatarios según el modo elegido:
     *   - tenant_admins: todos los users con rol admin del tenant.
     *   - specific_users: los user_ids pasados en config (validados contra
     *     tenant_id para evitar fuga cross-tenant).
     */
    protected function resolveRecipients(Automation $automation, array $config): Collection
    {
        $mode = $config['recipients'] ?? 'tenant_admins';
        $tenantId = $automation->tenant_id;

        if ($mode === 'specific_users') {
            $ids = array_filter((array) ($config['user_ids'] ?? []));
            // Usuarios elegidos por el creador (filtrados por seguridad: deben
            // pertenecer al tenant de la automation o ser super).
            $picked = User::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                })
                ->where('is_active', true)
                ->get();

            // Ademas SIEMPRE notificamos a todos los super — son observadores
            // cross-tenant y deben enterarse de cualquier ejecucion. El bell
            // les pinta badge con el nombre del tenant para distinguir.
            $supers = User::withoutGlobalScopes()
                ->whereHas('roles', fn ($q) => $q->where('name', 'super'))
                ->where('is_active', true)
                ->get();

            return $picked->merge($supers)->unique('id')->values();
        }

        // Default: admins del tenant + todos los super (cross-tenant).
        // El super tiene visibilidad global; aunque la automation pertenezca
        // a un tenant especifico, el super debe enterarse. El frontend le
        // pintara badge con el nombre del tenant para distinguir.
        return User::withoutGlobalScopes()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super']))
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereHas('roles', fn ($q2) => $q2->where('name', 'super'));
            })
            ->where('is_active', true)
            ->get();
    }
}
