<?php

use App\Models\Country;
use App\Models\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Una empresa tambien lleva documento, y tampoco es el mismo en todas partes.
 *
 * `companies.num_doc` estaba suelto: un texto sin tipo, sin longitud y sin
 * decir de que documento hablamos. En la interfaz salia como «codigo», que no
 * es nada, y en los mensajes del importador ya habia aparecido el apaño
 * «El codigo (RUC/CUIT/RFC/NIT)» — la propia frase reconoce que el dato cambia
 * de pais en pais y que no habia donde guardarlo.
 *
 * Es exactamente el problema que ya se resolvio para las personas, asi que se
 * resuelve con la misma tabla en vez de con otra nueva: `document_types` gana
 * un `scope` que dice a quien pertenece el tipo. Peru siembra RUC para la
 * empresa y sigue con DNI, CE, PTP y pasaporte para la persona. Con eso el
 * formulario de empresa hereda gratis lo que ya hace el de personas: contar
 * los digitos y avisar de que un RUC lleva once.
 *
 * A los tipos que ya existen se les pone `person`: eran todos de persona.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('scope', 10)->default(DocumentType::PERSONA)->after('country_id')
                ->comment('person | company: a quien pertenece el tipo');
        });

        DB::table('document_types')->update(['scope' => DocumentType::PERSONA]);

        Schema::table('companies', function (Blueprint $table) {
            $table->string('doc_type', 20)->default('RUC')->after('country_id');
        });

        $peru = Country::where('iso_code', 'PE')->first();

        if ($peru) {
            // Once digitos exactos y empieza por 10 (persona natural con
            // negocio) o 20 (persona juridica), pero la regla de los dos
            // primeros no se mete aqui: hay RUC de 15 y 17 en circulacion para
            // otros casos y una regla mas dura que la realidad es la que deja
            // fuera a una empresa de verdad.
            DocumentType::withoutGlobalScopes()->firstOrCreate(
                ['country_id' => $peru->id, 'scope' => DocumentType::EMPRESA, 'code' => 'RUC'],
                [
                    'slug' => Str::random(22),
                    'name' => 'Registro Único de Contribuyentes',
                    'min_length' => 11, 'max_length' => 11,
                    'allowed_chars' => DocumentType::SOLO_CIFRAS,
                    'is_active' => true, 'created_by' => 1,
                ],
            );

            DB::table('companies')->where('country_id', $peru->id)->update(['doc_type' => 'RUC']);
        }
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('doc_type'));
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('scope'));
    }
};
