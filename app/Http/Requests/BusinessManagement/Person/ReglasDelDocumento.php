<?php

namespace App\Http\Requests\BusinessManagement\Person;

use App\Models\DocumentType;
use App\Models\Person;
use App\Support\PrivateInfo;
use Illuminate\Support\Facades\DB;

/**
 * Las reglas del documento de identidad, que el alta y la edición comparten.
 *
 * Estaban copiadas palabra por palabra en los dos FormRequest —tipo, unicidad
 * y longitud, sesenta líneas idénticas— y eso es exactamente cómo se arreglan
 * las cosas en un sitio y no en el otro.
 */
trait ReglasDelDocumento
{
    /**
     * El tipo sale del catálogo, no de una lista escrita aquí.
     *
     * Con una salvedad: si el catálogo de ese país está vacío se admiten los
     * tres de siempre. El desplegable ya se caía a ellos —está en
     * `PersonController::docTypeOptions()`— pero la validación no, así que en
     * una base sin sembrar la pantalla ofrecía «DNI», el servidor lo rechazaba
     * por no existir en el catálogo y no había forma de dar de alta a nadie.
     *
     * @return array<int, mixed>
     */
    protected function reglasDelTipo(): array
    {
        return ['required', 'string', 'max:20', function ($attribute, $value, $fail) {
            // El tipo que la persona YA tiene siempre pasa. El catálogo manda
            // sobre lo que se elige de nuevo, no sobre lo que está guardado:
            // dar de baja «PASAPORTE» del catálogo no puede dejar sin poder
            // corregirle el apellido a quien lleva uno.
            $person = $this->route('person');
            if ($person instanceof Person && (string) $value === (string) $person->doc_type) {
                return;
            }

            $delPais = DocumentType::query()
                ->where('country_id', $this->paisDelDocumento())
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->pluck('code');

            $admitidos = $delPais->isEmpty()
                ? \App\Http\Controllers\BusinessManagement\PersonController::docTypesPorDefecto()
                : $delPais->all();

            if (! in_array((string) $value, $admitidos, true)) {
                $fail(__('people.doc_type_invalid', ['types' => implode(', ', $admitidos)]));
            }
        }];
    }

    /**
     * El número: obligatorio, único por país + workspace y con la longitud que
     * declare su tipo.
     *
     * La longitud es **ayuda al dar de alta y nada más**: el buscador de la
     * cuadrilla va por coincidencia exacta, porque en la base hay dos peruanos
     * con DNI de 7 caracteres y una regla más estricta que la realidad los
     * dejaría fuera del sistema para siempre.
     *
     * @return array<int, mixed>
     */
    protected function reglasDelNumero(?int $personId = null): array
    {
        return [
            'required', 'string', 'max:255',
            function ($attribute, $value, $fail) use ($personId) {
                $existe = DB::table('people')
                    ->whereNull('deleted_at')
                    ->where('tenant_id', $this->user()?->tenant_id)
                    ->where('country_id', $this->paisDelDocumento())
                    ->where('doc_type', $this->input('doc_type'))
                    ->when($personId, fn ($q) => $q->where('id', '!=', $personId))
                    ->where('num_doc', trim((string) $value))
                    ->exists();

                if ($existe) {
                    $fail(__('people.num_doc_unique'));
                }
            },
            function ($attribute, $value, $fail) {
                $tipo = DocumentType::where('country_id', $this->paisDelDocumento())
                    ->where('code', $this->input('doc_type'))
                    ->first();

                if (! $tipo) {
                    return;
                }

                $numero = trim((string) $value);
                $largo = mb_strlen($numero);

                if ($tipo->min_length && $largo < $tipo->min_length) {
                    $fail(__('people.num_doc_too_short', ['type' => $tipo->code, 'min' => $tipo->min_length]));
                } elseif ($tipo->max_length && $largo > $tipo->max_length) {
                    $fail(__('people.num_doc_too_long', ['type' => $tipo->code, 'max' => $tipo->max_length]));
                } elseif (! $tipo->admiteElNumero($numero)) {
                    // El largo puede cuadrar y el contenido no: «1111111A» son
                    // ocho caracteres y no es un DNI.
                    $fail($tipo->porQueNoAdmite());
                }
            },
        ];
    }

    /** El país que manda: el del formulario y, si no viene, el de la persona. */
    protected function paisDelDocumento(): ?int
    {
        if ($this->filled('country_id')) {
            return (int) $this->input('country_id');
        }

        $person = $this->route('person');

        return is_object($person) ? $person->country_id : $this->user()?->country_id;
    }

    /**
     * Quien no puede ver el documento tampoco lo reescribe.
     *
     * El formulario de edición recibía el número ya enmascarado en un campo
     * editable y obligatorio, así que guardar cualquier cambio de la persona
     * escribía `******36` encima del DNI real: mismo largo, único, la
     * validación lo daba por bueno y nadie veía un error. La pantalla ahora
     * bloquea el campo; esto es lo que lo garantiza cuando la petición no viene
     * de la pantalla.
     *
     * Se conserva el trío entero —país, tipo y número—, que es justo la clave
     * única de la persona: cambiar el país de un documento que no se puede leer
     * es la misma operación por otra puerta.
     */
    protected function conservarDocumentoSiEstaTapado(): void
    {
        $person = $this->route('person');

        if (! $person instanceof Person || PrivateInfo::visibleFor($this->user())) {
            return;
        }

        $this->merge([
            'country_id' => $person->country_id,
            'doc_type'   => $person->doc_type,
            'num_doc'    => $person->num_doc,
        ]);
    }
}
