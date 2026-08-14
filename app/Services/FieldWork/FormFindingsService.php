<?php

namespace App\Services\FieldWork;

use App\Models\FormAnswer;
use App\Models\FormSubmission;
use App\Support\BandasDeRiesgo;
use App\Support\Catalogo;

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
 * Aqui hubo un error MIO que conviene dejar escrito, porque casi se queda: este
 * comentario decia que la v1 contaba mal —que `answers.count(0)` sumaba los «no
 * aplica» y dejaba fuera los «no conforme»— y lo daba por un fallo de alla. No
 * lo era. El 0 de la v1 **es** «no conforme»: el radio con valor 0 pinta
 * `equis.png` (ver LegacyFormMapper), asi que `count(0)` contaba exactamente lo
 * que tenia que contar. Quien tenia el 0 y el 2 cambiados era el migrador de
 * aqui, y en vez de sospechar del propio codigo se escribio un parrafo
 * explicando por que el sistema viejo estaba equivocado.
 *
 * Cuando un sistema que lleva anios en produccion y el codigo nuevo no
 * coinciden, el que suele estar mal es el nuevo.
 *
 * Donde SI nos separamos de la v1, por decision del dueno del producto: el
 * banco de preguntas del PTF cuenta sus «No». Alla no se contaban —
 * `F2Document#set_completed` solo miraba la matriz de riesgo— y en la ficha del
 * plan quedaba un PTF con cuatro respuestas negativas dicho como «sin
 * observaciones», al lado de un EPP que si las enseñaba. Un «No» en el Pare y
 * Tome 5 es exactamente una observacion: alguien contesto que no a una
 * pregunta de seguridad antes de empezar.
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
            'person_checklist', 'tool_checklist' => $this->respuestasMalas($valor['items'] ?? [], $config),
            // El banco de preguntas guarda la lista entera en UNA respuesta, no
            // una fila por pregunta: aqui el valor ya es la lista.
            'question_bank' => $this->respuestasMalas($valor, $config),
            default => 0,
        };
    }

    /**
     * Un peligro que no esta en la banda tolerable.
     *
     * CUAL ES LA TOLERABLE LA DICE LA PLANTILLA, NO NOSOTROS. Antes se cogia la
     * ultima banda declarada y, si no habia ninguna, la palabra `bajo` escrita
     * aqui — que es el nombre que le puso la v1 y que no tiene por que usar la
     * siguiente empresa. Ahora se marca con `tolerable: true`, y la ultima sigue
     * siendo el valor por defecto porque las cuatro plantillas migradas van de
     * peor a mejor. Ver `BandasDeRiesgo`.
     *
     * SIN BANDAS DECLARADAS NO SE INVENTA NINGUNA. Un formato hecho a mano al
     * que le falten los niveles no tiene con que decidir si un peligro es
     * tolerable, y responder que no lo es —que es lo que hacia el `'bajo'`
     * cableado— convertia en observacion cada peligro evaluado del documento.
     */
    protected function esRiesgoNoTolerable(array $config, array $fila): bool
    {
        $bandas = BandasDeRiesgo::de($config);

        if ($bandas === []) {
            return false;
        }

        $nivel = $fila['nivel'] ?? null;

        // Si la fila no trae el nivel pero si el valor, se deduce. Las 3 657
        // matrices migradas estan asi: el migrador copiaba `risk_value` de la
        // v1 y no escribia la banda, con lo que salian «Sin evaluar» en
        // pantalla y sumaban cero observaciones — un AST con ocho peligros
        // altos se leia como una jornada limpia.
        if (! is_string($nivel) || $nivel === '') {
            $nivel = self::nivelDeRiesgo($fila['valor_riesgo'] ?? null, $bandas);
        }

        // Sin nivel Y sin valor no hay peligro evaluado: una fila a medias no
        // es una no conformidad, es un campo sin llenar, y de eso se ocupa
        // `faltantes()`.
        if (! is_string($nivel) || $nivel === '') {
            return false;
        }

        return $nivel !== BandasDeRiesgo::tolerable($bandas);
    }

    /**
     * Las bandas de la plantilla, normalizadas.
     *
     * Se conserva el nombre porque lo llaman el PDF y las pruebas; lo que hace
     * ahora es delegar en `BandasDeRiesgo`, que es quien sabe leer las dos
     * formas —la vieja de `hasta` acumulado y la nueva de rangos— y quien
     * resuelve rotulo y color.
     */
    public static function bandasDe(array $config, ?string $locale = null): array
    {
        return BandasDeRiesgo::de($config, $locale);
    }

    /**
     * En que banda cae un valor de la matriz.
     *
     * El nivel es un DERIVADO del valor y de las bandas de la plantilla, no un
     * dato por si mismo: `1-8 alto, 9-15 medio, 16-25 bajo` en la matriz de la
     * v1 (`Risk#level_name`). Por eso se puede reconstruir de una fila que solo
     * guarde el numero, que es como llegaron las migradas.
     *
     * UN VALOR FUERA DE TODAS LAS BANDAS NO CAE EN LA ULTIMA. Antes si, y con la
     * matriz de la v1 no se notaba porque las tres bandas cubren el 1 al 25
     * enteros. Con rangos escritos a mano puede quedar un hueco, y meter ahi
     * «riesgo bajo» seria decir que un peligro es tolerable sin que nadie lo
     * haya dicho: sale sin evaluar, que es lo que es.
     *
     * Es la misma regla que `nivelRiesgo()` de RiskMatrixField.vue, que la pinta.
     * La prueba `test_el_nivel_se_calcula_igual_en_el_servidor_y_en_la_pantalla`
     * compara las dos.
     *
     * @param  array<int, array>  $bandas  las de `bandasDe()`, ya normalizadas
     */
    public static function nivelDeRiesgo(mixed $valor, array $bandas): ?string
    {
        return BandasDeRiesgo::deValor($valor, $bandas)['clave'] ?? null;
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $config  el del campo, para leer los tonos declarados
     */
    protected function respuestasMalas(mixed $items, array $config = []): int
    {
        if (! is_array($items)) {
            return 0;
        }

        return count(array_filter(
            $items,
            fn ($i) => is_array($i) && $this->tono($i['answer'] ?? null, $config) === self::MALA,
        ));
    }

    /**
     * Que clase de respuesta es: la que diga el catalogo, y si no lo dice, la
     * que se deduzca del texto.
     *
     * LO PRIMERO ES PREGUNTAR AL CATALOGO
     * -----------------------------------
     * Una respuesta puede declarar su tono (`{"value": "Rechazado", "tone":
     * "bad"}`), y cuando lo declara **manda**. Es el unico camino que funciona
     * para un formato que no hable castellano de obra peruana.
     *
     * LA HEURISTICA, Y POR QUE SIGUE AQUI
     * -----------------------------------
     * Los cuatro formatos migrados y las 14 000 entregas guardan cadenas
     * sueltas: EPP usa Conforme / No conforme / No aplica, IHM No cumple /
     * Cumple / No aplica, y en el IHM el orden ni siquiera empieza por la buena,
     * asi que la posicion en la lista no dice nada. Para todo eso se lee el
     * texto, como se ha leido siempre.
     *
     * PERO LA HEURISTICA NO ES ESCALABLE Y HAY QUE DECIRLO. «Empieza por no» da
     * por conforme a «Rechazado», «Malo», «Deficiente» y «Fail», y por no
     * conformidad a «Normal». No es un respaldo aceptable para un formato nuevo:
     * es la compatibilidad con lo que ya estaba escrito. Un catalogo nuevo
     * declara sus tonos, y el editor los pide.
     *
     * **Es la misma regla que `tono()` de resources/js/Components/FormFields/
     * respuestas.js**, que pinta la casilla. Si una de las dos cambia y la otra
     * no, la pantalla pinta rojo y el contador dice cero. La prueba
     * `test_el_servidor_y_la_pantalla_coinciden_en_que_es_una_mala_respuesta`
     * las compara.
     *
     * @param  array<string, mixed>  $config  el del campo; sin el, solo heuristica
     */
    public function tono(mixed $respuesta, array $config = []): string
    {
        $declarado = Catalogo::tonoDeclarado($config['answers'] ?? null, $respuesta);

        if ($declarado !== null) {
            return $declarado;
        }

        return $this->tonoDeducido($respuesta);
    }

    /** La deduccion del texto, a solas. Es lo que se compara con la de la pantalla. */
    public function tonoDeducido(mixed $respuesta): string
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
        // Un array no es un texto. Puede llegar si alguien clasifica una
        // entrada cruda del catalogo en vez de su valor; se trata como vacio
        // —que clasifica «no aplica», el tono que no suma nada— en vez de
        // reventar con «Array to string» en mitad de un contador.
        if (! is_scalar($texto) && $texto !== null) {
            return '';
        }

        $limpio = mb_strtolower(trim((string) $texto));

        return preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($limpio, \Normalizer::FORM_D) ?: $limpio) ?? $limpio;
    }
}
