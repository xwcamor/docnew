<?php

namespace App\Services\BusinessManagement;

use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Guarda de una vez las secciones y los campos de un documento.
 *
 * `FormTemplateBuilder` sabe añadir UNA sección y UN campo, que es lo que hacía
 * falta para el comando que trajo los formatos de la v1. La pantalla manda otra
 * cosa: el árbol entero tal y como quedó después de que alguien moviera cuatro
 * campos, renombrara dos y borrara uno. Eso es una sincronización —crear lo que
 * no estaba, actualizar lo que sí, borrar lo que sobra y renumerar todo— y no
 * se puede montar llamando en bucle a los métodos de alta sin dejar posiciones
 * duplicadas por el camino.
 *
 * Todo va en una transacción porque un árbol a medias es peor que no haber
 * guardado: un documento con la sección nueva pero sin sus campos se publica
 * igual y sale en obra incompleto.
 */
class FormTemplateStructureService
{
    /**
     * Un documento sólo se reestructura mientras no sea evidencia de nada.
     *
     * Dos condiciones, y hacen falta las dos. La primera es la de
     * `FormTemplateBuilder::soloBorrador()`: publicado no se toca. La segunda
     * tapa el agujero que deja la primera — «Despublicar» devuelve el estado a
     * `draft` sin borrar las entregas, así que con sólo mirar el estado se
     * podía despublicar un AST con tres años de entregas firmadas detrás,
     * quitarle un campo y volver a publicar. Las respuestas de ese campo se las
     * lleva por delante la clave ajena en cascada, en silencio.
     *
     * Se cuentan también las entregas en papelera: un borrado lógico no
     * desfirma nada, y sus respuestas siguen en la tabla.
     */
    public function esEditable(FormTemplate $plantilla): bool
    {
        return $plantilla->status === 'draft' && $this->entregas($plantilla) === 0;
    }

    /** Cuántas veces se ha llenado, contando las que están en papelera. */
    public function entregas(FormTemplate $plantilla): int
    {
        return $plantilla->submissions()->withTrashed()->count();
    }

    /**
     * El motivo por el que no se deja editar, para poder decirlo en pantalla.
     * Cuál de los dos manda importa: si ya se llenó, ese es el motivo de peso.
     */
    public function motivoBloqueo(FormTemplate $plantilla): ?string
    {
        if ($this->entregas($plantilla) > 0) {
            return 'submissions';
        }

        return $plantilla->status === 'draft' ? null : 'published';
    }

    /**
     * Sincroniza el árbol. `$secciones` viene ya validado por el FormRequest y
     * llega en el orden en que se ve en pantalla: la posición es el índice.
     *
     * @param  array<int, array{id?: int|null, fields: array<int, array>}>  $secciones
     */
    public function sincronizar(FormTemplate $plantilla, array $secciones): void
    {
        if (! $this->esEditable($plantilla)) {
            throw new \DomainException(__('form_templates.structure_not_editable'));
        }

        DB::transaction(function () use ($plantilla, $secciones) {
            $seccionesVivas = $plantilla->sections()->with('fields')->get()->keyBy('id');
            $camposVivos    = $seccionesVivas->flatMap->fields->keyBy('id');

            $seccionesQueSiguen = [];
            $camposQueSiguen    = [];

            // Primera pasada: crear y actualizar. Se guarda para el final la
            // escritura del `code`, ver `renombrarCodigos()`.
            $pendientesDeCodigo = [];

            $this->aparcarCodigos($camposVivos->keys()->all());

            foreach (array_values($secciones) as $iSeccion => $datosSeccion) {
                $seccion = $this->seccion($plantilla, $seccionesVivas, $datosSeccion['id'] ?? null);
                $seccion->position = $iSeccion + 1;
                $seccion->name_es  = $this->texto($datosSeccion['name_es'] ?? null);
                $seccion->name_en  = $this->texto($datosSeccion['name_en'] ?? null);
                $seccion->save();

                $seccionesQueSiguen[] = $seccion->id;

                foreach (array_values($datosSeccion['fields'] ?? []) as $iCampo => $datosCampo) {
                    $campo = $this->campo($camposVivos, $datosCampo['id'] ?? null);

                    $campo->form_section_id = $seccion->id;
                    $campo->field_type      = $datosCampo['field_type'];
                    $campo->is_required     = (bool) ($datosCampo['is_required'] ?? false);
                    $campo->position        = $iCampo + 1;
                    // La etiqueta va a su columna. Un campo de antes de que
                    // existieran la traía en `config.label`, y el editor la
                    // sube ya rellena desde ahí (ver el payload de
                    // `structure()`), así que se rescata en vez de perderse.
                    $campo->label_es        = $this->texto($datosCampo['label_es'] ?? null)
                                              ?? ($datosCampo['config']['label'] ?? null);
                    $campo->label_en        = $this->texto($datosCampo['label_en'] ?? null);
                    $campo->config          = $this->limpiarConfig($datosCampo['config'] ?? []);

                    // Un campo nuevo necesita `code` ya, que la columna es NOT
                    // NULL; se le pone uno provisional y único.
                    if (! $campo->exists) {
                        $campo->code = '__nuevo_' . $iSeccion . '_' . $iCampo;
                    }

                    $campo->save();

                    $camposQueSiguen[] = $campo->id;
                    $pendientesDeCodigo[$campo->id] = $datosCampo['code'];
                }
            }

            $this->borrarLoQueSobra($camposVivos, $camposQueSiguen, $seccionesVivas, $seccionesQueSiguen);
            $this->renombrarCodigos($pendientesDeCodigo);
        });
    }

    /** La sección de la petición, o una nueva si venía sin `id`. */
    private function seccion(FormTemplate $plantilla, $vivas, ?int $id): FormSection
    {
        $existente = $id !== null ? $vivas->get($id) : null;

        return $existente ?? new FormSection(['form_template_id' => $plantilla->id]);
    }

    /** El campo de la petición, o uno nuevo si venía sin `id`. */
    private function campo($vivos, ?int $id): FormField
    {
        return ($id !== null ? $vivos->get($id) : null) ?? new FormField();
    }

    /**
     * Aparca el código de todos los campos que ya existían.
     *
     * `form_fields_code_unique` es (sección, código) y salta en el acto, no al
     * final de la transacción. Dos cosas que la pantalla permite lo rompían a
     * mitad de guardado: intercambiar los códigos de dos campos de la misma
     * sección, y arrastrar un campo a otra sección donde ese código lo tiene
     * todavía un campo que se va a borrar dos líneas después.
     *
     * Aparcarlos todos en un valor imposible de chocar —el id, que es único— y
     * escribir los definitivos al final deja el terreno despejado, sin tener
     * que adivinar qué renombrado choca con cuál.
     */
    private function aparcarCodigos(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($ids as $id) {
            FormField::whereKey($id)->update(['code' => '__tmp_' . $id]);
        }
    }

    /** La segunda vuelta: los códigos definitivos, con el terreno ya libre. */
    private function renombrarCodigos(array $codigos): void
    {
        foreach ($codigos as $id => $code) {
            FormField::whereKey($id)->update(['code' => $code]);
        }
    }

    /**
     * Quitar lo que el usuario quitó, con un cerrojo antes.
     *
     * `form_answers.form_field_id` es `cascadeOnDelete`: borrar un campo se
     * lleva sus respuestas sin decir nada. `esEditable()` ya impide llegar aquí
     * con entregas detrás, pero esto es la evidencia de un documento firmado y
     * un solo camino de salida no basta — si mañana alguien afloja el guard de
     * arriba, aquí sigue reventando en vez de borrar en silencio.
     */
    private function borrarLoQueSobra($camposVivos, array $camposQueSiguen, $seccionesVivas, array $seccionesQueSiguen): void
    {
        $camposABorrar = $camposVivos->keys()->diff($camposQueSiguen)->values()->all();

        if ($camposABorrar !== []) {
            $conRespuestas = FormAnswer::whereIn('form_field_id', $camposABorrar)
                ->pluck('form_field_id')
                ->unique();

            if ($conRespuestas->isNotEmpty()) {
                throw new \DomainException(__('form_templates.structure_field_has_answers', [
                    'code' => $camposVivos->get($conRespuestas->first())?->code ?? $conRespuestas->first(),
                ]));
            }

            FormField::whereIn('id', $camposABorrar)->delete();
        }

        $seccionesABorrar = $seccionesVivas->keys()->diff($seccionesQueSiguen)->values()->all();

        if ($seccionesABorrar !== []) {
            FormSection::whereIn('id', $seccionesABorrar)->delete();
        }
    }

    /**
     * Una `config` vacía se guarda como NULL, no como `{}`.
     *
     * El resto del sistema pregunta `config?->options ?? []` y le da igual, pero
     * la columna nació nullable y los cuatro formatos de la v1 tienen NULL ahí:
     * dejar `{}` en unos y NULL en otros hace que dos campos idénticos no se
     * parezcan al compararlos en la auditoría.
     */
    private function limpiarConfig(array $config): ?array
    {
        // `label` sale de la configuración: ahora es una columna. Estuvo aquí
        // mientras el motor no tenía dónde poner el texto, y dejar las dos
        // copias es peor que no tener ninguna — se editaría la columna y la
        // pantalla de llenado seguiría leyendo el JSON de hace tres meses. Lo
        // que había escrito ya se rescató al fijar `label_es`.
        unset($config['label']);

        $limpia = [];

        foreach ($config as $clave => $valor) {
            if ($valor === null || $valor === '' || $valor === []) {
                continue;
            }

            $limpia[$clave] = $valor;
        }

        return $limpia === [] ? null : $limpia;
    }

    /** Un texto vacío es «sin nombre», y eso en la base es NULL, no `''`. */
    private function texto(?string $valor): ?string
    {
        $limpio = trim((string) $valor);

        return $limpio === '' ? null : $limpio;
    }
}
