<?php

namespace App\Support\Diagnostics;

/**
 * ConditionLabel — fuente única de la ETIQUETA visible de la calificación de
 * salud (Excelente…Crítico), indexada por RATING (0-4), editable por idioma.
 *
 * Por qué por rating y no por la palabra: el rating es la llave estable que
 * maneja el cálculo (nunca cambia). La palabra es solo presentación y se traduce
 * por idioma. Así, renombrar/agregar bandas NUNCA deja un hueco de traducción —
 * cualquier banda hereda la etiqueta de su rating. Y agregar idiomas = una clave
 * más en el dataset, sin tocar código.
 *
 * Las etiquetas viven en el dataset editable `condition_labels` (BD vía
 * RuleData, con fallback al JSON de fábrica). Si el dataset no trae el idioma
 * pedido, cae al archivo lang (`diagnostics.cond_*`) — nunca queda vacío.
 */
class ConditionLabel
{
    public const DATASET_KEY = 'condition_labels';

    /** Rating entero 0-4 → clave i18n canónica. 4 = mejor, 0 = peor. */
    private const RATING_KEY = [0 => 'muy_malo', 1 => 'malo', 2 => 'medio', 3 => 'bueno', 4 => 'muy_bueno'];

    /** Palabra canónica (código interno guardado en datos) → rating. */
    private const WORD_RATING = [
        'Muy Bueno' => 4, 'Bueno' => 3, 'Medio' => 2, 'Malo' => 1, 'Muy Malo' => 0,
    ];

    /** Clave i18n (muy_bueno…) → rating. */
    private const KEY_RATING = [
        'muy_bueno' => 4, 'bueno' => 3, 'medio' => 2, 'malo' => 1, 'muy_malo' => 0,
    ];

    /** Etiqueta visible para un rating (0-4) en el idioma pedido (o el activo). */
    public static function for(int|float|null $rating, ?string $locale = null): string
    {
        if ($rating === null) {
            return '—';
        }
        $rating = (int) $rating;
        $locale = $locale ?: app()->getLocale();

        $labels = RuleData::get(self::DATASET_KEY);
        $entry = $labels[(string) $rating] ?? $labels[$rating] ?? null;
        if (is_array($entry) && !empty($entry[$locale])) {
            return (string) $entry[$locale];
        }

        // Fallback al archivo de idioma (siempre tiene los 5 niveles).
        $key = self::RATING_KEY[$rating] ?? null;
        return $key ? __('diagnostics.cond_' . $key, [], $locale) : '—';
    }

    /**
     * Etiqueta a partir de la palabra canónica guardada en una muestra
     * (dgaf_condition, fiquis.condition…). Si la palabra es canónica la resuelve
     * por su rating (editable/traducible); si es una palabra libre desconocida,
     * la devuelve tal cual (no se puede traducir lo que no está indexado).
     */
    public static function forCondition(?string $word, ?string $locale = null): string
    {
        if ($word === null || $word === '') {
            return '—';
        }
        if (array_key_exists($word, self::WORD_RATING)) {
            return self::for(self::WORD_RATING[$word], $locale);
        }

        return $word;
    }

    /** Etiqueta a partir de la clave i18n (muy_bueno…). Para superficies que ya manejan la clave. */
    public static function forKey(?string $key, ?string $locale = null): string
    {
        if ($key === null || $key === '') {
            return '—';
        }

        return array_key_exists($key, self::KEY_RATING)
            ? self::for(self::KEY_RATING[$key], $locale)
            : __('diagnostics.cond_' . $key, [], $locale);
    }

    /** Mapa rating(0-4) → etiqueta en el idioma pedido. Para compartir al frontend. */
    public static function map(?string $locale = null): array
    {
        $out = [];
        foreach (array_keys(self::RATING_KEY) as $rating) {
            $out[$rating] = self::for($rating, $locale);
        }

        return $out;
    }

    /** Mapa clave i18n (cond_*) → etiqueta, para sobrescribir las líneas de idioma. */
    public static function i18nOverrides(?string $locale = null): array
    {
        $out = [];
        foreach (self::RATING_KEY as $rating => $key) {
            $out['cond_' . $key] = self::for($rating, $locale);
        }

        return $out;
    }
}
