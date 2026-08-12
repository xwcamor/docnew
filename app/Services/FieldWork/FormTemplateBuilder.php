<?php

namespace App\Services\FieldWork;

use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Construccion de un formato desde la interfaz.
 *
 * Este servicio es la razon de ser del proyecto: en el sistema anterior un
 * formato nuevo eran una tabla, un modelo, un controlador, sus vistas y su PDF
 * — entre 3 y 5 dias de trabajo — y aun asi los campos estaban cableados en el
 * codigo. Aqui se define y se publica sin tocar el repositorio.
 */
class FormTemplateBuilder
{
    /** Tipos de campo y que necesita cada uno en su configuracion. */
    public const TIPOS = [
        'text'      => [],
        'textarea'  => [],
        'number'    => ['min', 'max', 'decimals'],
        'date'      => [],
        'time'      => [],
        'select'    => ['options'],
        'multiselect' => ['options'],
        'checkbox'  => [],
        'radio'     => ['options'],
        'table'     => ['columns'],
        'photo'     => ['max_files'],
        'file'      => ['max_files', 'mimes'],
        'signature' => [],
        // Compuestos: reproducen lo que en el sistema viejo eran formatos enteros.
        'person_checklist' => ['items'],   // EPP: una fila por trabajador del plan
        'tool_checklist'   => ['items'],   // IHM: una fila por herramienta
        'risk_matrix'      => ['severities', 'probabilities'], // AST y PTF
        'question_bank'    => ['questions'],                   // PTF
    ];

    /** Los que exigen configuracion para poder pintarse. */
    public const CONFIG_OBLIGATORIA = [
        'select', 'multiselect', 'radio', 'table',
        'person_checklist', 'tool_checklist', 'risk_matrix', 'question_bank',
    ];

    public function crear(array $datos): FormTemplate
    {
        return FormTemplate::create([
            'slug'    => Str::random(22),
            'country_id' => $datos['country_id'],
            'code'    => $datos['code'],
            // El nombre se recibia y no se escribia: los cuatro formatos que trae
            // `docufiz:migrate-formats` (AST/PTF/EPP/IHM) nacian con `name` NULL
            // en una instalacion limpia, y solo se arreglaban al re-ejecutar el
            // comando, que si repara nombres.
            'name'    => $datos['name'] ?? null,
            'kind'    => $datos['kind'] ?? FormTemplate::STRUCTURED,
            'status'  => 'draft',
            'version' => 1,
            'requires_signature' => $datos['requires_signature'] ?? true,
            'tenant_id'  => $datos['tenant_id'] ?? null,
            'created_by' => $datos['created_by'] ?? auth()->id(),
        ]);
    }

    public function agregarSeccion(FormTemplate $plantilla, ?int $posicion = null): FormSection
    {
        $this->soloBorrador($plantilla);

        return $plantilla->sections()->create([
            'position' => $posicion ?? ($plantilla->sections()->max('position') + 1),
        ]);
    }

    public function agregarCampo(FormSection $seccion, array $datos): FormField
    {
        $this->soloBorrador($seccion->formTemplate()->first());

        $tipo = $datos['field_type'];

        if (! array_key_exists($tipo, self::TIPOS)) {
            throw new \InvalidArgumentException("Tipo de campo desconocido: {$tipo}");
        }

        $config = $datos['config'] ?? [];

        if (in_array($tipo, self::CONFIG_OBLIGATORIA, true)) {
            $faltan = array_diff(self::TIPOS[$tipo], array_keys($config));

            if ($faltan !== []) {
                throw new \InvalidArgumentException(
                    "El campo '{$tipo}' necesita configurar: " . implode(', ', $faltan)
                );
            }
        }

        return $seccion->fields()->create([
            'code'        => Str::slug($datos['code'], '_'),
            'field_type'  => $tipo,
            'is_required' => $datos['is_required'] ?? false,
            'position'    => $datos['position'] ?? ($seccion->fields()->max('position') + 1),
            'config'      => $config ?: null,
            'visibility_rule' => $datos['visibility_rule'] ?? null,
        ]);
    }

    /**
     * Publica el formato. A partir de aqui no se puede editar: para cambiarlo se
     * saca una version nueva, porque lo ya firmado no se puede alterar.
     *
     * Publicar es tambien el RELEVO de la version anterior, y son dos cosas,
     * ninguna de las cuales pasaba:
     *
     *  1. La vieja se archiva. `nuevaVersion()` ya prometia esto en su docblock
     *     —«la anterior se archiva cuando la nueva se publica»— pero no lo hacia
     *     nadie: `archived` existia solo como etiqueta del desplegable de
     *     filtros. Quedaban dos filas diciendo «Publicado», mismo nombre, mismo
     *     codigo, indistinguibles en el listado.
     *
     *  2. Se mueve el pivote con los tipos de trabajo. `WorkPlan::
     *     documentosEstandar()` resuelve por `work_type_form_templates`, que
     *     apunta al `form_template_id` de la fila vieja. Sin moverlo, publicar
     *     la version nueva no cambiaba absolutamente nada de lo que reciben los
     *     planes: se seguia sirviendo la v1, en silencio.
     *
     * Lo ya firmado no se toca — las entregas guardan `template_version` y el
     * PDF busca esa fila por codigo y version (`FormSubmissionPdfService`), asi
     * que archivar la vieja no altera ningun documento entregado.
     */
    public function publicar(FormTemplate $plantilla): FormTemplate
    {
        if ($plantilla->kind !== FormTemplate::UPLOAD_ONLY && ! $plantilla->fields()->exists()) {
            throw new \InvalidArgumentException('Un formato con campos no puede publicarse vacio.');
        }

        return DB::transaction(function () use ($plantilla) {
            $anteriores = $this->versionesAnteriores($plantilla, 'published');

            $plantilla->update(['status' => 'published', 'published_at' => now()]);

            foreach ($anteriores as $vieja) {
                $this->moverTiposDeTrabajo($vieja, $plantilla);
                $vieja->update(['status' => 'archived']);
            }

            return $plantilla->fresh();
        });
    }

    /**
     * Deshace la publicacion.
     *
     * Es el inverso de `publicar()` y tiene que deshacer las dos cosas que
     * aquel hace, o deja el documento en un estado que no existia antes: la
     * version anterior archivada y el pivote de los tipos de trabajo apuntando
     * a un borrador — o sea, tipos de trabajo que exigen un documento que
     * ningun plan puede usar.
     *
     * Si no hay predecesora no hay nada que devolver, y entonces esto es lo que
     * era: poner el estado en borrador.
     */
    public function despublicar(FormTemplate $plantilla): FormTemplate
    {
        return DB::transaction(function () use ($plantilla) {
            $plantilla->update(['status' => 'draft']);

            $predecesora = $this->versionesAnteriores($plantilla, 'archived')
                ->sortByDesc('version')
                ->first();

            if ($predecesora) {
                $predecesora->update(['status' => 'published']);
                $this->moverTiposDeTrabajo($plantilla, $predecesora);
            }

            return $plantilla->fresh();
        });
    }

    /**
     * Pasa las exigencias de los tipos de trabajo de una version a otra.
     *
     * `WorkPlan::documentosEstandar()` resuelve por `work_type_form_templates`,
     * que guarda un `form_template_id` — el de una FILA, o sea el de una
     * version concreta. Sin mover esto, publicar una version nueva no cambiaba
     * nada de lo que reciben los planes.
     *
     * Los tipos que ya apuntan al destino se saltan y su fila de origen se
     * borra: moverla reventaria el unique (work_type_id, form_template_id).
     */
    protected function moverTiposDeTrabajo(FormTemplate $desde, FormTemplate $hacia): void
    {
        $yaApuntan = DB::table('work_type_form_templates')
            ->where('form_template_id', $hacia->id)
            ->pluck('work_type_id')
            ->all();

        DB::table('work_type_form_templates')
            ->where('form_template_id', $desde->id)
            ->whereNotIn('work_type_id', $yaApuntan)
            ->update(['form_template_id' => $hacia->id, 'updated_at' => now()]);

        DB::table('work_type_form_templates')
            ->where('form_template_id', $desde->id)
            ->delete();
    }

    /**
     * Las versiones mas viejas del mismo documento, en el estado que se pida.
     *
     * La identidad de un documento a lo largo de sus versiones es
     * (workspace, pais, codigo) — la clave unica de la tabla lleva esos tres
     * mas `version`. `tenant_id` es nullable para el catalogo global, y por eso
     * la comparacion se parte en dos ramas: `WHERE tenant_id = NULL` no empareja
     * nada.
     *
     * @return \Illuminate\Support\Collection<int, FormTemplate>
     */
    protected function versionesAnteriores(FormTemplate $plantilla, string $estado)
    {
        return FormTemplate::query()
            ->where('id', '!=', $plantilla->id)
            ->where('country_id', $plantilla->country_id)
            ->where('version', '<', $plantilla->version)
            ->where('status', $estado)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $plantilla->code)])
            ->when(
                $plantilla->tenant_id === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $plantilla->tenant_id),
            )
            ->get();
    }

    /**
     * Version nueva a partir de una publicada. Se copia la estructura y queda en
     * borrador; la anterior se archiva cuando la nueva se publica.
     */
    public function nuevaVersion(FormTemplate $plantilla): FormTemplate
    {
        return DB::transaction(function () use ($plantilla) {
            $nueva = $plantilla->replicate(['slug', 'status', 'published_at']);
            $nueva->slug = Str::random(22);
            $nueva->status = 'draft';
            $nueva->published_at = null;
            $nueva->version = $plantilla->version + 1;
            $nueva->save();

            foreach ($plantilla->sections()->with('fields')->get() as $seccion) {
                $copia = $nueva->sections()->create(['position' => $seccion->position]);

                foreach ($seccion->fields as $campo) {
                    $copia->fields()->create($campo->only([
                        'code', 'field_type', 'is_required', 'position', 'config', 'visibility_rule',
                    ]));
                }
            }

            return $nueva;
        });
    }

    /** Un formato publicado no se toca: se saca una version nueva. */
    protected function soloBorrador(FormTemplate $plantilla): void
    {
        if ($plantilla->status !== 'draft') {
            throw new \InvalidArgumentException(
                'El formato ya esta publicado. Crea una version nueva para modificarlo.'
            );
        }
    }
}
