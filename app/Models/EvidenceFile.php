<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvidenceFile extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['signature_event_id', 'kind', 'file_path', 'sha256',
                           'byte_size', 'width', 'height', 'taken_at'];
    protected $casts = ['taken_at' => 'datetime'];

    public const FACE = 'face';
    public const SIGNATURE = 'signature';

    public function signatureEvent() { return $this->belongsTo(SignatureEvent::class); }
}
