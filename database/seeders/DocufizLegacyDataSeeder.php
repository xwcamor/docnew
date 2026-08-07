<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Country;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\PersonCompanyLink;
use App\Models\PersonRole;
use App\Models\PersonSignature;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Carga los datos que ya se migraron del sistema anterior.
 *
 *   php artisan db:seed --class=DocufizLegacyDataSeeder
 *
 * Lee `database/seeders/data/legacy-docufiz.json`. Ese archivo NO esta en el
 * repositorio y no debe estarlo: contiene nombres y documentos de personas
 * reales, y este repositorio es publico. Se entrega aparte y se coloca a mano.
 *
 * Es idempotente: se puede correr las veces que haga falta. Las empresas se
 * reconocen por su RUC y las personas por su documento, asi que volver a
 * ejecutarlo actualiza en vez de duplicar.
 */
class DocufizLegacyDataSeeder extends Seeder
{
    public function run(): void
    {
        $ruta = database_path('seeders/data/legacy-docufiz.json');

        if (! file_exists($ruta)) {
            $this->command->warn('No se encontro database/seeders/data/legacy-docufiz.json');
            $this->command->line('Ese archivo trae los datos migrados y se entrega aparte del repositorio,');
            $this->command->line('porque contiene datos personales reales. Colocalo ahi y vuelve a ejecutar.');

            return;
        }

        $datos = json_decode(file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);

        $tenant = Tenant::first();
        $pais = Country::where('iso_code', 'PE')->first() ?? Country::first();

        if (! $tenant || ! $pais) {
            $this->command->error('Faltan tenant o pais: corre php artisan migrate --seed antes.');

            return;
        }

        $base = ['tenant_id' => $tenant->id, 'created_by' => 1];

        $empresas = $this->sembrarEmpresas($datos['companies'] ?? [], $pais->id, $base);
        $this->sembrarPersonas($datos['people'] ?? [], $pais->id, $base, $empresas);
        $this->sembrarFormatos($datos['form_templates'] ?? [], $pais->id, $base);
    }

    /** @return array<string,int> RUC => id de la empresa */
    protected function sembrarEmpresas(array $filas, int $paisId, array $base): array
    {
        $porRuc = [];

        foreach ($filas as $f) {
            $empresa = Company::withTrashed()
                ->where('country_id', $paisId)
                ->where('num_doc', $f['num_doc'])
                ->first();

            $datos = $base + [
                'country_id'    => $paisId,
                'num_doc'       => $f['num_doc'],
                'name'          => $f['name'],
                'complete_name' => $f['complete_name'],
                'is_active'     => $f['is_active'],
                'legacy_id'     => $f['legacy_id'],
            ];

            $empresa
                ? $empresa->update($datos)
                : $empresa = Company::create($datos + ['slug' => Str::random(22)]);

            $porRuc[$f['num_doc']] = $empresa->id;
        }

        $this->command->info(sprintf('Empresas: %d', count($porRuc)));

        return $porRuc;
    }

    protected function sembrarPersonas(array $filas, int $paisId, array $base, array $empresas): void
    {
        $vinculos = $firmas = 0;

        foreach ($filas as $f) {
            $persona = Person::withTrashed()
                ->where('country_id', $paisId)
                ->where('doc_type', $f['doc_type'])
                ->where('num_doc', $f['num_doc'])
                ->first();

            $datos = $base + [
                'country_id'   => $paisId,
                'doc_type'     => $f['doc_type'],
                'num_doc'      => $f['num_doc'],
                'name'         => $f['name'],
                'lastname'     => $f['lastname'],
                'legacy_table' => $f['legacy_table'] ?? null,
            ];

            $persona
                ? $persona->update($datos)
                : $persona = Person::create($datos + ['slug' => Str::random(22)]);

            foreach ($f['roles'] ?? [] as $rol) {
                PersonRole::firstOrCreate(['person_id' => $persona->id, 'role' => $rol], ['is_active' => true]);
            }

            foreach ($f['companies'] ?? [] as $ruc) {
                if (! isset($empresas[$ruc])) {
                    continue;
                }

                PersonCompanyLink::firstOrCreate(
                    ['person_id' => $persona->id, 'company_id' => $empresas[$ruc]],
                    ['is_active' => true],
                );
                $vinculos++;
            }

            foreach ($f['signatures'] ?? [] as $s) {
                PersonSignature::firstOrCreate(
                    ['person_id' => $persona->id, 'sha256' => $s['sha256']],
                    ['file_path' => $s['file_path'], 'source' => $s['source'], 'valid_from' => now()],
                );
                $firmas++;
            }
        }

        $conVarias = Person::has('companyLinks', '>', 1)->count();

        $this->command->info(sprintf(
            'Personas: %d · vinculos: %d · firmas: %d · %d persona(s) en 2 o mas empresas',
            count($filas), $vinculos, $firmas, $conVarias,
        ));
    }

    protected function sembrarFormatos(array $filas, int $paisId, array $base): void
    {
        $creados = 0;

        foreach ($filas as $f) {
            if (FormTemplate::where('code', $f['code'])->exists()) {
                continue;
            }

            $plantilla = FormTemplate::create($base + [
                'slug'    => Str::random(22),
                'country_id' => $paisId,
                'code'    => $f['code'],
                'kind'    => $f['kind'],
                'status'  => 'published',
                'version' => 1,
                'requires_signature' => $f['requires_signature'],
                'published_at' => now(),
            ]);

            foreach ($f['sections'] as $s) {
                $seccion = $plantilla->sections()->create(['position' => $s['position']]);

                foreach ($s['fields'] as $campo) {
                    $seccion->fields()->create($campo);
                }
            }

            $creados++;
        }

        $this->command->info(sprintf('Formatos: %d creados (%d ya existian)', $creados, count($filas) - $creados));
    }
}
