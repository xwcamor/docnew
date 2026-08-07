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
    public function show(WorkPlan $work_plan)
    {
        return inertia('FieldWork/Sign', [
            'plan' => $work_plan->only(['slug', 'code', 'description']),
            'people' => $work_plan->people()->with('person:id,slug,name,lastname,num_doc')->get()
                ->map(fn ($p) => [
                    'slug' => $p->slug,
                    'person' => $p->person->only(['slug', 'name', 'lastname', 'num_doc']),
                    'signed' => $p->is_approved,
                ]),
            'approvals' => $work_plan->approvals()->with('person:id,slug,name,lastname,num_doc')->get()
                ->map(fn ($a) => [
                    'slug' => $a->slug,
                    'person' => $a->person?->only(['slug', 'name', 'lastname', 'num_doc']),
                    'required' => $a->is_required,
                    'signed' => $a->is_approved,
                ]),
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

        $evento = $this->firmas->firmar(
            $firmable,
            $persona,
            $datos['role_signed'],
            $datos['descriptor'] ?? null,
            $datos['photo'] ?? null,
            $request->only(['latitude', 'longitude', 'device_id', 'manual_override', 'override_reason'])
                + ['override_by' => $request->user()->id],
        );

        return response()->json([
            'method'   => $evento->method,
            'verified' => $evento->isVerified(),
            'pending_review' => $evento->pending_review,
            'message'  => $evento->pending_review
                ? __('Firma registrada. Queda pendiente de revision del supervisor.')
                : __('Firma verificada.'),
        ], 201);
    }

    /** Bandeja de firmas que quedaron pendientes de revision. */
    public function review(Request $request)
    {
        abort_unless($request->user()->can('signature_events.review'), 403);

        $pendientes = SignatureEvent::pendingReview()
            ->with(['person:id,slug,name,lastname,num_doc', 'files'])
            ->latest('signed_at')
            ->paginate(20)
            ->through(fn ($e) => [
                'id' => $e->id,
                'person' => $e->person->only(['slug', 'name', 'lastname', 'num_doc']),
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

        $datos = $request->validate([
            'accepted' => ['required', 'boolean'],
            'reason'   => ['nullable', 'string', 'max:255'],
        ]);

        $this->firmas->revisar($signature_event, $datos['accepted'], $request->user()->id, $datos['reason'] ?? null);

        return back()->with('success', __('Firma revisada.'));
    }

    /** La evidencia no es publica: se sirve autenticada y con permiso. */
    public function evidence(Request $request, \App\Models\EvidenceFile $evidence_file)
    {
        abort_unless($request->user()->can('signature_events.review'), 403);

        return Storage::disk('local')->response($evidence_file->file_path);
    }
}
