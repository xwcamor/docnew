<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las respuestas migradas usaban claves que la pantalla no lee.
 *
 * `LegacyFormMapper` escribia las filas de EPP, IHM y PTF en castellano
 * —`respuesta`, `pregunta`, `herramienta`— y los campos compuestos del motor
 * leen `answer`, `question` y `tool`. Las 14 435 entregas de cinco años se
 * abrian **en blanco**: el nombre del item si cuadraba, porque esa clave se
 * llama igual en los dos sitios, y ninguna respuesta salia marcada.
 *
 * No salto antes porque el PDF aplana el JSON tal cual, sin buscar claves
 * concretas: el documento impreso salia perfecto. Y no era cosmetico — abrir un
 * EPP migrado y pulsar Guardar sobrescribia con nulos las respuestas reales,
 * porque la pantalla creia que no habia ninguna.
 *
 * Aqui se renombran en sitio. Se hace en SQL y no cargando modelos porque son
 * decenas de miles de filas y lo unico que cambia es el nombre de la clave: el
 * valor no se toca, asi que no hay nada que interpretar en PHP.
 */
return new class extends Migration
{
    /** clave vieja => clave nueva, dentro de cada objeto de la lista `items`. */
    private array $enItems = ['respuesta' => 'answer'];

    /** Lo mismo, en la raiz de la fila. */
    private array $enLaFila = ['herramienta' => 'tool', 'pregunta' => 'question', 'respuesta' => 'answer'];

    public function up(): void
    {
        $this->renombrar($this->enItems, $this->enLaFila);
        $this->ponerElNivelDeRiesgo();
    }

    /** Vuelta atras: el mismo renombrado del reves. */
    public function down(): void
    {
        $this->renombrar(
            array_flip($this->enItems),
            array_flip($this->enLaFila),
        );
    }

    /**
     * La banda de riesgo, que la migracion nunca escribio.
     *
     * Las filas de AST y PTF llegaron con `valor_riesgo` —el numero del 1 al 25
     * de la matriz de la v1— pero sin `nivel`, que es lo que pinta la banda de
     * color y lo que cuenta FormFindingsService. Con 3 657 AST y 3 662 PTF
     * migrados eso significaba cero observaciones en los dos formatos y ninguna
     * fila coloreada en pantalla.
     *
     * Las bandas salen de la plantilla de cada campo (`config.levels`), no de un
     * 15 escrito aqui: un formato de otro pais puede tener otra matriz.
     */
    private function ponerElNivelDeRiesgo(): void
    {
        $campos = DB::table('form_fields')->where('field_type', 'risk_matrix')->get(['id', 'config']);

        foreach ($campos as $campo) {
            $config = json_decode($campo->config ?? '{}', true);
            $bandas = $config['levels'] ?? $config['niveles'] ?? null;

            if (! is_array($bandas) || $bandas === []) {
                continue;
            }

            DB::table('form_answers')
                ->where('form_field_id', $campo->id)
                ->whereNotNull('value_json')
                ->orderBy('id')
                ->chunkById(1000, function ($filas) use ($bandas) {
                    foreach ($filas as $fila) {
                        $valor = json_decode($fila->value_json, true);

                        if (! is_array($valor) || array_key_exists('nivel', $valor)) {
                            continue;
                        }

                        $valor['nivel'] = $this->bandaDe($valor['valor_riesgo'] ?? null, $bandas);

                        DB::table('form_answers')->where('id', $fila->id)
                            ->update(['value_json' => json_encode($valor, JSON_UNESCAPED_UNICODE)]);
                    }
                });
        }
    }

    /** En que banda cae un valor. Sin valor no hay banda: la fila esta a medias. */
    private function bandaDe(mixed $valor, array $bandas): ?string
    {
        if (! is_numeric($valor)) {
            return null;
        }

        foreach ($bandas as $banda) {
            if ((int) $valor <= (int) ($banda['hasta'] ?? 0)) {
                return $banda['clave'] ?? null;
            }
        }

        return end($bandas)['clave'] ?? null;
    }

    private function renombrar(array $enItems, array $enLaFila): void
    {
        DB::table('form_answers')
            ->whereNotNull('value_json')
            ->orderBy('id')
            ->chunkById(1000, function ($filas) use ($enItems, $enLaFila) {
                foreach ($filas as $fila) {
                    $valor = json_decode($fila->value_json, true);

                    if (! is_array($valor)) {
                        continue;
                    }

                    $nuevo = $this->traducir($valor, $enItems, $enLaFila);

                    if ($nuevo === $valor) {
                        continue;
                    }

                    DB::table('form_answers')->where('id', $fila->id)
                        ->update(['value_json' => json_encode($nuevo, JSON_UNESCAPED_UNICODE)]);
                }
            });
    }

    /**
     * Una respuesta puede ser una fila (EPP, IHM) o una lista de filas (PTF, que
     * guarda las 17 preguntas juntas). Se tratan las dos.
     */
    private function traducir(array $valor, array $enItems, array $enLaFila): array
    {
        // Lista de filas: PTF guarda un array en la raiz.
        if (array_is_list($valor)) {
            return array_map(
                fn ($f) => is_array($f) ? $this->cambiarClaves($f, $enLaFila) : $f,
                $valor,
            );
        }

        $valor = $this->cambiarClaves($valor, $enLaFila);

        if (isset($valor['items']) && is_array($valor['items'])) {
            $valor['items'] = array_map(
                fn ($i) => is_array($i) ? $this->cambiarClaves($i, $enItems) : $i,
                $valor['items'],
            );
        }

        return $valor;
    }

    /** Renombra conservando el orden: en el PDF las claves son las columnas. */
    private function cambiarClaves(array $fila, array $mapa): array
    {
        $salida = [];

        foreach ($fila as $clave => $v) {
            $salida[$mapa[$clave] ?? $clave] = $v;
        }

        return $salida;
    }
};
