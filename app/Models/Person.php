<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'slug', 'country_id', 'nationality_id', 'doc_type', 'num_doc',
        'name', 'lastname', 'birthdate', 'is_active', 'legacy_id', 'legacy_table',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = ['is_active' => 'boolean', 'birthdate' => 'date'];

    public function getFullNameAttribute(): string
    {
        return trim($this->name . ' ' . $this->lastname);
    }

    public function country() { return $this->belongsTo(Country::class); }
    public function companyLinks() { return $this->hasMany(PersonCompanyLink::class); }
    public function companies() { return $this->belongsToMany(Company::class, 'person_company_links'); }
    public function roles() { return $this->hasMany(PersonRole::class); }
    public function biometrics() { return $this->hasMany(PersonBiometric::class); }
    public function signatures() { return $this->hasMany(PersonSignature::class); }
    public function signatureEvents() { return $this->hasMany(SignatureEvent::class); }

    /** Biometria vigente: es la que se compara al firmar. */
    public function activeBiometric()
    {
        return $this->hasOne(PersonBiometric::class)->where('is_active', true)->latestOfMany();
    }

    /** Firma de referencia vigente. */
    public function currentSignature()
    {
        return $this->hasOne(PersonSignature::class)->whereNull('valid_to')->latestOfMany();
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('role', $role)->where('is_active', true)->exists();
    }
}
