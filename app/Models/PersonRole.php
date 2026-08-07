<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonRole extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['person_id', 'role', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public const WORKER = 'worker';
    public const SUPERVISOR = 'supervisor';
    public const HSE_SUPERVISOR = 'hse_supervisor';

    public function person() { return $this->belongsTo(Person::class); }
}
