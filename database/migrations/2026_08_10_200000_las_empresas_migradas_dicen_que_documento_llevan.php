<?php

use App\Models\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las empresas que llegaron del sistema anterior recuperan su «RUC».
 *
 * En el listado se veia «20522756441» a secas mientras la unica empresa dada de
 * alta desde el formulario ponia «RUC 20522756441». Todas son peruanas y todas
 * llevan RUC: la diferencia no estaba en el dato, estaba en quien lo escribio.
 *
 * `companies.doc_type` tenia `default 'RUC'`, y el migrador nunca escribia la
 * columna porque no le hacia falta. Al quitar ese valor por defecto —una
 * contratista chilena se guardaba en silencio con un documento peruano, que era
 * un fallo de verdad— el migrador se quedo sin la muleta y nadie se dio cuenta:
 * lo ya migrado siguio igual y lo nuevo entro en blanco.
 *
 * Se rellena desde el catalogo, con el documento de empresa del pais de cada
 * una, no con un «RUC» escrito aqui: la fila de al lado puede ser de Chile.
 *
 * Y solo si el numero encaja con ese tipo. Un numero que no cumple la regla del
 * RUC no es un RUC, y ponerle la etiqueta seria afirmar algo que no consta —
 * esas se quedan como estan, a la vista, para que alguien las mire.
 */
return new class extends Migration
{
    public function up(): void
    {
        // En una base recien creada esto corre antes de los seeders y no hay
        // catalogo todavia: no hay nada que arreglar y no hay nada que hacer.
        if (DocumentType::query()->where('scope', DocumentType::EMPRESA)->doesntExist()) {
            return;
        }

        $sinTipo = DB::table('companies')->whereNull('doc_type')->get(['id', 'country_id', 'num_doc']);

        if ($sinTipo->isEmpty()) {
            return;
        }

        $porPais = [];

        foreach ($sinTipo->groupBy('country_id') as $paisId => $empresas) {
            $codigo = DocumentType::deLaEmpresaDe((int) $paisId);

            if (! $codigo) {
                continue;
            }

            $tipo = DocumentType::where('country_id', $paisId)
                ->where('scope', DocumentType::EMPRESA)
                ->where('code', $codigo)
                ->first();

            $encajan = $empresas
                ->filter(fn ($e) => $this->encaja($tipo, (string) $e->num_doc))
                ->pluck('id');

            if ($encajan->isEmpty()) {
                continue;
            }

            DB::table('companies')->whereIn('id', $encajan)->update(['doc_type' => $codigo]);
            $porPais[$codigo] = $encajan->count();
        }

        foreach ($porPais as $codigo => $n) {
            echo "  {$n} empresa(s) pasan a llevar {$codigo}." . PHP_EOL;
        }
    }

    /** El numero cumple la regla del tipo: largo y caracteres. */
    private function encaja(?DocumentType $tipo, string $numero): bool
    {
        if (! $tipo) {
            return false;
        }

        $largo = mb_strlen(trim($numero));

        return ! ($tipo->min_length && $largo < $tipo->min_length)
            && ! ($tipo->max_length && $largo > $tipo->max_length)
            && $tipo->admiteElNumero(trim($numero));
    }

    /**
     * No se vuelve atras.
     *
     * Dejar la columna en blanco otra vez seria devolver el fallo, y no hay
     * forma de distinguir cual estaba vacia antes de esto: el tipo es el que le
     * corresponde a la empresa por su pais, no un apaño de esta migracion.
     */
    public function down(): void
    {
    }
};
