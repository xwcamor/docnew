<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenantOrGlobal;
use App\Traits\Lockable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Que documento lleva una persona —DNI, carne de extranjeria, pasaporte, PTP—
 * o una empresa: RUC en Peru, RUT en Chile, CUIT en Argentina, NIT en Colombia.
 *
 * Los dos casos son el mismo catalogo con `scope` distinto. La empresa tenia el
 * suyo suelto: un `num_doc` sin tipo, sin longitud y llamado «codigo» en la
 * pantalla, que no es nada. La pista estaba en el propio mensaje de error del
 * importador —«El codigo (RUC/CUIT/RFC/NIT)»—: la frase reconoce que el dato
 * cambia de pais en pais y que no habia donde guardar cual es.
 *
 * Estaba escrito a mano dentro de `StorePersonRequest`
 * (`Rule::in(['DNI', 'CE', 'PASAPORTE'])`), asi que anadir el PTP —que en Peru
 * llevan miles de venezolanos— pasaba por tocar PHP y desplegar.
 *
 * `code` es lo que se guarda en `people.doc_type`. Se guarda el TEXTO y no la
 * clave ajena a proposito: la ficha de una persona firmada hace tres años tiene
 * que seguir diciendo «DNI» aunque alguien renombre o borre la fila del
 * catalogo, igual que las respuestas de los formatos guardan la etiqueta y no
 * el numero.
 */
class DocumentType extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;
    use Lockable;
    use BelongsToTenantOrGlobal;

    /** Solo cifras: el DNI peruano, el carné de extranjería, el PTP. */
    public const SOLO_CIFRAS = 'digits';

    /** Cifras y letras: el pasaporte. */
    public const CIFRAS_Y_LETRAS = 'alphanumeric';

    /** El documento de una persona: DNI, carne de extranjeria, PTP, pasaporte. */
    public const PERSONA = 'person';

    /** El de una empresa: RUC, RUT, CUIT, RFC, NIT, CNPJ. */
    public const EMPRESA = 'company';

    protected $fillable = [
        'slug', 'country_id', 'scope', 'code', 'name', 'min_length', 'max_length', 'allowed_chars',
        'for_foreigners', 'is_active',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'for_foreigners' => 'boolean',
        'min_length' => 'integer',
        'max_length' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** «DNI — Documento Nacional de Identidad», o solo la sigla si no hay mas. */
    public function getLabelAttribute(): string
    {
        return $this->name ? "{$this->code} — {$this->name}" : (string) $this->code;
    }

    /**
     * Las reglas de validacion del NUMERO para este tipo.
     *
     * Se usan al dar de alta a una persona, no al buscarla. La diferencia
     * importa: el buscador de trabajadores se apoya en la coincidencia exacta
     * del documento y no en su longitud, porque el volcado de la v1 tiene dos
     * peruanos con DNI de siete caracteres y una regla de longitud los dejaria
     * fuera para siempre.
     *
     * @return array<int, string>
     */
    public function reglasDelNumero(): array
    {
        return array_values(array_filter([
            $this->min_length ? 'min:' . $this->min_length : null,
            $this->max_length ? 'max:' . $this->max_length : null,
        ]));
    }

    /**
     * ¿Este numero encaja con los caracteres que admite el tipo?
     *
     * Los espacios no entran en ningun caso: no hay documento que los lleve
     * dentro, y uno invisible al final parte en dos a la misma persona.
     */
    public function admiteElNumero(string $numDoc): bool
    {
        return match ($this->allowed_chars) {
            self::SOLO_CIFRAS     => (bool) preg_match('/^[0-9]+$/', $numDoc),
            self::CIFRAS_Y_LETRAS => (bool) preg_match('/^[A-Za-z0-9]+$/', $numDoc),
            default               => ! preg_match('/\s/', $numDoc),
        };
    }

    /** Como decirle a alguien que ese numero no vale, en su idioma. */
    public function porQueNoAdmite(): string
    {
        return match ($this->allowed_chars) {
            self::SOLO_CIFRAS     => __('people.num_doc_solo_cifras', ['type' => $this->code]),
            self::CIFRAS_Y_LETRAS => __('people.num_doc_cifras_y_letras', ['type' => $this->code]),
            default               => __('people.num_doc_sin_espacios'),
        };
    }

    /**
     * Los tipos vigentes de un pais, para un selector.
     *
     * Por defecto los de persona: es lo que pedian todas las llamadas que ya
     * existian cuando el catalogo solo servia para gente.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function delPais(?int $countryId, string $scope = self::PERSONA): \Illuminate\Support\Collection
    {
        return static::query()
            ->active()
            ->where('scope', $scope)
            ->when($countryId, fn ($q) => $q->where('country_id', $countryId))
            ->orderBy('code')
            ->get();
    }

    /**
     * Con que documento se identifica una EMPRESA de este pais: RUC, RUT, NIT…
     *
     * Un pais tiene uno solo —es el documento tributario— asi que aqui no hay
     * nada que elegir y se puede rellenar sin preguntar. Lo usan las tres
     * puertas por las que entra una empresa sin pasar por el formulario: el
     * migrador de la v1 y los dos seeders.
     *
     * Hizo falta al quitarle a la columna su `default 'RUC'`. Ese valor por
     * defecto estaba mal —una contratista chilena se guardaba en silencio con un
     * documento peruano— pero era lo que tapaba que el migrador nunca escribiera
     * el tipo: al quitarlo, las empresas migradas quedaron con el numero a secas
     * y en el listado salia «20522756441» sin decir que era un RUC.
     *
     * Devuelve null si el pais no tiene ninguno o tiene mas de uno: en ese caso
     * no hay respuesta obvia y es mejor dejarlo vacio que inventarse una.
     */
    public static function deLaEmpresaDe(?int $countryId): ?string
    {
        if (! $countryId) {
            return null;
        }

        $tipos = static::delPais($countryId, self::EMPRESA);

        return $tipos->count() === 1 ? $tipos->first()->code : null;
    }
}
