<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonCompanyLink extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['person_id', 'company_id', 'position_id', 'started_on', 'ended_on', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'started_on' => 'date', 'ended_on' => 'date'];

    public function person() { return $this->belongsTo(Person::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function position() { return $this->belongsTo(Position::class); }
}
