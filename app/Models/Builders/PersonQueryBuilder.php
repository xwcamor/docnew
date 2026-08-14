<?php

namespace App\Models\Builders;

use App\Support\DocumentoBuscable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Buscar a una persona por su documento cuando el documento esta cifrado.
 *
 * POR QUE ESTO EXISTE Y NO ES UNA COMODIDAD
 * -----------------------------------------
 * `people.num_doc` esta cifrado, asi que `where('num_doc', '47019236')` compara
 * un DNI contra un sobre cifrado: **no encuentra nada, y no falla**. Devuelve
 * cero filas con cara de resultado legitimo. En este producto eso significa que
 * el buscador de la puerta dice «ese documento no esta registrado» delante de
 * una persona que si lo esta, y que la comprobacion de duplicados da via libre
 * para dar de alta al mismo trabajador por segunda vez.
 *
 * Hay veinte sitios en el codigo que consultan por documento —el listado, la
 * papelera, el buscador global, la validacion de unicidad, la importacion, el
 * migrador— y todos son de este tipo: si se rompen, se rompen en silencio.
 * Dejar la traduccion a `num_doc_hash` en manos de que cada uno se acuerde es
 * dejar una trampa puesta para el proximo que escriba una consulta.
 *
 * Por eso se traduce aqui, en el builder del modelo: `where('num_doc', ...)`
 * sigue significando lo que parece que significa, y por debajo va al indice
 * ciego. Lo que NO se puede traducir —un `LIKE '%parcial%'` sobre el documento—
 * lanza una excepcion en vez de devolver una lista vacia, porque un fallo que
 * se ve es infinitamente mejor que una busqueda que miente.
 *
 * Ojo con el limite: esto solo cubre Eloquent. Un `DB::table('people')
 * ->where('num_doc', ...)` pasa de largo y hay que escribirlo contra
 * `num_doc_hash` a mano.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class PersonQueryBuilder extends Builder
{
    /** Los operadores que un indice ciego puede responder. Y no hay mas. */
    private const OPERADORES = ['=', '!=', '<>'];

    /**
     * @param  \Closure|string|array<mixed>|\Illuminate\Contracts\Database\Query\Expression  $column
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // Forma de array (`where(['num_doc' => ..., 'doc_type' => ...])`, que
        // es la que usan `firstOrCreate` y `updateOrCreate` por dentro): se
        // reparte en llamadas sueltas para que el documento pase por aqui. Sin
        // esto la forma de array se escaparia, porque Eloquent la delega
        // entera al query builder de abajo.
        if (is_array($column) && $this->llevaElDocumento($column)) {
            return $this->where(function (self $q) use ($column) {
                foreach ($column as $clave => $valor) {
                    is_int($clave) ? $q->where(...$valor) : $q->where($clave, '=', $valor);
                }
            }, null, null, $boolean);
        }

        if (! is_string($column) || ! $this->esElDocumento($column)) {
            return parent::where($column, $operator, $value, $boolean);
        }

        [$value, $operator] = $this->getQuery()->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2,
        );

        return $this->porDocumento((string) $operator, $value, $boolean);
    }

    /**
     * @param  string  $column
     * @param  mixed  $values
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if (is_string($column) && $this->esElDocumento($column) && is_iterable($values)) {
            $hashes = [];

            foreach ($values as $valor) {
                $hash = DocumentoBuscable::hash($valor === null ? null : (string) $valor);

                if ($hash !== null) {
                    $hashes[] = $hash;
                }
            }

            $column = $this->getModel()->qualifyColumn('num_doc_hash');
            $values = $hashes;
        }

        $this->getQuery()->whereIn($column, $values, $boolean, $not);

        return $this;
    }

    // Las tres variantes de `whereIn` pasan por la de arriba en vez de bajar al
    // query builder por su cuenta. Es lo unico que evita que `whereNotIn(
    // 'num_doc', ...)` se escape sin traducir y excluya a nadie — que en una
    // consulta de exclusion significa que la gente que habia que dejar fuera
    // entra.

    /** @param string $column @param mixed $values */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /** @param string $column @param mixed $values */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /** @param string $column @param mixed $values */
    public function orWhereNotIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or', true);
    }

    /**
     * La traduccion en si: el documento tecleado se convierte en su hash.
     *
     * El `null` se deja pasar a la columna cifrada y no al indice: preguntar
     * «¿quien no tiene documento?» es una pregunta sobre el hueco, no sobre el
     * contenido, y las dos columnas estan vacias a la vez.
     */
    private function porDocumento(string $operador, mixed $valor, string $boolean): static
    {
        $operador = trim(mb_strtolower($operador));

        if (! in_array($operador, self::OPERADORES, true)) {
            throw new \LogicException(sprintf(
                'El documento esta cifrado y su indice ciego solo responde a igualdad: «%s» no se puede resolver. '
                . 'Una busqueda parcial sobre `num_doc` no es posible por diseño (ver App\Support\DocumentoBuscable).',
                $operador,
            ));
        }

        $columna = $this->getModel()->qualifyColumn('num_doc_hash');

        if ($valor === null) {
            return $operador === '='
                ? parent::whereNull($columna, $boolean)
                : parent::whereNotNull($columna, $boolean);
        }

        return parent::where(
            $columna,
            $operador === '=' ? '=' : '!=',
            DocumentoBuscable::hash((string) $valor),
            $boolean,
        );
    }

    private function esElDocumento(string $columna): bool
    {
        return $columna === 'num_doc'
            || $columna === $this->getModel()->qualifyColumn('num_doc');
    }

    /** @param array<mixed> $condiciones */
    private function llevaElDocumento(array $condiciones): bool
    {
        foreach ($condiciones as $clave => $valor) {
            if (is_string($clave) && $this->esElDocumento($clave)) {
                return true;
            }

            if (is_array($valor) && isset($valor[0]) && is_string($valor[0]) && $this->esElDocumento($valor[0])) {
                return true;
            }
        }

        return false;
    }
}
