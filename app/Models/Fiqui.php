<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fiqui — una muestra de análisis fisicoquímico del aceite.
 *
 * Cinco parámetros (rig/ten/acid/wat/pot). El diagnóstico depende del tipo de
 * aceite y de la clase de tensión del transformador (norma IEEE C57.106). El
 * score/rating/condición se cachean; los umbrales viven en fiquis_rules.json.
 */
class Fiqui extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenant;

    protected $table = 'fiquis';

    protected string $auditModule = 'fiquis';

    /** Parámetros que PUNTÚAN (entran al DGAF / estado). */
    public const PARAMS = ['rig', 'ten', 'acid', 'wat', 'pot'];

    /**
     * Parámetros de REFERENCIA: tienen su umbral y se colorean, pero no SUMAN al
     * DGAF. Miden la misma propiedad que uno que ya puntúa (las dos rigideces;
     * el factor de potencia a 25 y 100 °C), así que sumarlos contaría dos veces
     * lo mismo y diluiría acidez, agua y tensión interfacial.
     *
     * OJO: sí SUSTITUYEN. Cuando el informe trae el método alterno pero no el
     * principal, el alterno ocupa su lugar en el promedio (contra su propia
     * norma) — ver FiquisDiagnosisService::measurementFor(). Por eso hay que
     * pasarlos SIEMPRE al motor: usar FIELDS, no PARAMS, al armar los valores.
     */
    public const EXTRA = ['rig877', 'pot100'];

    /** Todos los campos numéricos medibles (para validar y guardar). */
    public const FIELDS = ['rig', 'ten', 'acid', 'wat', 'pot', 'rig877', 'pot100'];

    /**
     * Métodos que miden la misma propiedad: principal → alterno. Basta con uno
     * de los dos para que la propiedad entre al diagnóstico, así que los cuatro
     * campos son OPCIONALES en el formulario. Exigir cualquiera de ellos es lo
     * que llevó al sistema viejo a llenar de ceros la columna que el laboratorio
     * no midió, y el 0 el motor lo leía como una rigidez nula.
     */
    public const ALTERNATE = ['rig' => 'rig877', 'pot' => 'pot100'];

    protected $fillable = [
        'transformer_id', 'sample_date', 'report_number', 'laboratory_id',
        'rig', 'ten', 'acid', 'wat', 'pot', 'rig877', 'pot100',
        // Condiciones de ensayo que informa el laboratorio por API (norma, gap
        // de electrodos, temperatura). Constancia, no entra al diagnóstico.
        'methods',
        'score', 'rating', 'condition',
        'tenant_id', 'created_by', 'deleted_by',
    ];

    protected $casts = [
        'sample_date' => 'datetime',
        'rig' => 'decimal:3', 'ten' => 'decimal:3', 'acid' => 'decimal:4',
        'wat' => 'decimal:2', 'pot' => 'decimal:4',
        'rig877' => 'decimal:3', 'pot100' => 'decimal:4',
        'methods' => 'array',
        'score' => 'decimal:2', 'rating' => 'decimal:1',
    ];

    public function transformer(): BelongsTo
    {
        return $this->belongsTo(Transformer::class);
    }

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Laboratory::class);
    }
}
