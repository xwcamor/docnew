<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormAttachment extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['form_submission_id', 'form_field_id', 'file_path', 'sha256',
                           'mime_type', 'byte_size', 'uploaded_by', 'uploaded_at'];
    protected $casts = ['uploaded_at' => 'datetime'];

    public function submission() { return $this->belongsTo(FormSubmission::class, 'form_submission_id'); }
}
