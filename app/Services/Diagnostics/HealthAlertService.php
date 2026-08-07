<?php

namespace App\Services\Diagnostics;

use App\Models\Message;
use App\Models\Transformer;
use App\Models\User;
use App\Services\Communication\MessageService;

/**
 * HealthAlertService — alertas in-app cuando la salud de un transformador
 * CAMBIA a peor. Reusa el sistema de Mensajes (campana/inbox) con audiencia
 * tenant: le llega a todos los usuarios del workspace dueño del trafo.
 *
 * Dispara SOLO en transiciones (valor viejo ≠ nuevo), nunca en estados
 * sostenidos — eso deduplica de forma natural: un trafo que sigue "Malo" en
 * la próxima muestra no re-alerta; uno que empeora de nuevo sí.
 *
 * Dos eventos:
 *   - at_risk:   el rating cruzó hacia Malo/Muy Malo (<= 1) desde una banda
 *                mejor (o desde sin-datos).
 *   - worsening: la tendencia pasó a "worsening" (el DGAF de cromas subió).
 *
 * Kill-switch en config/transformers.php → health_alerts_enabled.
 */
class HealthAlertService
{
    /** Rating máximo (inclusive) que se considera "en riesgo". */
    private const AT_RISK_MAX = 1;

    public function __construct(protected MessageService $messages) {}

    public function maybeNotify(
        Transformer $transformer,
        ?int $oldRating,
        ?string $oldTrend,
        ?int $newRating,
        ?string $newTrend,
    ): void {
        if (!config('transformers.health_alerts_enabled', true)) {
            return;
        }
        // Sin tenant (trafo de plataforma/super) no hay workspace que avisar.
        if (!$transformer->tenant_id) {
            return;
        }

        $enteredRisk = $newRating !== null && $newRating <= self::AT_RISK_MAX
            && ($oldRating === null || $oldRating > self::AT_RISK_MAX);

        $startedWorsening = $newTrend === 'worsening' && $oldTrend !== 'worsening';

        if (!$enteredRisk && !$startedWorsening) {
            return;
        }

        $senderId = User::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');
        if (!$senderId) {
            return;
        }

        $name = $transformer->serial ?: ($transformer->tag ?: ('#' . $transformer->id));

        if ($enteredRisk) {
            $this->publish($transformer, $senderId,
                __('transformers.alerts.at_risk_subject', ['name' => $name]),
                __('transformers.alerts.at_risk_body', [
                    'name'  => $name,
                    'index' => $transformer->health_index !== null ? round($transformer->health_index) : '—',
                ]),
            );
        }

        if ($startedWorsening) {
            $this->publish($transformer, $senderId,
                __('transformers.alerts.worsening_subject', ['name' => $name]),
                __('transformers.alerts.worsening_body', ['name' => $name]),
            );
        }
    }

    private function publish(Transformer $transformer, int $senderId, string $subject, string $body): void
    {
        $message = Message::create([
            'subject'       => $subject,
            'body'          => $body,
            'created_by'    => $senderId,
            'audience_type' => Message::AUDIENCE_TENANT,
            'audience_id'   => $transformer->tenant_id,
            'allow_replies' => false,
            'is_active'     => true,
        ]);

        $this->messages->publish($message);

        $this->dropRestrictedRecipients($message, $transformer);
    }

    /**
     * Anti-fuga (3ª capa): los usuarios RESTRINGIDOS a clientes no deben recibir
     * alertas de trafos que no pueden ver. publish() materializa la audiencia
     * tenant completa; aquí se quitan los recipients con asignaciones que NO
     * incluyen al cliente del trafo (o todos los restringidos si el trafo no
     * tiene cliente — para ellos es invisible).
     */
    private function dropRestrictedRecipients(Message $message, Transformer $transformer): void
    {
        $restricted = \DB::table('customer_user')->distinct()->pluck('user_id');
        if ($restricted->isEmpty()) {
            return;
        }

        $allowed = $transformer->customer_id
            ? \DB::table('customer_user')
                ->where('customer_id', $transformer->customer_id)
                ->pluck('user_id')
            : collect();

        $toRemove = $restricted->diff($allowed);
        if ($toRemove->isNotEmpty()) {
            \App\Models\MessageRecipient::where('message_id', $message->id)
                ->whereIn('user_id', $toRemove)
                ->delete();
        }
    }
}
