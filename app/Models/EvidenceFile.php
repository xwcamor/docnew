<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * La foto (o firma) capturada al producirse una firma.
 *
 * SIN `Auditable`, a proposito. Regla del dueño del producto: los registros de
 * la foto cuando se toma no se auditan — «no deben exhibirse esos parametros
 * ni en logs». Cada captura dejaba una fila en `audit_logs` con la ruta del
 * archivo, su hash y sus medidas: un indice de donde esta la foto de cada
 * persona, en una tabla que ve todo super/admin y que nadie purga. El rastro
 * que importa —quien firmo, cuando y como— ya lo lleva `signature_events`;
 * esta tabla es la evidencia en si, no un hecho que contar.
 */
class EvidenceFile extends Model
{
    use HasFactory;

    protected $fillable = ['signature_event_id', 'kind', 'file_path', 'sha256',
                           'byte_size', 'width', 'height', 'taken_at'];
    protected $casts = ['taken_at' => 'datetime'];

    public const FACE = 'face';
    public const SIGNATURE = 'signature';

    public function signatureEvent() { return $this->belongsTo(SignatureEvent::class); }
}
