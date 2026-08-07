<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonBiometric extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['person_id', 'face_descriptor', 'threshold', 'enrolled_at', 'enrolled_by', 'is_active'];
    protected $casts = ['face_descriptor' => 'array', 'threshold' => 'float',
                        'enrolled_at' => 'datetime', 'is_active' => 'boolean'];

    /** No se expone nunca al frontend salvo en la verificacion 1:1. */
    protected $hidden = ['face_descriptor'];

    public function person() { return $this->belongsTo(Person::class); }
}
