<?php

namespace App\Models;

use App\Casts\Cifrado;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * La cara enrolada de una persona y el permiso que dio para registrarla.
 *
 * DOS COLUMNAS CIFRADAS EN REPOSO, Y POR QUE ESTAS DOS
 * ----------------------------------------------------
 * `face_descriptor` son 128 numeros que describen una cara. Es un dato
 * biometrico y no se puede revocar: a quien le filtran la contraseña se le
 * cambia; a quien le filtran la cara, no. Nunca se busca por el —solo se lee
 * entero, para una persona concreta, en la verificacion 1:1— asi que cifrarlo
 * no cuesta nada y no obliga a ningun indice ciego.
 *
 * `consent_text` es el texto que esa persona tuvo delante al aceptar. Tampoco
 * se busca nunca por el.
 *
 * LAS DOS VERDADES INCOMODAS DE ESTE CIFRADO
 * ------------------------------------------
 *   · Protege contra **quien lee un backup o el disco**, no contra quien tiene
 *     el `APP_KEY`. Con la clave, esto se abre entero. El backup deja de ser un
 *     volcado de caras; el servidor comprometido sigue siendolo.
 *   · **Si se pierde el `APP_KEY`, esto no se recupera.** No hay copia de
 *     seguridad de la clave dentro de la aplicacion. Hay que custodiarla fuera
 *     y rotarla con un plan, porque rotar obliga a re-cifrar (ver
 *     `App\Support\CifradoEnReposo` y `docufiz:cifrar-datos-sensibles`).
 */
class PersonBiometric extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['person_id', 'face_descriptor', 'threshold', 'enrolled_at', 'enrolled_by', 'is_active',
                           'consent_at', 'consent_version', 'consent_text', 'consent_ip'];
    protected $casts = ['face_descriptor' => Cifrado::class . ':array', 'consent_text' => Cifrado::class,
                        'threshold' => 'float',
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
    // v2: la primera redaccion decia «lo que se guarda NO es tu fotografia» a
    // secas, y la camara de la firma SI puede guardar una foto como evidencia
    // del acto — ademas de la firma trazada, que se guarda y se reutiliza. La
    // v2 autoriza las tres cosas: descriptor, foto del momento de firmar y
    // firma trazada.
    public const CONSENT_VERSION = '2026-08-v2';

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
     * Desde que `consent_text` esta cifrado, lo que llega a `audit_logs` es el
     * sobre, no el texto: el trait escribe `getAttributes()`, que son los
     * valores tal y como van a la base. Es lo correcto —seria absurdo cifrar la
     * columna y dejar una copia en claro en la tabla de al lado— y se sigue
     * leyendo con el mismo `APP_KEY`. La pantalla de historial enseña ese campo
     * en crudo; esta anotado en `docs/PENDIENTES.md`.
     *
     * `updated_at` va en la lista porque al declarar esta propiedad se pierde
     * la exclusion por defecto del trait.
     */
    protected $auditExclude = ['updated_at', 'face_descriptor'];

    public function person() { return $this->belongsTo(Person::class); }
}
