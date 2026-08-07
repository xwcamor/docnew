<?php

namespace App\Support\Transformers;

use App\Support\Tz;

/**
 * Definición ÚNICA de las columnas exportables de transformadores. La usan los
 * cuatro exporters (Excel / CSV / Word / PDF) y el buildQuery (para saber qué
 * relaciones eager-load). Así no se duplican headings ni resolvers y todos
 * exportan exactamente los mismos campos del dominio.
 *
 * Cada entrada: key → [heading, value(callable), relation(?string)].
 */
final class TransformerExportColumns
{
    /**
     * @return array<string, array{heading: string, value: callable, relation: ?string}>
     */
    public static function definitions(string $tz): array
    {
        $phase = fn ($p) => $p ? __('transformers.phases_' . $p) : '';
        $paper = fn ($p) => $p ? __('transformers.paper_type_' . $p) : '';
        $dt    = fn ($d) => $d?->copy()->setTimezone($tz)->format(Tz::DATETIME_FORMAT) ?? '';

        return [
            'serial'           => ['heading' => __('transformers.serial'),           'value' => fn ($c) => $c->serial,                 'relation' => null],
            'tag'              => ['heading' => __('transformers.tag'),              'value' => fn ($c) => $c->tag,                    'relation' => null],
            'customer'         => ['heading' => __('transformers.customer'),         'value' => fn ($c) => $c->customer?->name,        'relation' => 'customer:id,name'],
            'substation'       => ['heading' => __('transformers.substation'),       'value' => fn ($c) => $c->substation?->name,      'relation' => 'substation:id,name'],
            'transformer_type' => ['heading' => __('transformers.transformer_type'), 'value' => fn ($c) => $c->transformerType?->name, 'relation' => 'transformerType:id,name'],
            'oil_type'         => ['heading' => __('transformers.oil_type'),         'value' => fn ($c) => $c->oilType?->name,         'relation' => 'oilType:id,name'],
            'brand'            => ['heading' => __('transformers.brand'),            'value' => fn ($c) => $c->brand?->name,           'relation' => 'brand:id,name'],
            'connection_type'  => ['heading' => __('transformers.connection_type'),  'value' => fn ($c) => $c->connectionType?->name,  'relation' => 'connectionType:id,name'],
            'tap_changer_type' => ['heading' => __('transformers.tap_changer_type'), 'value' => fn ($c) => $c->tapChangerType?->name,  'relation' => 'tapChangerType:id,name'],
            'preservation'     => ['heading' => __('transformers.preservation'),     'value' => fn ($c) => $c->preservation?->name,    'relation' => 'preservation:id,name'],
            'voltage_kv'       => ['heading' => __('transformers.voltage_kv'),       'value' => fn ($c) => $c->voltage_kv,             'relation' => null],
            'power_mva'        => ['heading' => __('transformers.power_mva'),        'value' => fn ($c) => $c->power_mva,              'relation' => null],
            'manufacture_year' => ['heading' => __('transformers.manufacture_year'), 'value' => fn ($c) => $c->manufacture_year,       'relation' => null],
            'phases'           => ['heading' => __('transformers.phases'),           'value' => fn ($c) => $phase($c->phases),         'relation' => null],
            'paper_type'       => ['heading' => __('transformers.paper_type'),       'value' => fn ($c) => $paper($c->paper_type),     'relation' => null],
            'health_index'     => ['heading' => __('transformers.health_index'),     'value' => fn ($c) => $c->health_index,           'relation' => null],
            'health_state'     => ['heading' => __('transformers.health_state'),     'value' => fn ($c) => self::ratingLabel($c->health_rating), 'relation' => null],
            'created_at'       => ['heading' => __('global.created_at'),             'value' => fn ($c) => $dt($c->created_at),        'relation' => null],
            'updated_at'       => ['heading' => __('global.updated_at'),             'value' => fn ($c) => $dt($c->updated_at),        'relation' => null],
            'creator'          => ['heading' => __('global.created_by'),             'value' => fn ($c) => $c->creator->name ?? '—',   'relation' => 'creator:id,name'],
            // Workspace (tenant): el controller solo la habilita para super.
            'tenant'           => ['heading' => __('tenants.singular'),              'value' => fn ($c) => $c->tenant?->name ?? '—',   'relation' => 'tenant:id,name'],
        ];
    }

    /**
     * Traduce el rating de salud (0-4) a la etiqueta del idioma activo. El export
     * corre en el locale del usuario, así que el archivo sale traducido.
     */
    private static function ratingLabel(?int $rating): ?string
    {
        if ($rating === null) {
            return null;
        }
        return \App\Support\Diagnostics\ConditionLabel::for($rating);
    }

    /** Relaciones a eager-load para las columnas pedidas (sin duplicar). */
    public static function relationsFor(array $columns, string $tz = 'UTC'): array
    {
        $defs = self::definitions($tz);
        $rels = [];
        foreach ($columns as $k) {
            if (isset($defs[$k]) && $defs[$k]['relation'] !== null) {
                $rels[] = $defs[$k]['relation'];
            }
        }
        return array_values(array_unique($rels));
    }
}
