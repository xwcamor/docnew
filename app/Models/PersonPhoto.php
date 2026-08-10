<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * La foto de referencia de una persona: la que sirve para reconocerla.
 *
 * Versionada, igual que {@see PersonSignature}: al reemplazarla la anterior se
 * cierra con `valid_to` en vez de sobrescribirse, para que un plan firmado hace
 * un año pueda seguir enseñando la cara con la que se le identifico entonces.
 */
class PersonPhoto extends Model
{
    use HasFactory;
    use Auditable;

    /** La subio el administrador. Es la buena. */
    public const SUBIDA = 'uploaded';

    /** La primera que se capturo al firmar, a falta de otra mejor. */
    public const CAPTURADA = 'captured';

    /** Viene de `workers.photo` del sistema anterior. */
    public const MIGRADA = 'migrated';

    protected $fillable = ['person_id', 'file_path', 'sha256', 'source', 'valid_from', 'valid_to'];

    protected $casts = ['valid_from' => 'datetime', 'valid_to' => 'datetime'];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /** ¿Es la buena, la que subio una persona mirando la cara? */
    public function esDeCalidad(): bool
    {
        return in_array($this->source, [self::SUBIDA, self::MIGRADA], true);
    }
}
