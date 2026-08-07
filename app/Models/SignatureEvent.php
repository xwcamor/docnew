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
    ];

    protected $casts = [
        'signed_at' => 'datetime', 'reviewed_at' => 'datetime',
        'used_ai' => 'boolean', 'pending_review' => 'boolean',
        'manual_override' => 'boolean', 'evidence_missing' => 'boolean',
        'match_distance' => 'float', 'threshold_used' => 'float',
        'latitude' => 'float', 'longitude' => 'float',
    ];

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

    /** Verificada = la comparo el servidor y quedo por debajo del umbral. */
    public function isVerified(): bool
    {
        return $this->method === self::FACE_RECOGNITION && ! $this->manual_override;
    }
}
