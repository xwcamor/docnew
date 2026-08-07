<?php

namespace App\Services\Automations\Actions;

use App\Mail\AutomationDigestMail;
use App\Models\Automation;
use App\Models\Setting;
use App\Services\Automations\Contracts\ActionContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * EmailAction — envía un email con un resumen de los registros (si hay data
 * source) o un mensaje plano (si no).
 *
 * action_config esperado:
 *   {
 *     "to":      ["admin@x.com", "otro@x.com"],
 *     "subject": "Productos pendientes",
 *     "body":    "Hola, tienes {count} pendientes:\n{list}"
 *   }
 *
 * Variables soportadas en subject y body:
 *   {count}        — cantidad de registros (0 si no hay data source)
 *   {list}         — bullet list "- name: value" (vacío si no hay data)
 *   {date}         — fecha actual YYYY-MM-DD
 *   {automation}   — nombre de la automation
 */
class EmailAction implements ActionContract
{
    use \App\Services\Automations\Concerns\RendersTemplate;

    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return __('automations.action_email');
    }

    public function configSchema(): array
    {
        return [
            'to'      => ['type' => 'email_list', 'label' => __('automations.action_email_to'),      'required' => true],
            'subject' => ['type' => 'string',     'label' => __('automations.action_email_subject'), 'required' => true],
            'body'    => ['type' => 'text',       'label' => __('automations.action_email_body'),    'required' => true],
        ];
    }

    public function execute(Automation $automation, ?Collection $data): string
    {
        $config = $automation->action_config ?? [];
        $to     = (array) ($config['to'] ?? []);
        if (empty($to)) {
            throw new \RuntimeException('Email action sin destinatarios.');
        }

        $vars = $this->templateVars($automation, $data);
        $subject = $this->interpolate($config['subject'] ?? '', $vars);
        $body    = $this->interpolate($config['body']    ?? '', $vars);

        // Respeta el setting global. Si esta off, no se envia pero el run de
        // la automation queda marcado como exitoso (la accion se "ejecuto"
        // pero sin efecto real). Util para apagar emails sin desactivar las
        // automations una a una.
        if (!Setting::getBool('notifications.email_enabled', true)) {
            return sprintf('Skipped: notifications.email_enabled = false (%d destinatarios)', count($to));
        }

        Mail::to($to)->send(new AutomationDigestMail($subject, $body));

        // Notif in-app de confirmacion a TODOS los admins del workspace + a
        // todos los super (cross-tenant). Channel 'email' para que el bell
        // pinte icono de sobre. Auto-mark-read.
        $this->notifyWorkspaceAdmins($automation, $vars['count'], count($to));

        return sprintf('Enviado a %d destinatario(s)', count($to));
    }

    /**
     * Confirmacion en el bell para los admins del workspace y para todos los
     * super. La automation es del workspace, no del individuo que la creo —
     * cualquier admin del workspace deberia enterarse que se ejecuto.
     *
     * Super tambien recibe (con badge del tenant en el bell, manejado por
     * frontend) para tener visibilidad cross-tenant.
     */
    protected function notifyWorkspaceAdmins(Automation $automation, int $count, int $emailCount): void
    {
        $automation->loadMissing('tenant');

        $users = \App\Models\User::withoutGlobalScopes()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super']))
            ->where(function ($q) use ($automation) {
                // Admins del workspace de la automation, O cualquier super.
                $q->where('tenant_id', $automation->tenant_id)
                  ->orWhereHas('roles', fn ($q2) => $q2->where('name', 'super'));
            })
            ->where('is_active', true)
            ->get();

        if ($users->isEmpty()) return;

        $notification = new \App\Notifications\AutomationTriggered(
            automation: $automation,
            recordsMatched: $count,
            success: true,
            channel: 'email',
            emailRecipientsCount: $emailCount,
        );

        foreach ($users as $u) {
            $u->notify($notification);
        }

        // Auto-mark-read en bloque: info ambient, no requiere ack.
        \Illuminate\Notifications\DatabaseNotification::query()
            ->where('type', \App\Notifications\AutomationTriggered::class)
            ->whereIn('notifiable_id', $users->pluck('id'))
            ->where('notifiable_type', \App\Models\User::class)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subSeconds(5))
            ->update(['read_at' => now()]);
    }

}
