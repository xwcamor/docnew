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
     */
    public function publicar(FormTemplate $plantilla): FormTemplate
    {
        if ($plantilla->kind !== FormTemplate::UPLOAD_ONLY && ! $plantilla->fields()->exists()) {
            throw new \InvalidArgumentException('Un formato con campos no puede publicarse vacio.');
        }

        $plantilla->update(['status' => 'published', 'published_at' => now()]);

        return $plantilla->fresh();
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
