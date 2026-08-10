<?php

namespace App\Http\Requests\BusinessManagement\Company;

use App\Models\Company;
use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;

/**
 * Las reglas del documento de la empresa, que el alta y la edicion comparten.
 *
 * Antes solo habia un `min:3|max:20` sobre un texto sin tipo, con este comentario
 * repetido en los dos FormRequest:
 *
 *   «No pongo digits:11 porque eso es el RUC peruano y el modulo es multi-pais:
 *    cada uno tiene su documento fiscal.»
 *
 * La observacion era correcta y la conclusion no: lo que faltaba no era
 * renunciar a la regla, era saber DE QUE documento se habla. Con
 * `document_types` en scope `company` el pais dice si es un RUC de once cifras,
 * un RUT chileno o un CUIT argentino, y la regla sale del catalogo igual que la
 * del DNI de una persona.
 */
trait ReglasDelDocumento
{
    /**
     * El tipo sale del catalogo del pais.
     *
     * Es `nullable` a proposito. Si el pais no tiene ningun tipo de empresa
     * sembrado no hay nada que elegir, y exigir un dato que el catalogo no
     * puede ofrecer deja la pantalla sin poder dar de alta a nadie — que es
     * exactamente el fallo que se arreglo en personas con la lista por defecto.
     * Cuando no viene, manda el valor por defecto de la columna.
     *
     * @return array<int, mixed>
     */
    protected function reglasDelTipoDeDocumento(): array
    {
        return ['nullable', 'string', 'max:20', function ($attribute, $value, $fail) {
            // El tipo que la empresa YA tiene siempre pasa: dar de baja una
            // sigla del catalogo no puede dejar sin poder corregirle el nombre
            // a quien la lleva.
            $company = $this->route('company');
            if ($company instanceof Company && (string) $value === (string) $company->doc_type) {
                return;
            }

            $admitidos = $this->tiposDelPais();

            if ($admitidos !== [] && ! in_array((string) $value, $admitidos, true)) {
                $fail(__('companies.doc_type_invalid', ['types' => implode(', ', $admitidos)]));
            }
        }];
    }

    /**
     * El numero: obligatorio, unico por pais + workspace y con la forma que
     * declare su tipo.
     *
     * @return array<int, mixed>
     */
    protected function reglasDelNumeroDeDocumento(?int $companyId = null): array
    {
        return [
            'required', 'string', 'min:3', 'max:20',
            function ($attribute, $value, $fail) use ($companyId) {
                $existe = DB::table('companies')
                    ->whereNull('deleted_at')
                    ->where('tenant_id', $this->user()?->tenant_id)
                    ->where('country_id', $this->paisDelDocumento())
                    ->when($companyId, fn ($q) => $q->where('id', '!=', $companyId))
                    ->where('num_doc', trim((string) $value))
                    ->exists();

                if ($existe) {
                    $fail(__('companies.num_doc_unique'));
                }
            },
            function ($attribute, $value, $fail) {
                $tipo = DocumentType::query()
                    ->where('country_id', $this->paisDelDocumento())
                    ->where('scope', DocumentType::EMPRESA)
                    ->where('code', $this->input('doc_type'))
                    ->first();

                if (! $tipo) {
                    return;
                }

                $numero = trim((string) $value);
                $largo  = mb_strlen($numero);

                if ($tipo->min_length && $largo < $tipo->min_length) {
                    $fail(__('companies.num_doc_too_short', ['type' => $tipo->code, 'min' => $tipo->min_length]));
                } elseif ($tipo->max_length && $largo > $tipo->max_length) {
                    $fail(__('companies.num_doc_too_long', ['type' => $tipo->code, 'max' => $tipo->max_length]));
                } elseif (! $tipo->admiteElNumero($numero)) {
                    $fail($tipo->porQueNoAdmite());
                }
            },
        ];
    }

    /**
     * Si el formulario no manda el tipo, se deduce.
     *
     * La pantalla siempre lo manda, pero el resto de puertas no: la migracion
     * de la v1, el importador y las llamadas desde fuera solo traen el numero.
     * En un pais con un unico documento de empresa —Peru y su RUC— exigirlo
     * seria pedir un dato que solo puede tener un valor. Se toma el que ya
     * tiene la empresa y, si es nueva, el primero del catalogo de su pais.
     */
    protected function deducirElTipoSiNoViene(): void
    {
        if ($this->filled('doc_type')) {
            return;
        }

        $company = $this->route('company');

        if ($company instanceof Company && $company->doc_type) {
            $this->merge(['doc_type' => $company->doc_type]);

            return;
        }

        $tipos = $this->tiposDelPais();

        if ($tipos !== []) {
            $this->merge(['doc_type' => $tipos[0]]);

            return;
        }

        // Ni catalogo ni empresa: se quita del payload para que mande el valor
        // por defecto de la columna en vez de escribirle una cadena vacia.
        $this->replace($this->except('doc_type'));
    }

    /** Las siglas vigentes del pais del formulario. */
    protected function tiposDelPais(): array
    {
        return DocumentType::query()
            ->where('country_id', $this->paisDelDocumento())
            ->where('scope', DocumentType::EMPRESA)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->pluck('code')
            ->all();
    }

    /** El pais que manda: el del formulario y, si no viene, el de la empresa. */
    protected function paisDelDocumento(): ?int
    {
        if ($this->filled('country_id')) {
            return (int) $this->input('country_id');
        }

        $company = $this->route('company');

        return is_object($company) ? $company->country_id : $this->user()?->country_id;
    }
}
