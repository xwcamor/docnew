<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonBiometric extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['person_id', 'face_descriptor', 'threshold', 'enrolled_at', 'enrolled_by', 'is_active',
                           'consent_at', 'consent_version', 'consent_text', 'consent_ip'];
    protected $casts = ['face_descriptor' => 'array', 'threshold' => 'float',
                        'enrolled_at' => 'datetime', 'consent_at' => 'datetime', 'is_active' => 'boolean'];

    /**
     * La version del texto de consentimiento que se sirve ahora mismo.
     *
     * Se sube A MANO cada vez que el texto cambie de fondo —no por corregir una
     * coma— y con eso se puede agrupar quien acepto que. El texto en si vive en
     * `resources/lang/*` (`field_work.consent.text`) y se guarda ENTERO junto a
     * la version: la version sirve para agrupar, el texto para responder a «¿a
     * que dijo que si?» cuando hayan pasado dos años y tres redacciones.
     */
    public const CONSENT_VERSION = '2026-08-v1';

    /** No se expone nunca al frontend salvo en la verificacion 1:1. */
    protected $hidden = ['face_descriptor'];

    /**
     * El descriptor NO va al historial de cambios.
     *
     * `Auditable` escribe `getAttributes()` entero en `audit_logs.new_values` al
     * crear la fila, asi que enrolar a alguien dejaba una SEGUNDA COPIA de sus
     * 128 numeros faciales en otra tabla: una que nadie purga, que no tiene el
     * `$hidden` de este modelo y que sobrevive a borrar la biometria. O sea que
     * retirar la cara de una persona no la retiraba.
     *
     * Lo que si se queda en el historial —y tiene que quedarse— es el
     * consentimiento: quien, cuando y sobre que texto. Eso es justo lo que hay
     * que poder enseñar.
     *
     * `updated_at` va en la lista porque al declarar esta propiedad se pierde
     * la exclusion por defecto del trait.
     */
    protected $auditExclude = ['updated_at', 'face_descriptor'];

    public function person() { return $this->belongsTo(Person::class); }
}
