<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PlanChanged — notifica al admin del tenant cuando el plan cambió.
 *
 * Casos: upgrade (basic → pro), downgrade (enterprise → pro), suspensión
 * (plan vigente → free derivado), cancelación (plan vigente → free derivado
 * al vencer ends_at).
 *
 * Canales: in-app (bell) + email.
 * - Si es upgrade: mensaje positivo "ahora tienes acceso a X features".
 * - Si es downgrade/suspensión: warning explícito sobre qué deja de funcionar.
 *
 * Importante: las API keys del tenant NO se borran al downgrade. Quedan
 * "dormidas" hasta re-upgrade (gating runtime via plan_feature middleware).
 * Este mensaje se lo explica al admin para que no se asuste con los 402.
 */
class PlanChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public string $previousPlan,
        public string $newPlan,
        public string $direction = 'change', // 'upgrade' | 'downgrade' | 'change'
    ) {}

    public function via(object $notifiable): array
    {
        // El canal `database` siempre se envia (alimenta el bell del header).
        // El canal `mail` queda condicionado al setting global.
        return \App\Models\Setting::getBool('notifications.email_enabled', true)
            ? ['mail', 'database']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $msg = (new MailMessage)
            ->subject(__('mail.plan_changed_subject', [
                'workspace' => $this->tenant->name,
                'plan'      => strtoupper($this->newPlan),
            ]))
            ->greeting(__('global.greeting_hi', ['name' => $notifiable->name ?? '']));

        if ($this->direction === 'downgrade') {
            $msg->line(__('mail.plan_downgraded_intro', [
                'previous' => strtoupper($this->previousPlan),
                'current'  => strtoupper($this->newPlan),
            ]));
            $msg->line(__('mail.plan_downgraded_apis'));
            $msg->line(__('mail.plan_downgraded_upgrade_hint'));
        } elseif ($this->direction === 'upgrade') {
            $msg->success();
            $msg->line(__('mail.plan_upgraded_intro', [
                'previous' => strtoupper($this->previousPlan),
                'current'  => strtoupper($this->newPlan),
            ]));
        } else {
            $msg->line(__('mail.plan_changed_intro', [
                'previous' => strtoupper($this->previousPlan),
                'current'  => strtoupper($this->newPlan),
            ]));
        }

        return $msg->line(__('mail.plan_changed_footer'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'     => __('mail.plan_changed_subject', [
                'workspace' => $this->tenant->name,
                'plan'      => strtoupper($this->newPlan),
            ]),
            'body'      => $this->direction === 'downgrade'
                ? __('mail.plan_downgraded_apis')
                : __('mail.plan_changed_intro', [
                    'previous' => strtoupper($this->previousPlan),
                    'current'  => strtoupper($this->newPlan),
                ]),
            'tenant_id' => $this->tenant->id,
            'previous'  => $this->previousPlan,
            'current'   => $this->newPlan,
            'direction' => $this->direction,
            'category'  => 'plan_change',
        ];
    }
}
