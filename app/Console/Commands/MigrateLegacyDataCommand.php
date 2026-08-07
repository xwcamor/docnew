<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Country;
use App\Models\Person;
use App\Models\PersonCompanyLink;
use App\Models\PersonRole;
use App\Models\PersonSignature;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Trae los datos del sistema anterior, paso a paso.
 *
 * Cada paso es idempotente: se puede volver a correr sin duplicar nada, porque
 * cada fila migrada guarda su `legacy_id`. Nunca escribe en la base vieja.
 *
 *   php artisan docufiz:migrate-data empresas
 *   php artisan docufiz:migrate-data personas
 *   php artisan docufiz:migrate-data todo
 */
#[AsCommand(
    name: 'docufiz:migrate-data',
    description: 'Migra los datos del sistema anterior (empresas, personas, vinculos y firmas).'
)]
class MigrateLegacyDataCommand extends Command
{
    protected $signature = 'docufiz:migrate-data {paso=todo : empresas|personas|todo}';

    protected int $tenantId;
    protected int $countryId;

    public function handle(): int
    {
        $tenant = Tenant::first();
        $pais = Country::where('iso_code', 'PE')->first() ?? Country::first();

        if (! $tenant || ! $pais) {
            $this->error('Faltan tenant o pais: corre php artisan migrate --seed primero.');

            return self::FAILURE;
        }

        $this->tenantId = $tenant->id;
        $this->countryId = $pais->id;

        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            $this->error('No se pudo conectar a la base anterior. Revisa LEGACY_DB_* en .env');

            return self::FAILURE;
        }

        $paso = $this->argument('paso');

        if (in_array($paso, ['empresas', 'todo'], true)) {
            $this->migrarEmpresas();
        }

        if (in_array($paso, ['personas', 'todo'], true)) {
            $this->migrarPersonas();
        }

        return self::SUCCESS;
    }

    protected function migrarEmpresas(): void
    {
        $this->info('── Empresas ──');
        $viejas = DB::connection('legacy')->table('companies')->orderBy('id')->get();
        $creadas = $actualizadas = 0;

        foreach ($viejas as $v) {
            // Se busca por legacy_id y, si no, por RUC: una empresa que ya
            // existiera (de una carga anterior o de los datos de ejemplo) se
            // adopta en vez de duplicarse.
            $existente = Company::withTrashed()->where('legacy_id', $v->id)->first()
                ?? Company::withTrashed()
                    ->where('country_id', $this->countryId)
                    ->where('num_doc', $v->num_doc)
                    ->first();

            $datos = [
                'country_id'    => $this->countryId,
                'num_doc'       => $v->num_doc,
                'name'          => $v->name,
                'complete_name' => $v->complete_name,
                'is_active'     => (bool) $v->is_active,
                'tenant_id'     => $this->tenantId,
                'legacy_id'     => $v->id,
                'created_by'    => 1,
            ];

            if ($existente) {
                $existente->update($datos);
                $actualizadas++;
            } else {
                Company::create($datos + ['slug' => Str::random(22)]);
                $creadas++;
            }
        }

        $this->linea('empresas', $viejas->count(), Company::whereNotNull('legacy_id')->count(),
            "{$creadas} nuevas, {$actualizadas} actualizadas");
    }

    /**
     * Personas: la parte delicada.
     *
     * En el sistema anterior la misma persona estaba en tres tablas (workers,
     * supervisors, hse_supervisors) y ademas repetida por cada empresa en la que
     * trabajaba. Aqui se agrupan por documento en una sola identidad y lo que se
     * multiplica son los vinculos.
     */
    protected function migrarPersonas(): void
    {
        $this->info('── Personas ──');
        $viejo = DB::connection('legacy');

        $fuentes = [
            'workers'         => PersonRole::WORKER,
            'supervisors'     => PersonRole::SUPERVISOR,
            'hse_supervisors' => PersonRole::HSE_SUPERVISOR,
        ];

        $porDocumento = [];   // num_doc => datos consolidados
        $filasOrigen = 0;

        foreach ($fuentes as $tabla => $rol) {
            foreach ($viejo->table($tabla)->where('is_deleted', 0)->orderBy('id')->get() as $f) {
                $filasOrigen++;
                $doc = trim((string) $f->num_doc);

                if ($doc === '') {
                    continue;
                }

                $porDocumento[$doc] ??= [
                    'name' => $f->name, 'lastname' => $f->lastname,
                    'roles' => [], 'empresas' => [], 'firmas' => [], 'legacy' => [],
                    'nombres_vistos' => [],
                ];

                $p = &$porDocumento[$doc];
                $p['roles'][$rol] = true;
                $p['legacy'][] = "{$tabla}#{$f->id}";
                $p['nombres_vistos'][mb_strtolower(trim("{$f->name} {$f->lastname}"))] = true;

                // El nombre mas largo suele ser el mas completo (nombres compuestos).
                if (mb_strlen("{$f->name} {$f->lastname}") > mb_strlen("{$p['name']} {$p['lastname']}")) {
                    $p['name'] = $f->name;
                    $p['lastname'] = $f->lastname;
                }

                if ($tabla === 'workers' && $f->company_id) {
                    $p['empresas'][$f->company_id] = $f->position_id ?? null;
                }

                if (! empty($f->signature)) {
                    $p['firmas'][$f->signature] = true;
                }
                unset($p);
            }
        }

        $conflictos = [];
        $creadas = $vinculos = $firmas = 0;

        foreach ($porDocumento as $doc => $d) {
            if (count($d['nombres_vistos']) > 1) {
                $conflictos[$doc] = array_keys($d['nombres_vistos']);
            }

            $persona = Person::withTrashed()->where('country_id', $this->countryId)
                ->where('doc_type', 'DNI')->where('num_doc', $doc)->first();

            if (! $persona) {
                $persona = Person::create([
                    'slug' => Str::random(22), 'country_id' => $this->countryId,
                    'doc_type' => 'DNI', 'num_doc' => $doc,
                    'name' => $d['name'], 'lastname' => $d['lastname'],
                    'tenant_id' => $this->tenantId, 'created_by' => 1,
                    'legacy_table' => implode(', ', $d['legacy']),
                ]);
                $creadas++;
            }

            foreach (array_keys($d['roles']) as $rol) {
                PersonRole::firstOrCreate(['person_id' => $persona->id, 'role' => $rol], ['is_active' => true]);
            }

            foreach ($d['empresas'] as $empresaLegacy => $cargoLegacy) {
                $empresa = Company::where('legacy_id', $empresaLegacy)->first();

                if (! $empresa) {
                    continue;
                }

                PersonCompanyLink::firstOrCreate(
                    ['person_id' => $persona->id, 'company_id' => $empresa->id],
                    ['is_active' => true],
                );
                $vinculos++;
            }

            foreach (array_keys($d['firmas']) as $archivo) {
                PersonSignature::firstOrCreate(
                    ['person_id' => $persona->id, 'sha256' => hash('sha256', $archivo)],
                    [
                        'file_path'  => 'legacy/firmas/' . $archivo,
                        'source'     => 'migrated',
                        'valid_from' => now(),
                    ],
                );
                $firmas++;
            }
        }

        $this->linea('personas', $filasOrigen, Person::count(),
            sprintf('%d identidades desde %d filas de las tres tablas', $creadas, $filasOrigen));
        $this->line(sprintf('  vinculos persona-empresa: %d · firmas de referencia: %d', $vinculos, $firmas));

        if ($conflictos !== []) {
            $this->newLine();
            $this->warn(sprintf('%d documento(s) con nombres distintos entre tablas. Se tomo el mas largo; revisalos:', count($conflictos)));

            foreach (array_slice($conflictos, 0, 10, true) as $doc => $nombres) {
                $this->line("  {$doc}: " . implode('  |  ', $nombres));
            }
        }
    }

    /** Conteo de origen contra destino: si no cuadra, se ve aqui. */
    protected function linea(string $que, int $origen, int $destino, string $detalle): void
    {
        $this->line(sprintf('  %-10s origen: %-6d destino: %-6d  %s', $que, $origen, $destino, $detalle));
    }
}
