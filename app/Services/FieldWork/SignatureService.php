<?php

namespace App\Services\FieldWork;

use App\Models\EvidenceFile;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\PersonSignature;
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

                // Si la persona no tiene foto de referencia, esta se queda como
                // suya. Una captura de obra no es la buena —a contraluz, con
                // casco, en movimiento— pero es muchisimo mejor que nada a la
                // hora de saber a quien se esta mirando. En cuanto el
                // administrador suba una decente, esta se jubila sola.
                $this->adoptarFotoSiNoTiene($persona, $foto);
            }

            // La aprobacion la calcula el servidor a partir del evento.
            if (in_array('is_approved', $firmable->getFillable(), true)) {
                $firmable->forceFill(['is_approved' => true])->save();
            }

            // Y con la ultima firma el plan puede cerrarse solo, como en el
            // sistema anterior (`PlanApproval#lock_plan`). Sin esto el plan se
            // queda abierto para siempre: alli se cerraron asi 3 297 de 3 653
            // planes, ninguno a mano.
            //
            // Cuenta la firma del trabajador igual que la del aprobador: aqui
            // el cierre exige el plan completo, asi que la ultima que llegue
            // —de quien sea— es la que puede cerrarlo.
            $plan = match (true) {
                $firmable instanceof \App\Models\WorkPlanApproval => $firmable->workPlan,
                $firmable instanceof \App\Models\WorkPlanPerson   => $firmable->workPlan,
                default => null,
            };

            if ($plan) {
                app(\App\Services\BusinessManagement\WorkPlanCompletionService::class)->evaluar($plan);
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

    /**
     * La firma trazada de una persona: se guarda **una vez** y se reutiliza.
     *
     * Es la logica del sistema anterior, que yo no habia portado. Alli la firma
     * vive en `workers.signature` y en cada plan se guarda el marcador
     * `signed_by_IA`, que significa «la de siempre»:
     *
     *     already_signed = worker.signature.present? && worker.signature != "signed_by_IA"
     *     if already_signed && !wants_to_replace_signature
     *       params[:signature] = "signed_by_IA"
     *
     * O sea: la primera vez se le pide a la persona que firme; a partir de ahi
     * solo la foto. Tiene sentido en obra —nadie va a redibujar su firma cinco
     * veces al dia con el dedo— y ademas es lo que hace que la firma sea
     * reconocible entre planes: si cada dia se traza de nuevo, cada dia es
     * distinta y no prueba nada.
     *
     * Aqui la firma vive en `person_signatures`, con `valid_from`/`valid_to`:
     * al reemplazarla la anterior se cierra en vez de sobrescribirse, porque
     * los documentos ya firmados tienen que seguir apuntando a la que se uso.
     */
    public function firmaVigente(Person $persona): ?PersonSignature
    {
        return $persona->signatures()
            ->whereNull('valid_to')
            ->where('file_path', 'not like', self::MARCADOR . '%')
            ->latest('valid_from')
            ->first();
    }

    // ── La foto de referencia ────────────────────────────────────────────────
    //
    // La cara con la que se identifica a una persona. En el sistema anterior
    // era `workers.photo`: el administrador subia la buena cuando la que se
    // capturaba en obra salia irreconocible. Aqui vive en `person_photos`,
    // versionada igual que la firma y por lo mismo — un plan firmado hace un
    // año tiene que poder seguir enseñando la cara de entonces.

    public function fotoVigente(Person $persona): ?PersonPhoto
    {
        return $persona->photos()
            ->whereNull('valid_to')
            ->where('file_path', 'not like', self::MARCADOR . '%')
            ->latest('valid_from')
            ->first();
    }

    /**
     * Prefijo de las filas que la migracion dejo apuntando a un archivo que
     * todavia no habia llegado.
     *
     * Una fila asi NO es una firma ni una foto: es la anotacion de que la v1
     * decia que existia. Mientras el archivo no se copie no vale como tal, y
     * confundirlas es caro — la pantalla de firmar creeria que la persona ya
     * tiene firma y no le pediria el trazo, con lo que firmaria y su firma
     * seguiria sin existir.
     */
    private const MARCADOR = 'legacy/';

    /**
     * Guarda una foto de referencia nueva y jubila la anterior.
     *
     * @param  string  $imagen  binario, o `data:image/...;base64,...`
     */
    public function guardarFoto(Person $persona, string $imagen, string $origen = PersonPhoto::SUBIDA): PersonPhoto
    {
        $binario = $this->aBinario($imagen);

        if ($binario === null) {
            throw new \InvalidArgumentException('La foto no es una imagen valida.');
        }

        // Mismo tratamiento que la evidencia: 320 px de lado en WebP. Es de
        // sobra para reconocer una cara y son ~15 KB en vez de ~80.
        [$binario] = $this->comprimir($binario);
        $hash = hash('sha256', $binario);

        return DB::transaction(function () use ($persona, $binario, $hash, $origen) {
            $vigente = $this->fotoVigente($persona);

            // La misma foto otra vez no crea una version nueva.
            if ($vigente && $vigente->sha256 === $hash) {
                return $vigente;
            }

            $vigente?->update(['valid_to' => now()]);

            $ruta = sprintf('fotos/%s/%s.webp', now()->format('Y/m'), Str::random(24));
            Storage::disk('local')->put($ruta, $binario);

            return $persona->photos()->create([
                'file_path'  => $ruta,
                'sha256'     => $hash,
                'source'     => $origen,
                'valid_from' => now(),
            ]);
        });
    }

    /**
     * La primera foto que se le toma a alguien sin foto se queda como suya.
     *
     * Solo si no tiene ninguna: una capturada en obra nunca pisa a la que subio
     * el administrador, que es justo la que existe porque la de obra no valia.
     */
    protected function adoptarFotoSiNoTiene(Person $persona, string $foto): void
    {
        if ($this->fotoVigente($persona) !== null) {
            return;
        }

        try {
            $this->guardarFoto($persona, $foto, PersonPhoto::CAPTURADA);
        } catch (\Throwable $e) {
            // Esto es un extra: que no se pueda guardar la foto de referencia
            // NO puede tumbar una firma en obra, que es lo que de verdad hay
            // que registrar.
            \Illuminate\Support\Facades\Log::warning('No se pudo adoptar la foto de referencia', [
                'person_id' => $persona->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Acepta el binario tal cual o una cadena `data:image/...;base64,...`. */
    protected function aBinario(string $imagen): ?string
    {
        if (! str_starts_with($imagen, 'data:')) {
            return $imagen === '' ? null : $imagen;
        }

        $decodificado = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $imagen), true);

        return $decodificado === false || $decodificado === '' ? null : $decodificado;
    }

    /**
     * Guarda una firma nueva y jubila la anterior.
     *
     * @param  string  $base64  el trazo, tal y como sale del lienzo
     */
    public function guardarFirma(Person $persona, string $base64): PersonSignature
    {
        $binario = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $base64), true);

        if ($binario === false) {
            throw new \InvalidArgumentException('La firma no es una imagen valida.');
        }

        // Una firma es un trazo sobre fondo transparente: 480 px de ancho basta
        // para imprimirla en el PDF y pesa unos 4 KB. No se comprime con
        // `comprimir()` porque ese metodo aplana a WebP opaco y la firma se
        // pinta sobre el papel del formato.
        $hash = hash('sha256', $binario);

        return DB::transaction(function () use ($persona, $binario, $hash) {
            $vigente = $this->firmaVigente($persona);

            // La misma firma otra vez no crea una version nueva: seria ruido en
            // el historial y un archivo duplicado.
            if ($vigente && $vigente->sha256 === $hash) {
                return $vigente;
            }

            $vigente?->update(['valid_to' => now()]);

            $ruta = sprintf('firmas/%s/%s.png', now()->format('Y/m'), Str::random(24));
            Storage::disk('local')->put($ruta, $binario);

            return $persona->signatures()->create([
                'file_path'  => $ruta,
                'sha256'     => $hash,
                'source'     => 'drawn',
                'valid_from' => now(),
            ]);
        });
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
    /**
     * Encoge una foto de referencia al tamaño con el que se enseña.
     *
     * Lo mismo que se le hace a cualquier cara capturada en obra, pero abierto
     * para que la importacion del sistema viejo pase por aqui: sus fotos vienen
     * tal cual salieron de una camara y en el listado se pintan a 34 pixeles.
     * Copiarlas enteras es mandar megas por cada fila.
     *
     * Si GD no esta o la imagen no se deja procesar, devuelve la original: una
     * foto grande es mejor que ninguna.
     *
     * @return array{0:string,1:?int,2:?int} imagen, ancho y alto finales
     */
    public function encogerFoto(string $binario): array
    {
        return $this->comprimir($binario);
    }

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
