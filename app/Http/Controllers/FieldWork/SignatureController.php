<?php

namespace App\Http\Controllers\FieldWork;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\SignatureEvent;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Services\FieldWork\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignatureController extends Controller
{
    public function __construct(private readonly SignatureService $firmas)
    {
    }

    /**
     * Pantalla de firma de un plan: la cuadrilla y los aprobadores, con quien
     * falta por firmar.
     */
    /**
     * Si el flujo de aprobacion es secuencial.
     *
     * Por defecto no lo es: hay obras donde el HSE pasa antes que el supervisor
     * y bloquearlo pararia el trabajo. Se enciende por workspace cuando el
     * procedimiento lo exige de verdad.
     */
    protected function exigeOrden(): bool
    {
        return (bool) (\App\Models\Setting::get('docufiz.sequential_approvals') ?? false);
    }

    /**
     * Una persona tal y como puede salir en pantalla.
     *
     * El `num_doc` crudo no sale de aqui: se manda `safe_num_doc`, que llega
     * como `******78` a quien no tenga `people.view_private_info`. Va en un
     * metodo y no repetido en cada `map()` para que no se olvide en el
     * siguiente sitio que haga falta.
     */
    protected function personaVisible(?\App\Models\Person $persona): ?array
    {
        return $persona ? [
            'slug'     => $persona->slug,
            'name'     => $persona->name,
            'lastname' => $persona->lastname,
            'list_name' => $persona->list_name,
            'num_doc'  => $persona->safe_num_doc,
        ] : null;
    }

    /**
     * La pantalla de firma, **de una sola persona**.
     *
     * Se llega con `?target=<slug>` desde la fila concreta de la ficha, que es
     * de donde se pulsa Firmar. Antes esta pantalla repetía las tres listas del
     * plan entero: había que volver a buscar a la persona que acababas de
     * elegir, y con la cámara abierta y guantes puestos eso es un paso de más.
     *
     * Cada fila trae **si esa persona ya tiene firma guardada**, porque cambia
     * lo que hay que pedirle: con firma en archivo sólo se toma la foto; sin
     * ella hay que capturarla. Es lo que hacía el sistema anterior con el
     * marcador `signed_by_IA`.
     */
    public function show(Request $request, WorkPlan $work_plan)
    {
        $personas = $work_plan->people()->with('person:id,slug,name,lastname,num_doc')->get();

        // Quien ya firmo no tiene nada que hacer en esta pantalla: se vuelve al
        // plan. Abrir la camara para enseñar «ya firmaste» es pedirle a alguien
        // que se ponga delante del objetivo para no hacer nada.
        $destino = $request->string('target')->toString() ?: null;

        if ($destino && $this->yaFirmo($work_plan, $destino)) {
            return redirect()
                ->route('business_management.work_plans.show', $work_plan->slug);
        }

        $aprobaciones = $work_plan->approvals()
            ->with(['person:id,slug,name,lastname,num_doc', 'approvalRule:id,name,approver_role,priority_level'])
            ->get()
            ->sortBy(fn ($a) => $a->approvalRule?->priority_level ?? 99);

        // Quién tiene firma en archivo, en UNA consulta.
        //
        // Se descartan las que siguen apuntando a `legacy/…`: son marcadores
        // que dejo la migracion porque la v1 decia que esa persona tenia firma,
        // y cuyo archivo nunca llego. Contarlas como firma es el peor de los
        // fallos posibles aqui — a esa persona no se le pide el trazo, firma,
        // y su firma sigue sin existir. Nadie lo nota hasta abrir el PDF.
        $conFirma = \App\Models\PersonSignature::query()
            ->whereNull('valid_to')
            ->where('file_path', 'not like', 'legacy/%')
            ->whereIn('person_id', $personas->pluck('person_id')->merge($aprobaciones->pluck('person_id'))->filter()->unique())
            ->pluck('person_id')
            ->flip();

        // Quien ya dio su permiso EN EL SISTEMA ANTERIOR no lo vuelve a dar.
        //
        // Regla del dueño del producto: «para los trabajadores que tienen foto
        // [migrada], activarles que ya aceptaron — eso ya lo hicieron en el
        // sistema anterior; esto solo saldria para los que recien se van a
        // crear». La señal es la foto migrada de la v1: alli la foto se
        // registraba con su consentimiento, y volver a preguntarselo a 380
        // personas en la puerta de la obra es un tramite que ya pasaron. La
        // pantalla, al verlo, salta el aviso y manda la constancia de que el
        // permiso viene del sistema anterior (version `v1-migrada`).
        $conConsentimientoPrevio = \App\Models\PersonPhoto::query()
            ->where('source', \App\Models\PersonPhoto::MIGRADA)
            ->whereIn('person_id', $personas->pluck('person_id')->merge($aprobaciones->pluck('person_id'))->filter()->unique())
            ->pluck('person_id')
            ->flip();

        return inertia('FieldWork/Sign', [
            'plan' => $work_plan->only(['slug', 'code', 'description']),
            // Sobre quién se abre. Sin esto la pantalla es un listado y hay que
            // volver a elegir a la persona que ya elegiste en la ficha.
            'target' => $request->string('target')->toString() ?: null,
            // El documento va enmascarado, como en el resto: quien firma se
            // reconoce por su nombre y por su cara, no por el DNI en pantalla.
            // Esta pantalla se usa en obra, en una tablet que pasa de mano en
            // mano, que es donde peor sienta tener 20 documentos a la vista.
            'people' => $personas->map(fn ($p) => [
                'slug' => $p->slug,
                'person' => $this->personaVisible($p->person),
                'signed' => $p->is_approved,
                'has_signature' => $conFirma->has($p->person_id),
                'prior_consent' => $conConsentimientoPrevio->has($p->person_id),
            ]),
            'approvals' => $aprobaciones
                ->map(fn ($a) => [
                    'slug' => $a->slug,
                    'role' => $a->approvalRule?->approver_role,
                    'rule_name' => $a->approvalRule?->name,
                    'person' => $this->personaVisible($a->person),
                    'required' => $a->is_required,
                    'signed' => $a->is_approved,
                    'has_signature' => $a->person_id && $conFirma->has($a->person_id),
                    'prior_consent' => $a->person_id && $conConsentimientoPrevio->has($a->person_id),
                ])
                ->values(),
            'settings' => [
                'timeoutSeconds' => (int) (\App\Models\Setting::get('docufiz.face_timeout_seconds') ?? 30),
                'liveness' => (bool) (\App\Models\Setting::get('docufiz.face_liveness') ?? true),
            ],
        ]);
    }

    /**
     * Descriptores de UNA persona, para la verificacion 1:1.
     *
     * Nunca se entrega el padron completo: el navegador solo puede comparar
     * contra la persona que ya se identifico con su documento.
     */
    public function descriptors(Request $request, Person $person)
    {
        abort_unless($request->user()->can('form_submissions.sign'), 403);

        $biometria = $person->activeBiometric;
        abort_if(! $biometria, 404, __('La persona no tiene biometria enrolada.'));

        return response()->json([
            'descriptors' => $biometria->face_descriptor,
            // El umbral se informa para el feedback en vivo, pero la decision
            // la toma el servidor al registrar la firma.
            'threshold' => $this->firmas->umbralPara($person),
        ]);
    }

    /** Registra la firma. El metodo y la aprobacion los decide el servidor. */
    public function store(Request $request)
    {
        abort_unless($request->user()->can('form_submissions.sign'), 403);

        $datos = $request->validate([
            'signable_type' => ['required', 'in:work_plan_person,work_plan_approval'],
            'signable_slug' => ['required', 'string', 'size:22'],
            'person_slug'   => ['required', 'string', 'size:22'],
            'role_signed'   => ['required', 'string', 'max:30'],
            'descriptor'    => ['nullable', 'array', 'size:128'],
            'descriptor.*'  => ['numeric'],
            'photo'         => ['nullable', 'string'],
            // El trazo, si la persona no tiene firma en archivo o si pidio
            // cambiarla. Cuando ya la tiene y no la cambia, esto no viaja: se
            // reutiliza la guardada, que es lo que hacia la v1 con el marcador
            // `signed_by_IA`.
            'signature'     => ['nullable', 'string'],
            'replace_signature' => ['nullable', 'boolean'],
            // El reto de vida —girar la cabeza, asentir— lo corre el navegador
            // y el servidor no lo puede recomprobar: al descriptor que llega no
            // se le nota si hubo gesto o si era una foto en una pantalla. Ver
            // mas abajo por que aceptar esto no abre ningun agujero.
            'liveness_failed' => ['nullable', 'boolean'],
            'latitude'      => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'     => ['nullable', 'numeric', 'between:-180,180'],
            'device_id'     => ['nullable', 'string', 'max:255'],
            'manual_override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $firmable = $datos['signable_type'] === 'work_plan_person'
            ? WorkPlanPerson::where('slug', $datos['signable_slug'])->firstOrFail()
            : WorkPlanApproval::where('slug', $datos['signable_slug'])->firstOrFail();

        $persona = Person::where('slug', $datos['person_slug'])->firstOrFail();

        // Una firma manual solo la autoriza quien tiene `signature_events.review`,
        // el permiso sensible de firmas: autorizar sin reconocimiento y ver las
        // fotos de evidencia. (Ya no abre ninguna bandeja: no la hay.)
        if (($datos['manual_override'] ?? false) && ! $request->user()->can('signature_events.review')) {
            abort(403, __('No tienes permiso para firmar sin reconocimiento.'));
        }

        if ($firmable instanceof WorkPlanApproval) {
            // **Siempre**: primero firma el ejecutante. Es la regla del sistema
            // anterior y no es opcional — el que hace el trabajo declara lo que
            // va a hacer, y el supervisor autoriza sobre esa declaración.
            // Autorizar antes es firmar en blanco.
            //
            // Antes esto sólo se comprobaba con `docufiz.sequential_approvals`
            // activo, que viene apagado: la pantalla bloqueaba y el servidor
            // dejaba pasar cualquier peticion hecha a mano.
            if ($firmable->faltaElRepresentante()) {
                abort(422, __('field_work.approval_needs_representative'));
            }

            // Y si además el workspace exige el orden estricto, tampoco se firma
            // por delante de una obligatoria de nivel anterior. Eso sí es una
            // vuelta de tuerca nuestra, y por eso es un ajuste.
            $antes = $this->exigeOrden()
                ? $firmable->aprobacionesPendientesAntes()
                : collect();

            if ($antes->isNotEmpty()) {
                abort(422, __('field_work.approval_out_of_order', [
                    'roles' => $antes->map(fn ($a) => $a->approvalRule?->role?->label
                        ?? $a->approvalRule?->approver_role)->filter()->unique()->implode(', '),
                ]));
            }
        }

        // La firma trazada se guarda ANTES del evento y una sola vez: a partir
        // de aqui esa persona ya no vuelve a dibujarla, sólo pone la cara.
        //
        // Sin firma en archivo es obligatoria. Es lo unico que la foto no puede
        // sustituir: la foto prueba que estuvo, la firma es lo que va impreso
        // en el documento que ve el inspector.
        $tieneFirma = $this->firmas->firmaVigente($persona) !== null;

        if (filled($datos['signature'] ?? null)) {
            $this->firmas->guardarFirma($persona, $datos['signature']);
        } elseif (! $tieneFirma) {
            abort(422, __('field_work.sign.signature_required', ['name' => $persona->list_name]));
        }

        $evento = $this->firmas->firmar(
            $firmable,
            $persona,
            $datos['role_signed'],
            $datos['descriptor'] ?? null,
            $datos['photo'] ?? null,
            $request->only(['latitude', 'longitude', 'device_id', 'manual_override', 'override_reason'])
                + [
                    'override_by' => $request->user()->id,
                    // El gesto de vida lo corre el navegador y el servidor no lo
                    // puede recalcular con el descriptor: si no se supero, hay
                    // que decirselo. Va DENTRO de `firmar()` y no despues
                    // —antes se marcaba el evento ya creado— porque de eso
                    // depende tambien si se guarda la foto, y para cuando el
                    // controlador ponia la marca la foto ya estaba descartada.
                    'liveness_failed' => $request->boolean('liveness_failed'),
                ],
        );


        // Los dos textos salen de `resources/lang`, no escritos aqui.
        //
        // El resto de este controlador los lleva en castellano dentro de la
        // llamada —`__('Firma revisada.')`— y esos son deuda vieja, pero estos
        // dos no se suman a ella: los lee quien acaba de firmar, y esa frase
        // tiene que poderse decir en los dos idiomas y llevar sus tildes.
        //
        // Ninguno promete una revision: la firma sin reconocimiento vale desde
        // ya, con su foto guardada. Lo que cambia entre los dos es solo si la
        // cara se llego a verificar.
        $mensaje = $evento->pending_review
            ? __('field_work.sign.left_pending')
            : __('field_work.sign.verified');

        // Las dos se anuncian en el plan, y las dos se van alli.
        //
        // La sin reconocimiento **detenia** la pantalla de firma, con su cartel
        // y su boton para volver. El sitio era el equivocado: en obra la tablet
        // pasa a la siguiente persona en cuanto suelta el dedo, y lo que hacia
        // el cartel era obligar a un toque mas para seguir. Ademas era el unico
        // sitio donde se decia — un cartel que se cierra y no deja nada detras.
        //
        // Ahora se dice en el plan, que es donde queda: en el aviso al llegar
        // y, sobre todo, en la fila de esa persona, marcada «Sin reconocer».
        // No es una promesa de revision —ya no hay bandeja— sino el hecho, que
        // tambien imprime el PDF como metodo de la firma.
        $request->session()->flash(
            $evento->pending_review ? 'warning' : 'success', $mensaje,
        );

        return response()->json([
            'method'   => $evento->method,
            'verified' => $evento->isVerified(),
            'pending_review' => $evento->pending_review,
            'message'  => $mensaje,
        ], 201);
    }

    /** ¿El destino de la pantalla —trabajador o aprobacion— ya firmo? */
    protected function yaFirmo(WorkPlan $plan, string $slug): bool
    {
        $fila = $plan->people()->where('slug', $slug)->first()
            ?? $plan->approvals()->where('slug', $slug)->first();

        return $fila !== null && ((bool) $fila->is_approved || $fila->signatureEvents()->exists());
    }

    /**
     * El album de las firmas: el plan, el trabajador y la foto de ese momento.
     *
     * SOLO SUPER — la ruta ya lo exige con `role:super` y aqui se repite
     * porque una defensa que vive solo en el fichero de rutas se pierde el dia
     * que alguien reordena middlewares.
     *
     * Tres decisiones del dueño del producto gobiernan esta pantalla:
     *
     *   · Enseña el plan, la persona y la foto (con su hora), mas el METODO de
     *     la firma y la marca «sin reconocimiento» — que son hechos, no
     *     parametros: el album existe para ver quien fallo el reconocimiento.
     *     Nada de coincidencias, coordenadas, IPs ni aparatos — esos
     *     parametros no se exhiben aqui ni en ningun log.
     *   · Mirarla NO se audita. Es lectura pura: no escribe en `audit_logs`
     *     ni en ninguna otra parte, y no hay que añadirle rastro nunca.
     *   · La foto va como URL a la ruta de evidencia —que al super se le abre
     *     por el `Gate::before`— y no incrustada: el JSON de Inertia viaja
     *     entero al navegador.
     *
     * Solo eventos con una foto DE VERDAD: las filas `legacy/…` son
     * marcadores de la importacion, no archivos (ver `signerFace`).
     */
    public function photos(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $conFoto = fn ($q) => $q
            ->where('kind', \App\Models\EvidenceFile::FACE)
            ->where('file_path', 'not like', 'legacy/%');

        $eventos = SignatureEvent::query()
            ->delWorkspace()
            ->whereHas('files', $conFoto)
            ->with([
                'person:id,slug,name,lastname',
                'files' => $conFoto,
                // La morph con su plan dentro, sin N+1: cada firmable llega
                // con `workPlan` ya cargado.
                'signable' => fn ($morph) => $morph->morphWith([
                    WorkPlanPerson::class      => ['workPlan:id,slug,code'],
                    WorkPlanApproval::class    => ['workPlan:id,slug,code'],
                    \App\Models\FormSubmission::class => ['workPlan:id,slug,code'],
                ]),
            ])
            ->latest('signed_at')
            ->paginate(24)
            ->through(function ($e) {
                // La ultima foto del evento: si la camara disparo dos veces,
                // la que quedo es la que se enseña.
                $foto = $e->files->sortByDesc('id')->first();
                $plan = $e->signable?->workPlan;

                return [
                    'id'       => $e->id,
                    'person'   => $e->person?->list_name ?? '—',
                    'plan'     => $plan ? [
                        'code' => $plan->code,
                        'url'  => route('business_management.work_plans.show', $plan->slug),
                    ] : null,
                    'taken_at' => $foto?->taken_at ?? $e->signed_at,
                    'photo_url' => $foto && Storage::disk('local')->exists($foto->file_path)
                        ? route('field_work.signatures.evidence', $foto->id)
                        : null,
                    // Como se firmo. No es un parametro de la captura —esos
                    // siguen sin salir— sino el hecho que el album vino a
                    // enseñar: quien paso el reconocimiento y quien no.
                    'method'   => $e->method,
                    // «Sin reconocimiento»: el dato `pending_review` de siempre
                    // —se conserva en la base—, que aqui ya no promete ninguna
                    // revision. Es solo la marca de que la cara no se verifico.
                    'sin_reconocimiento' => (bool) $e->pending_review,
                    // Si todavia se puede anular: una firma ya resuelta —anulada
                    // o dada por buena a mano— no se vuelve a tocar.
                    'can_void' => $e->reviewed_at === null,
                ];
            });

        return inertia('FieldWork/SignaturePhotos', ['events' => $eventos]);
    }

    /**
     * Anular (o, en el limite, dar por buena) una firma — SOLO SUPER.
     *
     * Es lo que quedo de la antigua bandeja de revision. La firma sin
     * reconocimiento vale desde que se toma: el PDF imprime su metodo y el
     * album la enseña marcada, sin cola ni obligacion de mirar nada. Lo unico
     * que hace falta poder hacer es tumbar una que se sabe falsa, y esa
     * decision excepcional es del super, desde el album.
     *
     * La ruta ya exige `role:super`; se repite aqui porque una defensa que
     * vive solo en el fichero de rutas se pierde el dia que alguien reordena
     * middlewares.
     */
    public function resolve(Request $request, SignatureEvent $signature_event)
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $this->soloDelWorkspace($signature_event);

        $datos = $request->validate([
            'accepted' => ['required', 'boolean'],
            'reason'   => ['nullable', 'string', 'max:255'],
        ]);

        $this->firmas->revisar($signature_event, $datos['accepted'], $request->user()->id, $datos['reason'] ?? null);

        return back()->with('success', $datos['accepted']
            ? __('Firma revisada.')
            : __('field_work.photos.voided'));
    }

    /** La evidencia no es publica: se sirve autenticada, con permiso y del propio workspace. */
    public function evidence(Request $request, \App\Models\EvidenceFile $evidence_file)
    {
        abort_unless($request->user()->can('signature_events.review'), 403);
        $this->soloDelWorkspace($evidence_file->signatureEvent);

        return Storage::disk('local')->response($evidence_file->file_path);
    }

    /**
     * La firma tiene que colgar de un plan del propio workspace.
     *
     * El permiso `signature_events.review` dice QUE puede revisar firmas, no
     * DE QUIEN. Sin esto, con el id de la fila en la mano —o el de un fichero
     * de evidencia— un admin resolvia la firma de otro contratista o se
     * descargaba la foto de su trabajador. 404 y no 403: fuera del workspace
     * esa fila no existe, y decir «no puedes» ya confirma que existe.
     */
    protected function soloDelWorkspace(?SignatureEvent $evento): void
    {
        abort_unless(
            $evento !== null && SignatureEvent::query()->whereKey($evento->id)->delWorkspace()->exists(),
            404,
        );
    }

    /**
     * Enrolamiento facial: se guardan los descriptores, nunca la foto.
     *
     * Es lo que falta cuando alguien va a firmar por primera vez: la pantalla
     * detecta que no tiene cara registrada y guia la captura de varias muestras.
     *
     * EL CONSENTIMIENTO SE GUARDA, NO SOLO SE EXIGE.
     *
     * `'consent' => ['accepted']` ya estaba, pero la pantalla mandaba `true` a
     * pelo: nadie preguntaba nada. Y aunque se hubiera preguntado, el si de la
     * persona se quedaba dentro de una peticion HTTP que se descarta.
     *
     * Un descriptor facial es un dato biometrico, y lo que se pide demostrar no
     * es que el sistema pidiera permiso: es que ESTA persona lo dio, CUANDO y
     * sobre QUE TEXTO. Por eso el texto viaja entero desde la pantalla y se
     * guarda tal cual, junto a su version y la IP. Ver la migracion
     * `2026_08_14_090000`.
     */
    public function enroll(Request $request, Person $person)
    {
        abort_unless($request->user()->can('people.edit'), 403);

        $datos = $request->validate([
            'descriptors'     => ['required', 'array', 'min:1', 'max:5'],
            'descriptors.*'   => ['array', 'size:128'],
            'descriptors.*.*' => ['numeric'],
            // Obligatorios los tres: sin ellos no hay consentimiento que
            // guardar, y un enrolamiento sin consentimiento registrado es
            // exactamente lo que habia antes.
            'consent'         => ['accepted'],
            'consent_version' => ['required', 'string', 'max:20'],
            'consent_text'    => ['required', 'string', 'max:4000'],
            // Un fotograma del enrolamiento, opcional. Ver mas abajo.
            'photo'           => ['nullable', 'string'],
        ]);

        abort_if(
            $person->activeBiometric !== null,
            409,
            __('Esta persona ya tiene una cara registrada. Debe retirarse antes de volver a enrolar.'),
        );

        $biometria = \App\Models\PersonBiometric::create([
            'person_id'       => $person->id,
            'face_descriptor' => $datos['descriptors'],
            'enrolled_at'     => now(),
            'enrolled_by'     => $request->user()->id,
            'is_active'       => true,
            // Lo que acepto, cuando y desde donde. El texto va entero: una
            // referencia a la version no responde a «¿a que dijo que si?»
            // cuando hayan pasado dos años y tres redacciones.
            'consent_at'      => now(),
            'consent_version' => $datos['consent_version'],
            'consent_text'    => $datos['consent_text'],
            'consent_ip'      => $request->ip(),
        ]);

        // Y si esta persona no tiene foto de referencia, se queda con una de
        // aqui. Cierra un agujero que si no no cierra nunca: se da de alta a
        // alguien sin foto —el alta no la exige—, se enrola (que guarda
        // descriptores y ninguna imagen) y a partir de ahi el servidor la
        // reconoce siempre, con lo que **no se guarda foto de evidencia**. La
        // ficha del plan diria «reconocimiento facial» sin una sola cara que
        // enseñar, y por mucho que esa persona firmara nunca aparecería una.
        //
        // No pisa nada: si ya hay foto —la que subio el administrador, o la
        // que llego de la importacion— se respeta. Y no puede tumbar el
        // enrolamiento, que es lo que de verdad se vino a hacer.
        if (filled($datos['photo'] ?? null)) {
            $this->firmas->adoptarFotoSiNoTiene($person, $datos['photo']);
        }

        return response()->json([
            'samples' => count($datos['descriptors']),
            'message' => __('Cara registrada. Ya puede firmar con reconocimiento.'),
        ], 201);
    }

    /** Retira la biometria vigente para poder volver a enrolar. */
    public function unenroll(Request $request, Person $person)
    {
        abort_unless($request->user()->can('signature_events.review'), 403);

        $person->biometrics()->update(['is_active' => false]);

        return back()->with('success', __('Biometria retirada.'));
    }
}
