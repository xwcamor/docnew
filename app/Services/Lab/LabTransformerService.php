<?php

namespace App\Services\Lab;

use App\Models\Customer;
use App\Models\CustomerSubstation;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Support\LikeQuery;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolución y alta de transformadores desde el laboratorio.
 *
 * Acá se resuelven los desajustes entre los dos modelos de datos. La regla que
 * los gobierna a todos: **ante la duda, fallar**. El sistema anterior emparejaba
 * equipos con un `find_by` por número de serie —que devuelve el primero si hay
 * repetidos— y mapeaba 20 tipos de equipo a 3 con un "si es mayor a 3 →
 * Potencia". Las dos cosas mandan muestras al transformador equivocado o lo
 * diagnostican con el cuadro de reglas equivocado, y ninguna deja rastro.
 *
 * Todas las consultas usan Eloquent normal: `BelongsToTenant` ya filtra por el
 * workspace dueño del token, así que un laboratorio no puede ver ni tocar la
 * flota de otro.
 */
class LabTransformerService
{
    /** Tope de candidatos que devuelve la búsqueda (no es un listado, es un match). */
    private const MAX_CANDIDATES = 25;

    /**
     * Candidatos que coinciden con lo que el laboratorio conoce del equipo.
     * Devuelve 0, 1 o varios: la desambiguación es del laboratorio, con una
     * persona en su bandeja de conciliación.
     *
     * `$exact` cambia el criterio y no es un detalle: la BÚSQUEDA (la pantalla
     * de conciliación) usa coincidencia parcial porque ahí hay alguien mirando
     * la lista; la RESOLUCIÓN automática de un envío usa coincidencia exacta,
     * porque un "S1" que entra parcialmente en el único "XS100" de la flota
     * resolvería a un candidato solo y mandaría la muestra al equipo equivocado
     * sin que nadie lo note. Es el mismo error del `find_by` del sistema viejo,
     * con otro disfraz.
     */
    public function lookup(?string $serial, ?string $tag, ?string $customer, bool $exact = false): Collection
    {
        $query = Transformer::query()->with(['customer:id,name', 'substation:id,name']);

        $isPgsql = config('database.default') === 'pgsql';
        $match = function ($column, $value) use ($isPgsql, $exact) {
            if ($exact) {
                return $isPgsql
                    ? ['unaccent(lower(' . $column . ')) = unaccent(lower(?))', [$value]]
                    : ['lower(' . $column . ') = lower(?)', [$value]];
            }

            return $isPgsql
                ? ['unaccent(lower(' . $column . ')) LIKE unaccent(lower(?))', [LikeQuery::contains($value)]]
                : [$column . ' LIKE ? ESCAPE \'\\\'', [LikeQuery::contains($value)]];
        };

        if ($serial !== null) {
            $query->whereRaw(...$match('transformers.serial', $serial));
        }
        if ($tag !== null) {
            $query->whereRaw(...$match('transformers.tag', $tag));
        }
        if ($customer !== null) {
            // Por id si viene numérico (el laboratorio ya lo guardó), por nombre
            // si no.
            $query->when(
                ctype_digit($customer),
                fn ($q) => $q->where('transformers.customer_id', (int) $customer),
                fn ($q) => $q->whereHas('customer', fn ($c) => $c->whereRaw(...$match('customers.name', $customer))),
            );
        }

        return $query->orderBy('serial')->limit(self::MAX_CANDIDATES)->get();
    }

    /**
     * El transformador al que pertenece un envío de resultados.
     *
     * Orden: slug (lo que el laboratorio guardó en `equipment.external_ref`) y,
     * si no lo tiene, la búsqueda. Cero candidatos o más de uno = 422 con el
     * detalle; NUNCA se elige por el laboratorio ni se crea al vuelo. Un
     * transformador fantasma nacido de un error de tipeo en el número de serie
     * ensucia la flota, el tablero y el histórico de tendencias del equipo real.
     */
    public function resolve(array $ref): Transformer
    {
        $slug = $this->str($ref['slug'] ?? null);

        if ($slug !== null) {
            $found = Transformer::where('slug', $slug)->first();
            if (! $found) {
                throw ValidationException::withMessages([
                    'transformer.slug' => __('lab_api.transformer_not_found'),
                ]);
            }

            return $found;
        }

        $serial   = $this->str($ref['serial'] ?? null);
        $tag      = $this->str($ref['tag'] ?? null);
        $customer = $this->str($ref['customer'] ?? null);

        if ($serial === null && $tag === null && $customer === null) {
            throw ValidationException::withMessages([
                'transformer' => __('lab_api.transformer_required'),
            ]);
        }

        $candidates = $this->lookup($serial, $tag, $customer, exact: true);

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'transformer' => __('lab_api.transformer_not_found'),
            ]);
        }

        if ($candidates->count() > 1) {
            throw ValidationException::withMessages([
                'transformer' => __('lab_api.transformer_ambiguous', ['count' => $candidates->count()])
                    . ' ' . $candidates->map(fn ($t) => $t->slug)->implode(', '),
            ]);
        }

        return $candidates->first();
    }

    /**
     * Traduce el equipo del laboratorio a un transformador nuestro y lo crea.
     *
     * Los tres desajustes de modelo y qué se decidió (el detalle y el porqué
     * están en docs/API-LABORATORIO.md):
     *
     *  1. TIPO DE EQUIPO — el laboratorio tiene 20, este sistema diagnostica 3.
     *     Se acepta solo lo que se sabe diagnosticar y se rechaza el resto con
     *     la lista de los válidos. Aceptar un buje como "Potencia" le aplicaría
     *     el cuadro de reglas de un transformador de potencia y el índice de
     *     salud saldría de una norma que no rige para ese equipo.
     *  2. TENSIÓN — el laboratorio tiene alta/baja/terciario, aquí es un número.
     *     Se toma el MÁXIMO, porque `voltage_kv` se usa como CLASE DE TENSIÓN
     *     para elegir los umbrales fisicoquímicos del IEEE C57.106, y la clase
     *     de un equipo la define su devanado más alto.
     *  3. FASES — entero en el laboratorio, texto aquí. Traducción exacta por
     *     tabla; un valor fuera de 1/2/3 se rechaza en vez de redondear.
     */
    public function create(array $data): Transformer
    {
        $customer = $this->resolveCustomer($data);
        $substation = $this->resolveSubstation($data, $customer);

        $transformer = new Transformer();
        $transformer->forceFill([
            'serial'                 => $data['serial'],
            'tag'                    => $data['tag'],
            'customer_id'            => $customer->id,
            'customer_substation_id' => $substation->id,
            'transformer_type_id'    => $this->resolveType($data)->id,
            'oil_type_id'            => $this->resolveOil($data)->id,
            'voltage_kv'             => $this->resolveVoltage($data),
            'phases'                 => $this->resolvePhases($data),
            'power_mva'              => $data['power_mva'] ?? null,
            'manufacture_year'       => $data['manufacture_year'] ?? null,
            'paper_type'             => $data['paper_type'] ?? null,
            'created_by'             => auth()->id(),
        ]);
        $transformer->save();

        return $transformer->load(['customer:id,name', 'substation:id,name']);
    }

    /** El cliente del envío, por id o por nombre exacto. Null si no existe. */
    public function findCustomer(array $data): ?Customer
    {
        return isset($data['customer_id'])
            ? Customer::find($data['customer_id'])
            : Customer::whereRaw('lower(name) = ?', [mb_strtolower((string) ($data['customer'] ?? ''))])->first();
    }

    private function resolveCustomer(array $data): Customer
    {
        $customer = $this->findCustomer($data);

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => __('lab_api.customer_required'),
            ]);
        }

        return $customer;
    }

    /**
     * Subestación del equipo.
     *
     * `transformers.customer_substation_id` es obligatorio en el formulario web
     * y no hay API de jerarquía (sedes → áreas → subestaciones), así que el
     * laboratorio no tiene de dónde sacar el id. Se resuelve sin abrir un módulo
     * nuevo: se acepta el id o el NOMBRE dentro del cliente, y cuando no se
     * puede resolver, el 422 devuelve `available_substations` con las del
     * cliente. La lista llega exactamente donde hace falta —la bandeja de
     * conciliación, donde hay una persona— en vez de exponer la jerarquía
     * entera.
     *
     * Lo que NO se hace: fabricar una subestación "-" como hacía el sistema
     * viejo. Ese placeholder es el que dejó la jerarquía llena de nodos vacíos.
     */
    private function resolveSubstation(array $data, Customer $customer): CustomerSubstation
    {
        $available = CustomerSubstation::whereHas(
            'area',
            fn ($q) => $q->whereHas('location', fn ($l) => $l->where('customer_id', $customer->id)),
        )->get(['id', 'name']);

        if (! empty($data['customer_substation_id'])) {
            $found = $available->firstWhere('id', (int) $data['customer_substation_id']);
            if ($found) {
                return CustomerSubstation::findOrFail($found->id);
            }
        }

        $name = $this->str($data['substation'] ?? null);
        if ($name !== null) {
            $found = $available->first(fn ($s) => mb_strtolower($s->name) === mb_strtolower($name));
            if ($found) {
                return CustomerSubstation::findOrFail($found->id);
            }

            throw ValidationException::withMessages([
                'substation' => __('lab_api.substation_unknown', ['name' => $name]),
            ])->status(422);
        }

        throw ValidationException::withMessages([
            'customer_substation_id' => __('lab_api.substation_required'),
        ]);
    }

    private function resolveType(array $data): TransformerType
    {
        $code = mb_strtolower($this->str($data['transformer_type'] ?? $data['equipment_type'] ?? '') ?? '');
        $type = TransformerType::where('code', $code)->first();

        if (! $type) {
            throw ValidationException::withMessages([
                'transformer_type' => __('lab_api.unknown_type', [
                    'code'    => $code === '' ? '(vacío)' : $code,
                    'allowed' => TransformerType::orderBy('sort_order')->pluck('code')->implode(', '),
                ]),
            ]);
        }

        return $type;
    }

    private function resolveOil(array $data): OilType
    {
        $code = mb_strtolower($this->str($data['oil_type'] ?? '') ?? '');
        $oil  = OilType::where('code', $code)->first();

        if (! $oil) {
            throw ValidationException::withMessages([
                'oil_type' => __('lab_api.unknown_oil', [
                    'code'    => $code === '' ? '(vacío)' : $code,
                    'allowed' => OilType::orderBy('sort_order')->pluck('code')->implode(', '),
                ]),
            ]);
        }

        return $oil;
    }

    /** El devanado más alto define la clase de tensión del equipo. */
    private function resolveVoltage(array $data): float
    {
        $candidates = array_filter([
            $data['voltage_kv'] ?? null,
            $data['voltage_kv_hv'] ?? null,
            $data['voltage_kv_lv'] ?? null,
            $data['voltage_kv_tv'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return (float) max(array_map('floatval', $candidates));
    }

    private function resolvePhases(array $data): ?string
    {
        $raw = $data['phases'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $map = config('lab_integration.phases');

        // El laboratorio manda un entero; la interfaz web manda el texto. Se
        // aceptan los dos y se rechaza cualquier otra cosa.
        if (is_numeric($raw)) {
            $mapped = $map[(int) $raw] ?? null;
        } else {
            $mapped = in_array($raw, $map, true) ? $raw : null;
        }

        if ($mapped === null) {
            throw ValidationException::withMessages([
                'phases' => __('lab_api.unknown_phases', ['value' => $raw]),
            ]);
        }

        return $mapped;
    }

    /** Subestaciones del cliente, para devolverlas junto al 422 de jerarquía. */
    public function substationsFor(?int $customerId): array
    {
        if (! $customerId) {
            return [];
        }

        return CustomerSubstation::whereHas(
            'area',
            fn ($q) => $q->whereHas('location', fn ($l) => $l->where('customer_id', $customerId)),
        )->orderBy('name')->get(['id', 'name'])->map(fn ($s) => [
            'id'   => $s->id,
            'name' => $s->name,
        ])->all();
    }

    private function str($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
