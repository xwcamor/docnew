<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SignatureEvent extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = [
        'signable_type', 'signable_id', 'person_id', 'role_signed', 'signed_at', 'method',
        'used_ai', 'match_distance', 'threshold_used', 'pending_review', 'manual_override',
        'override_reason', 'override_by', 'reviewed_at', 'reviewed_by', 'evidence_missing',
        'latitude', 'longitude', 'device_id', 'ip_address', 'user_agent',
        'country_code', 'region', 'city', 'tenant_id',
        'legacy_id', 'legacy_source',
    ];

    protected $casts = [
        'signed_at' => 'datetime', 'reviewed_at' => 'datetime',
        'used_ai' => 'boolean', 'pending_review' => 'boolean',
        'manual_override' => 'boolean', 'evidence_missing' => 'boolean',
        'match_distance' => 'float', 'threshold_used' => 'float',
        'latitude' => 'float', 'longitude' => 'float',
    ];

    /**
     * Los parametros de la captura NO van al historial de cambios.
     *
     * Regla del dueño del producto: «no deben exhibirse esos parametros ni en
     * logs». La fila de `signature_events` los conserva —son el dato con el
     * que se revisa una firma dudosa y se ajusta el umbral, y se enseñan solo
     * al super en la ficha del plan— pero `Auditable` escribia la fila ENTERA
     * en `audit_logs.new_values` al crearla: coordenadas, IP, aparato y
     * navegador de una persona quedaban en una tabla que ve todo super/admin
     * desde su pantalla de historial, y que nadie purga.
     *
     * Lo que si queda en el historial es el hecho: quien firmo que, cuando y
     * por que metodo. Eso es lo que hay que poder enseñar.
     *
     * `updated_at` va en la lista porque declarar esta propiedad pisa la
     * exclusion por defecto del trait.
     */
    protected $auditExclude = ['updated_at', 'match_distance', 'threshold_used',
                               'latitude', 'longitude', 'device_id', 'ip_address', 'user_agent'];

    /** Como se produjo la firma. Nunca se guarda un texto magico en otra columna. */
    public const FACE_RECOGNITION = 'face_recognition';
    public const TIMEOUT_CAPTURE  = 'timeout_capture';
    public const MANUAL           = 'manual';
    public const REUSED           = 'reused';
    public const MIGRATED         = 'migrated';

    public function signable() { return $this->morphTo(); }
    public function person() { return $this->belongsTo(Person::class); }
    public function files() { return $this->hasMany(EvidenceFile::class); }

    public function scopePendingReview($q) { return $q->where('pending_review', true); }

    /**
     * Solo las firmas del workspace de quien mira.
     *
     * `signature_events` no lleva scope de workspace propio —solo `Auditable`—
     * asi que una consulta suelta cruza contratistas. La bandeja de revision lo
     * hacia: un admin con `signature_events.review` veia las firmas dudosas de
     * TODOS, con nombre, documento y foto de gente que no es suya.
     *
     * Se acota por el plan del que cuelga la firma, que si tiene el scope. Es
     * polimorfica, de ahi `whereHasMorph` sobre las tres tablas firmables: un
     * `whereIn('signable_id', ...)` compararia ids de tablas distintas.
     */
    public function scopeDelWorkspace($q)
    {
        return $q->whereHasMorph(
            'signable',
            [WorkPlanPerson::class, WorkPlanApproval::class, FormSubmission::class],
            fn ($s) => $s->whereIn('work_plan_id', WorkPlan::query()->select('id')),
        );
    }

    /** Verificada = la comparo el servidor y quedo por debajo del umbral. */
    public function isVerified(): bool
    {
        return $this->method === self::FACE_RECOGNITION && ! $this->manual_override;
    }

    /**
     * Cuanto se parecio la cara, en porcentaje.
     *
     * Por dentro se guarda una **distancia** euclidiana: 0 es la misma cara y
     * cuanto mas alto, mas distinta. Es lo correcto para comparar y lo peor
     * posible para leer — «distancia 0,32 · umbral 0,50» no le dice nada a
     * quien esta revisando una firma, y encima se lee al reves de como se
     * piensa: el numero bueno es el bajo.
     *
     * Hacia fuera se cuenta como coincidencia, donde 100% es identica. La
     * distancia se sigue guardando: es el dato con el que se compara y con el
     * que se ajusta el umbral.
     */
    public function getMatchPercentAttribute(): ?int
    {
        if ($this->match_distance === null) {
            return null;
        }

        return max(0, min(100, (int) round((1 - (float) $this->match_distance) * 100)));
    }
}
