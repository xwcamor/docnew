<?php

namespace App\Services\FieldWork;

use App\Models\FormAnswer;
use App\Models\FormSubmission;

/**
 * Cuantas cosas salieron mal en un formato.
 *
 * En el sistema anterior cada uno de los cuatro formatos calculaba esto solo, en
 * un `set_completed` que corria despues de cada guardado, y el numero acababa en
 * `observations`. No era decorativo: era lo que el supervisor leia en la ficha
 * del plan para saber si la jornada habia salido limpia.
 *
 * Las dos reglas de alli, tal cual:
 *
 *   - **AST y PTF** (`f1_document`, `f2_document`): cuenta los peligros con
 *     `risk_value <= 15`. La matriz de la v1 es un ranking del 1 al 25 donde el 1
 *     es lo peor, asi que `<= 15` es «alto o medio». Aqui no se compara contra 15
 *     a pelo —eso ataria el motor a la matriz peruana— sino contra la ultima
 *     banda que declare la plantilla, que es la de menor riesgo.
 *
 *   - **EPP y IHM** (`f3_document`, `f4_document`): cuenta las respuestas
 *     negativas, una por trabajador-item o herramienta-item.
 *
 * Lo que la v1 hacia y aqui NO se copia, a proposito:
 *
 *   1. Alli el EPP y el IHM contaban las respuestas con valor `0`, que en el
 *      formulario es **«No aplica»**, y dejaban fuera el `2`, que es **«No
 *      conforme»**. Un item marcado «no aplica» sumaba observacion y uno marcado
 *      «no conforme» no. Es un error de la v1, no una regla: se cuenta lo que no
 *      esta conforme. Queda anotado en docs/COMPARACION-V1.md.
 *   2. El banco de preguntas del PTF no se cuenta, porque alli tampoco se
 *      contaba: `F2Document#set_completed` solo mira la matriz de riesgo.
 *
 * Lo que se cuenta NO bloquea nada. En la v1 tampoco: `lock_plan_if_all_-
 * conditions_met` solo exige `date_end` y las aprobaciones obligatorias
 * firmadas, asi que un plan con un EPP observado se cerraba igual. Un arnes en
 * mal estado es justo lo que hay que poder registrar y cerrar el mismo dia, con
 * su medida de correccion al lado.
 */
class FormFindingsService
{
    /** Respuesta negativa: la que cuenta como no conformidad. */
    public const MALA = 'bad';

    /** «No aplica»: ni buena ni mala, no cuenta. */
    public const NO_APLICA = 'na';

    /**
     * Recalcula y guarda. Se llama despues de cada guardado, como el
     * `after_save :set_completed` de alla.
     *
     * `updateQuietly` porque esto es una consecuencia de lo que el usuario
     * acaba de escribir, no una edicion suya: no tiene que ensuciar la
     * auditoria con una fila por cada tecla.
     */
    public function recalcular(FormSubmission $entrega): int
    {
        $total = $this->contar($entrega);

        // `forceFill` porque la columna no esta en $fillable: se calcula, no se
        // rellena desde una peticion.
        if ($entrega->nonconformities !== $total) {
            $entrega->forceFill(['nonconformities' => $total])->saveQuietly();
        }

        return $total;
    }

    /** Cuantas no conformidades tiene la entrega ahora mismo. */
    public function contar(FormSubmission $entrega): int
    {
        $campos = $entrega->formTemplate?->fields()->get()->keyBy('id') ?? collect();

        return $entrega->answers()->get()->sum(function (FormAnswer $r) use ($campos) {
            $campo = $campos[$r->form_field_id] ?? null;

            return $campo === null ? 0 : $this->deLaFila($campo->field_type, $campo->config ?? [], $r->value_json);
        });
    }

    /** Cuantas no conformidades hay en una fila, segun el tipo de campo. */
    protected function deLaFila(string $tipo, array $config, mixed $valor): int
    {
        if (! is_array($valor)) {
            return 0;
        }

        return match ($tipo) {
            'risk_matrix' => $this->esRiesgoNoTolerable($config, $valor) ? 1 : 0,
            'person_checklist', 'tool_checklist' => $this->respuestasMalas($valor['items'] ?? []),
            default => 0,
        };
    }

    /**
     * Un peligro que no esta en la banda de menor riesgo.
     *
     * Las bandas vienen de la plantilla (`config.levels`), ordenadas de peor a
     * mejor: la ultima es la buena. Si la plantilla no las declara —un formato
     * nuevo hecho a mano— se cae a la banda `bajo`, que es como se llama en las
     * cuatro plantillas que trajo la migracion.
     */
    protected function esRiesgoNoTolerable(array $config, array $fila): bool
    {
        $nivel = $fila['nivel'] ?? null;

        // Sin nivel no hay peligro evaluado: una fila a medias no es una no
        // conformidad, es un campo sin llenar, y de eso se ocupa `faltantes()`.
        if (! is_string($nivel) || $nivel === '') {
            return false;
        }

        $bandas = $config['levels'] ?? $config['niveles'] ?? null;

        $tolerable = is_array($bandas) && $bandas !== []
            ? (end($bandas)['clave'] ?? 'bajo')
            : 'bajo';

        return $nivel !== $tolerable;
    }

    /** @param array<int, mixed> $items */
    protected function respuestasMalas(mixed $items): int
    {
        if (! is_array($items)) {
            return 0;
        }

        return count(array_filter(
            $items,
            fn ($i) => is_array($i) && $this->tono($i['answer'] ?? null) === self::MALA,
        ));
    }

    /**
     * Que clase de respuesta es, deducida del texto.
     *
     * Cada formato trae su propio catalogo —EPP usa Conforme / No conforme / No
     * aplica, IHM No cumple / Cumple / No aplica— y en el IHM el orden ni
     * siquiera empieza por la buena, asi que la posicion en la lista no dice
     * nada. Por eso se lee el texto.
     *
     * **Es la misma regla que `tono()` de resources/js/Components/FormFields/
     * respuestas.js**, que pinta la casilla. Si una de las dos cambia y la otra
     * no, la pantalla pinta rojo y el contador dice cero. La prueba
     * `test_el_servidor_y_la_pantalla_coinciden_en_que_es_una_mala_respuesta`
     * las compara.
     */
    public function tono(mixed $respuesta): string
    {
        $texto = $this->normalizar($respuesta);

        if ($texto === '') {
            return self::NO_APLICA;
        }

        // «No aplica» se comprueba ANTES que el negativo generico: si no, la
        // regla de «empieza por no» se lo come y lo cuenta como observacion,
        // que es exactamente el error que tenia la v1.
        if (str_starts_with($texto, 'no aplica') || $texto === 'n/a' || $texto === 'na') {
            return self::NO_APLICA;
        }

        return str_starts_with($texto, 'no') ? self::MALA : 'ok';
    }

    /** Minusculas y sin tildes, para que 'Sí' y 'Si' se comparen igual. */
    protected function normalizar(mixed $texto): string
    {
        $limpio = mb_strtolower(trim((string) $texto));

        return preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($limpio, \Normalizer::FORM_D) ?: $limpio) ?? $limpio;
    }
}
