<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonSignature extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['person_id', 'file_path', 'sha256', 'source', 'valid_from', 'valid_to'];
    protected $casts = ['valid_from' => 'datetime', 'valid_to' => 'datetime'];

    public function person() { return $this->belongsTo(Person::class); }
}
