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

        // Una firma manual solo la autoriza quien puede revisar firmas.
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
                + ['override_by' => $request->user()->id],
        );

        // El gesto de vida fallido deja la firma pendiente de revision.
        //
        // La cara coincidio, asi que `firmar()` la da por reconocida: mide la
        // distancia entre descriptores y ahi no hay ni rastro del gesto. Pero
        // una cara que coincide sin gesto es exactamente lo que da una foto
        // puesta delante del objetivo, que es contra lo que existe el reto. Que
        // eso saliera «verificada» y no pasara por la bandeja del supervisor
        // hacia del reto un adorno.
        //
        // Fiarse del cliente aqui no abre nada: **solo puede empeorar** el
        // resultado. Una peticion hecha a mano diciendo `liveness_failed=false`
        // no consigue nada que no consiguiera ya callandose, y diciendo `true`
        // lo unico que logra es mandar su propia firma a revision.
        if ($request->boolean('liveness_failed') && ! $evento->pending_review) {
            $evento->forceFill(['pending_review' => true])->save();
        }

        // Los dos textos salen de `resources/lang`, no escritos aqui.
        //
        // El resto de este controlador los lleva en castellano dentro de la
        // llamada —`__('Firma revisada.')`— y esos son deuda vieja, pero estos
        // dos no se suman a ella: el de arriba es lo que lee alguien que cree
        // que ya firmo y todavia no vale su firma, y esa frase tiene que
        // poderse decir en los dos idiomas y llevar sus tildes.
        $mensaje = $evento->pending_review
            ? __('field_work.sign.left_pending')
            : __('field_work.sign.verified');

        // La firma limpia se anuncia en el plan: la pantalla se va alli sola y
        // el aviso viaja en la sesion, que la visita de Inertia recoge.
        //
        // La que queda pendiente de revision NO: esa **detiene** la pantalla de
        // firma y se lee alli, con su boton para volver. Es la unica que lo
        // hace, y es a proposito: la persona cree que ya firmo y en realidad su
        // firma no vale hasta que la mire un supervisor. Por flash no vale —
        // solo hay canal `success` y `error` (ver HandleInertiaRequests), los
        // dos son avisos que se desvanecen solos y ninguno de los dos colores
        // dice «pendiente» (docs/UI.md §5).
        if (! $evento->pending_review) {
            $request->session()->flash('success', $mensaje);
        }

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

    /** Bandeja de firmas que quedaron pendientes de revision. */
    public function review(Request $request)
    {
        abort_unless($request->user()->can('signature_events.review'), 403);

        $pendientes = SignatureEvent::pendingReview()
            ->delWorkspace()
            ->with(['person:id,slug,name,lastname,num_doc', 'files'])
            ->latest('signed_at')
            ->paginate(20)
            ->through(fn ($e) => [
                'id' => $e->id,
                'person' => $this->personaVisible($e->person),
                'method' => $e->method,
                'signed_at' => $e->signed_at,
                'match_distance' => $e->match_distance,
                'threshold_used' => $e->threshold_used,
                'manual_override' => $e->manual_override,
                'override_reason' => $e->override_reason,
                'evidence' => $e->files->map(fn ($f) => [
                    'kind' => $f->kind,
                    'url'  => Storage::disk('local')->exists($f->file_path)
                        ? route('field_work.signatures.evidence', $f->id)
                        : null,
                ]),
            ]);

        return inertia('FieldWork/SignatureReview', ['events' => $pendientes]);
    }

    /** Resolucion de una firma pendiente. */
    public function resolve(Request $request, SignatureEvent $signature_event)
    {
        abort_unless($request->user()->can('signature_events.review'), 403);
        $this->soloDelWorkspace($signature_event);

        $datos = $request->validate([
            'accepted' => ['required', 'boolean'],
            'reason'   => ['nullable', 'string', 'max:255'],
        ]);

        $this->firmas->revisar($signature_event, $datos['accepted'], $request->user()->id, $datos['reason'] ?? null);

        return back()->with('success', __('Firma revisada.'));
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
     */
    public function enroll(Request $request, Person $person)
    {
        abort_unless($request->user()->can('people.edit'), 403);

        $datos = $request->validate([
            'descriptors'     => ['required', 'array', 'min:1', 'max:5'],
            'descriptors.*'   => ['array', 'size:128'],
            'descriptors.*.*' => ['numeric'],
            'consent'         => ['accepted'],
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
        ]);

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
