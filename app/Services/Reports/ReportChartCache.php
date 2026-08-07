<?php

namespace App\Services\Reports;

use App\Models\Transformer;
use Illuminate\Support\Facades\Storage;

/**
 * ReportChartCache — guarda los gráficos REALES del informe (los que captura
 * echarts en el navegador del usuario logueado) para reusarlos donde no hay
 * navegador (portal compartido).
 *
 * Así el PDF del portal sale IDÉNTICO al de la app: las mismas imágenes, no
 * unas redibujadas. Se persisten cada vez que un usuario genera el informe
 * logueado (con sus gráficos), junto con una firma de los datos. Al servir el
 * portal, si la firma sigue vigente (no se agregaron/cambiaron muestras) se
 * reusan; si cambió, se descartan (caen al render server-side GD).
 */
class ReportChartCache
{
    private const DISK = 'local';
    private const DIR  = 'report-charts';

    /** Firma de los datos: cambia si se agregan/editan/borran muestras. */
    public function signature(Transformer $transformer): string
    {
        $parts = [];
        foreach (['chromatographicals', 'fiquis', 'furanos', 'fpots'] as $rel) {
            $q = $transformer->{$rel}();
            $parts[] = $q->count() . ':' . (string) $q->max('updated_at');
        }
        return md5(implode('|', $parts));
    }

    private function path(Transformer $transformer): string
    {
        return self::DIR . '/' . $transformer->id . '.json';
    }

    /** Persiste los gráficos capturados + la firma actual de los datos. */
    public function store(Transformer $transformer, array $charts): void
    {
        if (empty($charts)) {
            return;
        }
        try {
            Storage::disk(self::DISK)->put($this->path($transformer), json_encode([
                'signature' => $this->signature($transformer),
                'saved_at'  => now()->toIso8601String(),
                'charts'    => array_values($charts),
            ]));
        } catch (\Throwable) {
            // El caché es best-effort: si falla, el informe sale igual.
        }
    }

    /** Borra el caché de un trafo (al borrarlo definitivamente, evita huérfanos). */
    public function forget(int $transformerId): void
    {
        try {
            Storage::disk(self::DISK)->delete(self::DIR . '/' . $transformerId . '.json');
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** IDs de trafo con caché en disco (para purgar huérfanos). */
    public function cachedIds(): array
    {
        try {
            return collect(Storage::disk(self::DISK)->files(self::DIR))
                ->map(fn ($p) => (int) pathinfo($p, PATHINFO_FILENAME))
                ->filter()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Devuelve los gráficos guardados SOLO si su firma coincide con los datos
     * actuales (no quedaron viejos). Null si no hay o están desactualizados.
     */
    public function get(Transformer $transformer): ?array
    {
        try {
            $disk = Storage::disk(self::DISK);
            $path = $this->path($transformer);
            if (!$disk->exists($path)) {
                return null;
            }
            $data = json_decode($disk->get($path), true);
            if (($data['signature'] ?? null) !== $this->signature($transformer)) {
                return null; // hay muestras nuevas: el caché quedó viejo
            }
            return $data['charts'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
