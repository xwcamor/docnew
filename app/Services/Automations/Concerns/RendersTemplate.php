<?php

namespace App\Services\Automations\Concerns;

use App\Models\Automation;
use Illuminate\Support\Collection;

/**
 * Interpolación de plantillas de automation (subject/title/body) con las
 * variables del run. Compartido por EmailAction e InAppNotificationAction
 * para que ambas rendericen igual: {count}, {list}, {date}, {automation}.
 */
trait RendersTemplate
{
    /** Variables disponibles en las plantillas de una ejecución. */
    protected function templateVars(Automation $automation, ?Collection $data): array
    {
        return [
            'count'      => $data?->count() ?? 0,
            'list'       => $this->buildList($data),
            'date'       => now()->format('Y-m-d'),
            'automation' => $automation->name,
        ];
    }

    /**
     * Lista "- etiqueta" de hasta 50 registros (best-effort por campo).
     *
     * `code` entra en la cadena, y antes que `slug`, por un motivo concreto: un
     * plan de trabajo NO tiene nombre —se identifica por su codigo,
     * PE24-0412-0458— y su slug son 22 caracteres al azar. Sin esto, el correo
     * de «los planes que quedaron sin terminar» llegaba con una lista de
     * cadenas ilegibles.
     */
    protected function buildList(?Collection $data): string
    {
        if (!$data || $data->isEmpty()) {
            return '—';
        }

        return $data->take(50)->map(function ($item) {
            $label = $item->name ?? $item->email ?? $item->code ?? $item->serial
                ?? $item->tag ?? $item->slug ?? "#{$item->id}";
            return "- {$label}";
        })->implode("\n");
    }

    /** Reemplaza {var} por su valor; deja intacto lo que no conozca. */
    protected function interpolate(string $template, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $template);
    }
}
