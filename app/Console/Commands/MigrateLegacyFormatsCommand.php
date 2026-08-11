<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\FormTemplate;
use App\Models\Tenant;
use App\Services\FieldWork\FormTemplateBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Construye los cuatro formatos historicos (AST, PTF, EPP, IHM) como plantillas
 * del motor, con los catalogos reales del sistema anterior.
 *
 * Es el paso que demuestra que el motor sirve: lo que antes eran cuatro tablas
 * con sus modelos, controladores, vistas y PDF —entre 3 y 5 dias de trabajo por
 * formato— aqui es configuracion leida de la base vieja.
 *
 *   php artisan docufiz:migrate-formats
 *   php artisan docufiz:migrate-formats --fresh   (rehace las plantillas)
 */
#[AsCommand(
    name: 'docufiz:migrate-formats',
    description: 'Crea las plantillas AST, PTF, EPP e IHM con los catalogos del sistema anterior.'
)]
class MigrateLegacyFormatsCommand extends Command
{
    protected $signature = 'docufiz:migrate-formats {--fresh : Borra las plantillas migradas antes de rehacerlas}';

    public function handle(FormTemplateBuilder $constructor): int
    {
        $pais = Country::where('iso_code', 'PE')->first() ?? Country::first();
        $tenant = Tenant::first();

        if (! $pais || ! $tenant) {
            $this->error('Faltan pais o tenant: siembra la base antes (php artisan migrate --seed).');

            return self::FAILURE;
        }

        $base = ['country_id' => $pais->id, 'tenant_id' => $tenant->id, 'created_by' => 1];

        try {
            $catalogos = $this->leerCatalogos();
        } catch (\Throwable $e) {
            $this->error('No se pudo leer la base anterior: ' . $e->getMessage());
            $this->line('Revisa LEGACY_DB_* en tu .env.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $codigos = ['AST', 'PTF', 'EPP', 'IHM'];
            $ids = FormTemplate::withTrashed()->whereIn('code', $codigos)->pluck('id');

            // Una plantilla con entregas no se puede borrar: son documentos ya
            // llenados. Se avisa en vez de arrastrarlos.
            $conEntregas = \App\Models\FormSubmission::whereIn('form_template_id', $ids)->count();

            if ($conEntregas > 0) {
                $this->warn("Hay {$conEntregas} entrega(s) usando esas plantillas: se borran tambien (datos de demostracion).");
                \App\Models\FormSubmission::whereIn('form_template_id', $ids)->forceDelete();
            }

            \App\Models\WorkType::whereHas('formTemplates', fn ($q) => $q->whereIn('form_templates.id', $ids))
                ->each(fn ($wt) => $wt->formTemplates()->detach($ids));

            FormTemplate::withTrashed()->whereIn('code', $codigos)->forceDelete();
            $this->warn('Plantillas anteriores eliminadas.');
        }

        $this->construirAst($constructor, $base, $catalogos);
        $this->construirPtf($constructor, $base, $catalogos);
        $this->construirEpp($constructor, $base, $catalogos);
        $this->construirIhm($constructor, $base, $catalogos);

        $this->newLine();
        $this->table(
            ['Formato', 'Version', 'Estado', 'Campos'],
            FormTemplate::whereIn('code', ['AST', 'PTF', 'EPP', 'IHM'])->get()
                ->map(fn ($t) => [$t->code, $t->version, $t->status, $t->fields()->count()])
                ->all(),
        );

        return self::SUCCESS;
    }

    /** Lee los catalogos de la base anterior. Solo lectura, nunca escribe alli. */
    protected function leerCatalogos(): array
    {
        $viejo = DB::connection('legacy');
        $nombres = fn (string $tabla) => $viejo->table($tabla)
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->pluck('name_es')
            ->filter()
            ->values()
            ->all();

        return [
            'actividades'   => $nombres('ast_activities'),
            'peligros'      => $nombres('ast_dangers'),
            // `ast_risks` no se traia y era una columna entera del AST y del
            // PTF: en la v1 la fila es actividad → peligro → RIESGO → control,
            // donde el riesgo es la consecuencia («Agotamiento de recurso
            // natural»). Sin el catalogo, la pantalla no tenia de donde
            // ofrecerlo y lo migrado se quedaba sin sitio donde enseñarlo.
            'riesgos'       => $nombres('ast_risks'),
            'controles'     => $nombres('ast_controls'),
            'equipos'       => $nombres('ast_equipments'),
            'objetivos'     => $nombres('ast_objetives'),
            'epp'           => $nombres('epp_items'),
            'herramientas'  => $nombres('ihm_items'),
            'items_ihm'     => $nombres('ihm_tools'),
            'preguntas'     => $viejo->table('ptf_questions')->where('is_deleted', 0)
                                ->orderBy('id')->pluck('name_es')->filter()->values()->all(),
            // severities y probabilities usan `name` (c1..c5), no los tres idiomas.
            'severidades'   => $viejo->table('severities')->where('is_deleted', 0)
                                ->orderBy('id')->pluck('name')->values()->all(),
            'probabilidades' => $viejo->table('probabilities')->where('is_deleted', 0)
                                ->orderBy('id')->pluck('name')->values()->all(),
            'matriz'        => $this->leerMatrizDeRiesgo(),
        ];
    }

    /**
     * La matriz de riesgo real, tal cual estaba en la base anterior.
     *
     * Y esto importa: el riesgo **no** es severidad × probabilidad. La tabla
     * `risks` guarda un ranking del 1 al 25 en el que el 1 es lo peor
     * (c1 = mas grave, p1 = mas probable) y el 25 lo mas leve. Doce de las
     * veinticinco celdas caen en una banda distinta si se calcula con el
     * producto: c2×p4 vale 12 en la tabla y 8 multiplicando, que es la
     * diferencia entre «medio» y «alto» en un documento de seguridad.
     *
     * Las bandas salen de `Risk#level_name` del sistema anterior:
     * 1-8 alto, 9-15 medio, 16-25 bajo.
     *
     * @return array{valores: array<int,array<int,int>>, niveles: array}
     */
    protected function leerMatrizDeRiesgo(): array
    {
        $viejo = DB::connection('legacy');

        // Posicion en el catalogo, no id: es como la indexa la pantalla.
        $posSeveridad = array_flip($viejo->table('severities')->where('is_deleted', 0)
            ->orderBy('id')->pluck('id')->all());
        $posProbabilidad = array_flip($viejo->table('probabilities')->where('is_deleted', 0)
            ->orderBy('id')->pluck('id')->all());

        $valores = [];

        foreach ($viejo->table('risks')->where('is_deleted', 0)->get() as $r) {
            $s = $posSeveridad[$r->severity_id] ?? null;
            $p = $posProbabilidad[$r->probability_id] ?? null;

            if ($s === null || $p === null) {
                continue;
            }

            $valores[$s][$p] = (int) $r->risk_value;
        }

        ksort($valores);

        foreach ($valores as &$fila) {
            ksort($fila);
            $fila = array_values($fila);
        }
        unset($fila);

        return [
            'valores' => array_values($valores),
            'niveles' => [
                ['hasta' => 8,  'clave' => 'alto'],
                ['hasta' => 15, 'clave' => 'medio'],
                ['hasta' => 25, 'clave' => 'bajo'],
            ],
        ];
    }

    /**
     * AST — Analisis de Seguridad en el Trabajo.
     *
     * En el sistema viejo eran tres tablas encadenadas: actividades, y por cada
     * actividad sus peligros con control, severidad y probabilidad. Aqui es un
     * solo campo de tipo matriz de riesgo, con los catalogos como opciones.
     */
    protected function construirAst(FormTemplateBuilder $c, array $base, array $cat): void
    {
        $t = $this->plantilla($c, $base, 'AST');
        if (! $t) return;

        $s = $c->agregarSeccion($t);
        $c->agregarCampo($s, [
            'code' => 'matriz_de_riesgo', 'field_type' => 'risk_matrix', 'is_required' => true,
            'config' => [
                'activities'    => $cat['actividades'],
                'dangers'       => $cat['peligros'],
                'risks'         => $cat['riesgos'],
                'controls'      => $cat['controles'],
                'severities'    => $cat['severidades'],
                'probabilities' => $cat['probabilidades'],
                // La matriz real del sistema anterior, no una formula: ver
                // leerMatrizDeRiesgo().
                'matrix'        => $cat['matriz']['valores'],
                'levels'        => $cat['matriz']['niveles'],
            ],
        ]);

        $s2 = $c->agregarSeccion($t);
        $c->agregarCampo($s2, ['code' => 'equipos', 'field_type' => 'multiselect',
            'config' => ['options' => $cat['equipos']]]);
        $c->agregarCampo($s2, ['code' => 'objetivos', 'field_type' => 'multiselect',
            'config' => ['options' => $cat['objetivos']]]);
        $c->agregarCampo($s2, ['code' => 'observaciones', 'field_type' => 'textarea']);

        $c->publicar($t);
        $this->info(sprintf('AST: matriz con %d actividades, %d peligros, %d riesgos y %d controles.',
            count($cat['actividades']), count($cat['peligros']), count($cat['riesgos']), count($cat['controles'])));
    }

    /** PTF — Pare y Tome 5: banco de preguntas mas la matriz de riesgo. */
    protected function construirPtf(FormTemplateBuilder $c, array $base, array $cat): void
    {
        $t = $this->plantilla($c, $base, 'PTF');
        if (! $t) return;

        $s = $c->agregarSeccion($t);
        $c->agregarCampo($s, [
            'code' => 'preguntas', 'field_type' => 'question_bank', 'is_required' => true,
            'config' => ['questions' => $cat['preguntas'], 'answers' => ['Si', 'No', 'No aplica']],
        ]);

        $s2 = $c->agregarSeccion($t);
        $c->agregarCampo($s2, [
            'code' => 'matriz_de_riesgo', 'field_type' => 'risk_matrix',
            'config' => [
                'activities' => $cat['actividades'], 'dangers' => $cat['peligros'],
                'risks' => $cat['riesgos'], 'controls' => $cat['controles'],
                'severities' => $cat['severidades'], 'probabilities' => $cat['probabilidades'],
                'matrix' => $cat['matriz']['valores'], 'levels' => $cat['matriz']['niveles'],
            ],
        ]);
        $c->agregarCampo($s2, ['code' => 'observaciones', 'field_type' => 'textarea']);

        $c->publicar($t);
        $this->info(sprintf('PTF: %d preguntas del banco.', count($cat['preguntas'])));
    }

    /** EPP — una fila por trabajador del plan, con sus items de proteccion. */
    protected function construirEpp(FormTemplateBuilder $c, array $base, array $cat): void
    {
        $t = $this->plantilla($c, $base, 'EPP');
        if (! $t) return;

        $s = $c->agregarSeccion($t);
        $c->agregarCampo($s, [
            'code' => 'epp_por_trabajador', 'field_type' => 'person_checklist', 'is_required' => true,
            'config' => [
                'items'   => $cat['epp'],
                'answers' => ['Conforme', 'No conforme', 'No aplica'],
                // Campos de correccion que traia el formato original.
                'extra'   => ['correction_measure', 'deadline_date', 'correction_verification'],
            ],
        ]);
        $c->agregarCampo($s, ['code' => 'observaciones', 'field_type' => 'textarea']);

        $c->publicar($t);
        $this->info(sprintf('EPP: %d items por trabajador.', count($cat['epp'])));
    }

    /** IHM — inspeccion de herramientas manuales: una fila por herramienta. */
    protected function construirIhm(FormTemplateBuilder $c, array $base, array $cat): void
    {
        $t = $this->plantilla($c, $base, 'IHM');
        if (! $t) return;

        $s = $c->agregarSeccion($t);
        $c->agregarCampo($s, [
            'code' => 'inspeccion_de_herramientas', 'field_type' => 'tool_checklist', 'is_required' => true,
            'config' => [
                'tools'   => $cat['items_ihm'],
                'items'   => $cat['herramientas'],
                // 0 = no cumple, 1 = cumple, 2 = no aplica, como en el formato original.
                'answers' => ['No cumple', 'Cumple', 'No aplica'],
            ],
        ]);
        $c->agregarCampo($s, ['code' => 'observaciones', 'field_type' => 'textarea']);

        $c->publicar($t);
        $this->info(sprintf('IHM: %d herramientas y %d puntos de inspeccion.',
            count($cat['items_ihm']), count($cat['herramientas'])));
    }

    /**
     * El nombre de cada formato, tal y como se lee en el sistema anterior.
     *
     * Alli el codigo no se enseña: `Document#translated_name` resuelve
     * `documents.ast_documents` contra la tabla `translations` y sale «AST
     * (Analisis de Seguridad en el Trabajo)». Yo cree las plantillas con el
     * codigo a secas, asi que en pantalla salia «AST» — una sigla que hay que
     * saberse de antemano.
     */
    protected const NOMBRES = [
        'AST' => 'AST (Análisis de Seguridad en el Trabajo)',
        'PTF' => 'Pare Tome 5',
        'EPP' => 'Inspección de EPP (Equipos de Protección de Seguridad)',
        'IHM' => 'Inspección de Herramientas Manuales y Eléctricas Portátiles',
    ];

    /** Crea la plantilla si no existe ya publicada. */
    protected function plantilla(FormTemplateBuilder $c, array $base, string $codigo): ?FormTemplate
    {
        $existente = FormTemplate::where('code', $codigo)->first();

        if ($existente) {
            // Re-correr el comando rellena el nombre de las plantillas que ya
            // se crearon sin el, que es el caso de las cuatro que hay migradas.
            if (blank($existente->name) && isset(self::NOMBRES[$codigo])) {
                $existente->update(['name' => self::NOMBRES[$codigo]]);
                $this->line("  {$codigo}: nombre puesto — {$existente->name}");

                return null;
            }

            // Por que «ya existe» si la base se acaba de rehacer.
            //
            // Porque la creo `FormTemplatesSeeder` unos segundos antes, en el
            // mismo `setup:project`: el seeder trae las cuatro plantillas desde
            // `formatos-v1.json` para que una instalacion limpia funcione sin
            // tener la base vieja delante. O sea que este comando, dentro de
            // `--datos`, no tiene nada que hacer.
            //
            // El mensaje anterior decia «ya existe, se omite (usa --fresh para
            // rehacerlo)» y asustaba con razon: parecia que quedaba algo de una
            // corrida anterior y que hacia falta forzar. No queda nada — la base
            // se borro entera hace un momento.
            $this->line("  {$codigo}: ya la creo el sembrador en esta misma corrida, no hay nada que traer.");

            return null;
        }

        return $c->crear($base + [
            'code' => $codigo,
            'name' => self::NOMBRES[$codigo] ?? $codigo,
            'kind' => FormTemplate::STRUCTURED,
        ]);
    }
}
