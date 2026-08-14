<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DocumentType;

/**
 * El catalogo de tipos de documento agrupado por pais, para un formulario.
 *
 * El formulario elige pais y tipo en la misma pantalla, asi que la lista de
 * tipos tiene que seguir al pais elegido y no al del usuario. El catalogo es de
 * unas pocas filas por pais: cabe entero en la pagina y evita un viaje al
 * servidor por cada cambio de pais.
 *
 * Van tambien `min`, `max` y `allowed_chars`: el campo del numero tenia un tope
 * fijo de 20 caracteres para todos los tipos, asi que se podia teclear un
 * celular de nueve digitos en un DNI y no enterarse hasta darle a Guardar. Con
 * la forma del tipo delante, el campo corta al llegar al maximo y limpia lo que
 * no admite.
 *
 * Lo comparten personas y empresas: es el mismo catalogo con `scope` distinto —
 * DNI, carne de extranjeria, PTP y pasaporte para la gente; RUC, RUT, CUIT o
 * NIT para la empresa, segun el pais.
 */
trait TiposDeDocumento
{
    /**
     * @return array<int, array<int, array{value: string, label: string, min: int|null, max: int|null, allowed_chars: string|null, national: bool}>>
     */
    protected function docTypesByCountry(string $scope = DocumentType::PERSONA): array
    {
        return DocumentType::query()
            ->active()
            ->where('scope', $scope)
            ->orderBy('code')
            ->get(['country_id', 'code', 'name', 'min_length', 'max_length', 'allowed_chars', 'for_foreigners'])
            ->groupBy('country_id')
            ->map(fn ($tipos) => $tipos
                ->map(fn ($t) => [
                    'value'         => $t->code,
                    'label'         => $t->label,
                    'min'           => $t->min_length,
                    'max'           => $t->max_length,
                    'allowed_chars' => $t->allowed_chars,
                    // El documento nacional del pais (DNI en Peru, RUN en
                    // Chile): es el que se propone por defecto al dar de alta,
                    // porque es el que lleva casi todo el mundo. Sin esta marca
                    // el formulario proponia «DNI» por su nombre, que solo
                    // funciona en los paises donde se llama asi.
                    'national'      => ! $t->for_foreigners,
                ])
                ->values()
                ->all())
            ->all();
    }
}
