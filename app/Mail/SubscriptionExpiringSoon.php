<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email de warning al admin de un tenant cuando la sub está por expirar.
 * Trigger: command `subscriptions:check-expirations` (cron diario).
 */
class SubscriptionExpiringSoon extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public User $admin,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('subscriptions.email_subject', [
                'days' => $this->subscription->daysRemaining(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-expiring-soon',
            with: [
                'tenantName'     => $this->subscription->tenant?->name,
                'plan'           => $this->subscription->plan,
                'endsAt'         => $this->subscription->ends_at,
                'daysRemaining'  => $this->subscription->daysRemaining(),
                'adminName'      => $this->admin->name,
                'supportEmail'   => \App\Models\Setting::get('app.support_email'),
            ],
        );
    }
}
