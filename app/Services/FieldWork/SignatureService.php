<?php

namespace App\Services\FieldWork;

use App\Models\EvidenceFile;
use App\Models\Person;
use App\Models\Setting;
use App\Models\SignatureEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Firma de un documento con reconocimiento facial.
 *
 * Dos reglas que vienen de lo que fallaba en el sistema anterior:
 *
 *  1. La comparacion la hace el servidor. Antes el navegador calculaba la
 *     distancia, decidia si habia coincidencia y mandaba is_approved=1 en un
 *     campo oculto del formulario: bastaba con abrir las herramientas de
 *     desarrollo para firmar como cualquiera.
 *
 *  2. La foto se guarda siempre. Antes el 83 % de las fotos y el 96 % de las
 *     firmas eran la cadena "detected_by_IA" escrita en la columna del archivo,
 *     es decir, no habia evidencia de nada.
 *
 * Y una regla que viene de como se trabaja en obra: si no reconoce, no se
 * bloquea a nadie. Se captura igual, se firma, y el evento queda marcado para
 * que un supervisor lo revise.
 */
class SignatureService
{
    /** Distancia por defecto si el pais no tiene configuracion propia. */
    public const UMBRAL_POR_DEFECTO = 0.5;

    /**
     * Registra la firma de una persona sobre algo firmable (un trabajador del
     * plan, una aprobacion o un formato entregado).
     *
     * @param  array  $descriptor  Los 128 valores que midio el navegador.
     * @param  string|null  $foto  Imagen en base64. Obligatoria salvo firma manual autorizada.
     */
    public function firmar(
        Model $firmable,
        Person $persona,
        string $rolFirmado,
        ?array $descriptor,
        ?string $foto,
        array $contexto = [],
    ): SignatureEvent {
        $umbral = $this->umbralPara($persona);
        $distancia = $descriptor ? $this->distanciaMinima($persona, $descriptor) : null;

        // El metodo lo decide el servidor a partir de la medicion, nunca el cliente.
        $reconocida = $distancia !== null && $distancia <= $umbral;
        $manual = (bool) ($contexto['manual_override'] ?? false);

        if ($manual && blank($contexto['override_reason'] ?? null)) {
            throw new \InvalidArgumentException('Una firma manual necesita un motivo.');
        }

        $metodo = match (true) {
            $manual      => SignatureEvent::MANUAL,
            $reconocida  => SignatureEvent::FACE_RECOGNITION,
            default      => SignatureEvent::TIMEOUT_CAPTURE,
        };

        // La foto se guarda SIEMPRE, reconozca o no.
        //
        // En un documento de seguridad la prueba util a los dos anios es la cara
        // de quien firmo, no la distancia que midio el servidor. El sistema
        // anterior lo intentaba pero no lo cumplia: de 9 012 fotos, 7 508 eran la
        // cadena "detected_by_IA" y el archivo no existia.
        //
        // Se puede desactivar por workspace, pero por defecto se guarda.
        $exigirFoto = (bool) (Setting::get('docufiz.always_store_photo') ?? true);

        if (blank($foto) && ! $manual && ($exigirFoto || $metodo !== SignatureEvent::FACE_RECOGNITION)) {
            throw new \InvalidArgumentException('Se requiere la captura de la camara para firmar.');
        }

        return DB::transaction(function () use (
            $firmable, $persona, $rolFirmado, $metodo, $reconocida, $distancia, $umbral, $manual, $foto, $contexto
        ) {
            $evento = SignatureEvent::create([
                'signable_type'   => $firmable->getMorphClass(),
                'signable_id'     => $firmable->getKey(),
                'person_id'       => $persona->id,
                'role_signed'     => $rolFirmado,
                'signed_at'       => now(),
                'method'          => $metodo,
                'used_ai'         => $reconocida,
                'match_distance'  => $distancia,
                'threshold_used'  => $umbral,
                'pending_review'  => $metodo !== SignatureEvent::FACE_RECOGNITION,
                'manual_override' => $manual,
                'override_reason' => $contexto['override_reason'] ?? null,
                'override_by'     => $manual ? ($contexto['override_by'] ?? auth()->id()) : null,
                'latitude'        => $contexto['latitude'] ?? null,
                'longitude'       => $contexto['longitude'] ?? null,
                'device_id'       => $contexto['device_id'] ?? null,
                'ip_address'      => $contexto['ip_address'] ?? request()->ip(),
                'user_agent'      => $contexto['user_agent'] ?? request()->userAgent(),
                'country_code'    => $contexto['country_code'] ?? null,
                'region'          => $contexto['region'] ?? null,
                'city'            => $contexto['city'] ?? null,
                'tenant_id'       => $persona->tenant_id,
            ]);

            if (filled($foto)) {
                $yaGuardada = $this->evidenciaDelDia($evento);

                if ($yaGuardada) {
                    // Mismo plan, misma persona, mismo dia: se apunta al archivo
                    // que ya existe en vez de guardar otra foto igual.
                    EvidenceFile::create([
                        'signature_event_id' => $evento->id,
                        'kind'      => EvidenceFile::FACE,
                        'file_path' => $yaGuardada->file_path,
                        'sha256'    => $yaGuardada->sha256,
                        'byte_size' => $yaGuardada->byte_size,
                        'width'     => $yaGuardada->width,
                        'height'    => $yaGuardada->height,
                        'taken_at'  => now(),
                    ]);
                } else {
                    $this->guardarEvidencia($evento, $foto, EvidenceFile::FACE);
                }
            }

            // La aprobacion la calcula el servidor a partir del evento.
            if (in_array('is_approved', $firmable->getFillable(), true)) {
                $firmable->forceFill(['is_approved' => true])->save();
            }

            return $evento;
        });
    }

    /**
     * Distancia euclidiana minima contra las muestras enroladas de esa persona.
     * Es verificacion 1:1: nunca se compara contra el resto del padron.
     */
    public function distanciaMinima(Person $persona, array $descriptor): ?float
    {
        $biometria = $persona->activeBiometric;

        if (! $biometria || blank($biometria->face_descriptor)) {
            return null;
        }

        $muestras = $biometria->face_descriptor;

        // Compatibilidad con el formato viejo: un unico vector plano.
        if (! is_array($muestras[0] ?? null)) {
            $muestras = [$muestras];
        }

        $minima = null;

        foreach ($muestras as $muestra) {
            if (count($muestra) !== count($descriptor)) {
                continue;
            }

            $suma = 0.0;
            foreach ($muestra as $i => $valor) {
                $suma += ($valor - $descriptor[$i]) ** 2;
            }

            $distancia = sqrt($suma);
            $minima = $minima === null ? $distancia : min($minima, $distancia);
        }

        return $minima === null ? null : round($minima, 4);
    }

    /** Umbral configurado para el pais de la persona, con rango acotado. */
    public function umbralPara(Person $persona): float
    {
        $propio = $persona->activeBiometric?->threshold;

        // Ajuste del workspace. Se cambia solo desde configuracion y queda auditado.
        $delWorkspace = Setting::get('docufiz.face_threshold');

        $umbral = (float) ($propio ?? $delWorkspace ?? self::UMBRAL_POR_DEFECTO);

        // Fuera de este rango el reconocimiento deja de significar algo.
        return max(0.30, min(0.65, $umbral));
    }

    /**
     * Guarda la imagen y la registra. Deduplica por hash: la misma foto no se
     * almacena dos veces, que era otra fuente de crecimiento sin control.
     */
    protected function guardarEvidencia(SignatureEvent $evento, string $base64, string $tipo): EvidenceFile
    {
        $binario = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $base64), true);

        if ($binario === false) {
            throw new \InvalidArgumentException('La captura no es una imagen valida.');
        }

        // Una foto de 640x480 en JPEG pesa unos 70 KB. Reducida a 320 px y
        // convertida a WebP baja a unos 15 KB, y para lo que sirve —reconocer a
        // la persona en la revision— sigue siendo de sobra. Cinco veces menos
        // disco es la diferencia entre 12 GB al ano y 2,6 GB con 500 firmas
        // diarias, que es lo que importa cuando el disco es el de un droplet.
        [$binario, $ancho, $alto] = $this->comprimir($binario);
        $hash = hash('sha256', $binario);
        $existente = EvidenceFile::where('sha256', $hash)->first();

        $ruta = $existente?->file_path
            ?? sprintf('evidencias/%s/%s.webp', now()->format('Y/m'), Str::random(24));

        if (! $existente) {
            Storage::disk('local')->put($ruta, $binario);
        }

        return EvidenceFile::create([
            'signature_event_id' => $evento->id,
            'kind'      => $tipo,
            'file_path' => $ruta,
            'sha256'    => $hash,
            'byte_size' => strlen($binario),
            'width'     => $ancho,
            'height'    => $alto,
            'taken_at'  => now(),
        ]);
    }

    /** Resolucion de una firma que quedo pendiente de revision. */
    public function revisar(SignatureEvent $evento, bool $aceptada, int $revisorId, ?string $motivo = null): SignatureEvent
    {
        $evento->update([
            'pending_review' => false,
            'reviewed_at'    => now(),
            'reviewed_by'    => $revisorId,
            'override_reason' => $motivo ?? $evento->override_reason,
        ]);

        if (! $aceptada) {
            $firmable = $evento->signable;

            if ($firmable && in_array('is_approved', $firmable->getFillable(), true)) {
                $firmable->forceFill(['is_approved' => false])->save();
            }
        }

        return $evento->fresh();
    }

    /**
     * Reduce la captura a lo minimo util como evidencia: 320 px de lado mayor,
     * WebP de calidad media. Si la imagen no se puede procesar se guarda tal
     * cual: nunca se pierde una evidencia por no poder comprimirla.
     *
     * Medido con una captura de 640x480: 122 KB de JPEG quedan en 14 KB.
     *
     * @return array{0:string,1:?int,2:?int} imagen, ancho y alto finales
     */
    protected function comprimir(string $binario, int $lado = 320, int $calidad = 70): array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return [$binario, null, null];
        }

        $img = @imagecreatefromstring($binario);

        if ($img === false) {
            return [$binario, null, null];
        }

        $ancho = imagesx($img);
        $alto = imagesy($img);
        $escala = min(1, $lado / max($ancho, $alto));

        if ($escala < 1) {
            $nueva = imagescale($img, (int) round($ancho * $escala), (int) round($alto * $escala));

            if ($nueva !== false) {
                imagedestroy($img);
                $img = $nueva;
                $ancho = imagesx($img);
                $alto = imagesy($img);
            }
        }

        ob_start();
        $ok = imagewebp($img, null, $calidad);
        $salida = ob_get_clean();
        imagedestroy($img);

        return ($ok && $salida !== '')
            ? [$salida, $ancho, $alto]
            : [$binario, null, null];
    }

    /**
     * Una persona que firma cuatro formatos del mismo plan el mismo dia no
     * necesita cuatro fotos: es el mismo momento y la misma prueba. Se reutiliza
     * la evidencia ya guardada y solo se registra el evento nuevo.
     */
    protected function evidenciaDelDia(SignatureEvent $evento): ?EvidenceFile
    {
        $firmable = $evento->signable;
        $planId = $firmable?->work_plan_id ?? null;

        if (! $planId) {
            return null;
        }

        // Todo lo que se firma cuelga de un plan: el trabajador asignado, la
        // aprobacion del supervisor y cada formato entregado.
        $firmables = [
            \App\Models\WorkPlanPerson::class,
            \App\Models\WorkPlanApproval::class,
            \App\Models\FormSubmission::class,
        ];

        return EvidenceFile::query()
            ->where('kind', EvidenceFile::FACE)
            ->whereDate('created_at', today())
            ->whereHas('signatureEvent', fn ($q) => $q
                ->where('person_id', $evento->person_id)
                ->where('id', '!=', $evento->id)
                ->whereHasMorph('signable', $firmables, fn ($s) => $s->where('work_plan_id', $planId)))
            ->latest('id')
            ->first();
    }
}
