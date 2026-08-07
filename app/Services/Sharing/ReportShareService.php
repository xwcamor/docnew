<?php

namespace App\Services\Sharing;

use App\Models\ReportShare;
use App\Models\Transformer;
use App\Services\Diagnostics\HealthIndexService;
use App\Support\Transformers\TransformerShowPayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * ReportShareService — lógica del compartir: OTP (código por correo) y armado
 * de los datos del diagnóstico para el portal público y el PDF.
 *
 * El OTP vive en cache (driver `database`, sin Redis): hash + expiración corta +
 * intentos. La identidad se prueba con el código que llega al correo del share.
 */
class ReportShareService
{
    private const OTP_TTL = 600;      // 10 min
    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct(private HealthIndexService $hi)
    {
    }

    private function otpKey(ReportShare $share): string
    {
        return "share_otp:{$share->id}";
    }

    /** Genera un OTP de 6 dígitos, lo guarda hasheado y devuelve el código en claro (para el mail). */
    public function generateOtp(ReportShare $share): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->otpKey($share), [
            'hash'     => Hash::make($code),
            'attempts' => 0,
        ], self::OTP_TTL);

        return $code;
    }

    /** Valida el OTP ingresado. Cuenta intentos; lo invalida al acertar o agotar. */
    public function verifyOtp(ReportShare $share, string $code): bool
    {
        $data = Cache::get($this->otpKey($share));
        if (!$data) {
            return false;
        }
        if (($data['attempts'] ?? 0) >= self::OTP_MAX_ATTEMPTS) {
            Cache::forget($this->otpKey($share));
            return false;
        }
        if (Hash::check($code, $data['hash'])) {
            Cache::forget($this->otpKey($share));
            return true;
        }
        $data['attempts'] = ($data['attempts'] ?? 0) + 1;
        Cache::put($this->otpKey($share), $data, self::OTP_TTL);

        return false;
    }

    /** Transformadores dentro del alcance del share (1, la flota o un subconjunto). */
    public function transformersInScope(ReportShare $share)
    {
        if ($share->isFleet()) {
            // Eager-load + solo columnas necesarias: el listado lee HI cacheado
            // (fleetRow), sin N+1 ni recalcular diagnóstico.
            $q = Transformer::with('customer:id,name', 'transformerType:id,name')
                ->where('customer_id', $share->customer_id);
            if (!empty($share->transformer_ids)) {
                $q->whereIn('id', $share->transformer_ids);
            }
            return $q->orderBy('health_rating')->orderBy('serial')->get();
        }

        return Transformer::where('id', $share->transformer_id)->get();
    }

    /** ¿El trafo pedido pertenece al alcance del share? (anti-IDOR en /pdf/{trafo}). */
    public function transformerInScope(ReportShare $share, int $transformerId): ?Transformer
    {
        $q = Transformer::where('id', $transformerId);
        if ($share->isFleet()) {
            $q->where('customer_id', $share->customer_id);
            if (!empty($share->transformer_ids)) {
                $q->whereIn('id', $share->transformer_ids);
            }
        } else {
            $q->where('id', $share->transformer_id);
        }

        return $q->first();
    }

    /** Logo del workspace como data-URI base64 (para portal y mails). Null si no hay. */
    public function logoDataUri(ReportShare $share): ?string
    {
        $logo = $share->tenant?->logo ?? null;
        if (!$logo) {
            return null;
        }
        try {
            $disk = Storage::disk('public');
            return 'data:' . $disk->mimeType($logo) . ';base64,' . base64_encode($disk->get($logo));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fila ligera para el LISTADO de flota: lee el HI CACHEADO (health_index/
     * health_rating ya persistidos), sin recalcular el diagnóstico. Esto hace el
     * portal de flota O(lectura), no O(diagnóstico) — escala a fletas grandes.
     * El detalle de un trafo (summary) sí evalúa en vivo, pero es uno solo.
     */
    public function fleetRow(Transformer $transformer): array
    {
        // health_rating 0-4 → condición (label crudo) + color del semáforo.
        $map = [
            4 => ['Muy Bueno', 'green'], 3 => ['Bueno', 'lime'], 2 => ['Medio', 'yellow'],
            1 => ['Malo', 'orange'], 0 => ['Muy Malo', 'red'],
        ];
        [$condition, $color] = $map[$transformer->health_rating] ?? [null, null];

        return [
            'serial'    => $transformer->serial,
            'tag'       => $transformer->tag,
            'customer'  => $transformer->customer?->name,
            'type'      => $transformer->transformerType?->name,
            'index'     => $transformer->health_index !== null ? (int) round($transformer->health_index) : null,
            'condition' => $condition,
            'color'     => $color,
            'id'        => $transformer->id,
        ];
    }

    /** Resumen de diagnóstico de un trafo (Índice de Salud + componentes + specs) para portal/PDF. */
    public function summary(Transformer $transformer): array
    {
        // `code` es CLAVE: el motor de fiquis resuelve la tabla de umbrales por
        // oilType->code (string). Sin él, evaluate() devuelve null y fiquis sale
        // como "Sin datos" en el portal aunque tenga muestras. (cromas usa la FK
        // oil_type_id, por eso no se afectaba — de ahí el "solo sale cromas").
        $transformer->loadMissing('customer:id,name', 'oilType:id,name,code', 'transformerType:id,name,code');
        $hi = $this->hi->evaluate($transformer, persist: false)->toArray();

        return [
            'serial'     => $transformer->serial,
            'tag'        => $transformer->tag,
            'customer'   => $transformer->customer?->name,
            'type'       => $transformer->transformerType?->name,
            'oil'        => $transformer->oilType?->name,
            'voltage_kv' => $transformer->voltage_kv,
            'power_mva'  => $transformer->power_mva,
            'index'      => $hi['index'] ?? null,
            'condition'  => $hi['condition'] ?? null,
            'color'      => $hi['color'] ?? null,
            'id'         => $transformer->id,
            'components' => array_values($hi['components'] ?? []),
        ];
    }

    /**
     * Detalle COMPLETO de un trafo para el portal (mismo nivel que el informe
     * PDF): muestras diagnosticadas de cada prueba, columnas/límites y veredicto
     * sintetizado. Reusa TransformerShowPayload (la misma fuente del informe).
     *
     * @return array{cromas:array,fiquis:array,fiquisColumns:array,furanos:array,fpots:array,summary:array,gasLimits:array,fiquisLimits:array}
     */
    public function detail(Transformer $transformer): array
    {
        $data = app(TransformerShowPayload::class)->build($transformer);

        // Límite típico por gas (tope de la banda score 1) — igual que el informe.
        $gasLimits = [];
        foreach (($data['cromasLimits'] ?? []) as $gas => $bands) {
            $b1 = collect($bands)->firstWhere('score', 1);
            $gasLimits[$gas] = $b1['to'] ?? null;
        }
        // Límite por parámetro fisicoquímico (Máx/Mín según banda mejor).
        $fiquisLimits = [];
        foreach (($data['fiquisLimits'] ?? []) as $key => $bands) {
            if (empty($bands)) {
                continue;
            }
            $first = $bands[0];
            $last  = $bands[count($bands) - 1];
            if ((float) ($first['sev'] ?? 1) === 0.0 && $first['to'] !== null) {
                $fiquisLimits[$key] = ['dir' => 'max', 'value' => (float) $first['to']];
            } elseif ((float) ($last['sev'] ?? 1) === 0.0) {
                $fiquisLimits[$key] = ['dir' => 'min', 'value' => (float) $last['from']];
            }
        }

        return [
            'cromas'        => $data['cromas'],
            'fiquis'        => $data['fiquis'],
            'fiquisColumns' => $data['fiquisColumns'],
            'furanos'       => $data['furanos'],
            'fpots'         => $data['fpots'] ?? [],
            'summary'       => $data['diagnosisSummary'] ?? [],
            'gasLimits'     => $gasLimits,
            'fiquisLimits'  => $fiquisLimits,
        ];
    }
}
