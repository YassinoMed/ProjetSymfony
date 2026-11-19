<?php

declare(strict_types=1);

namespace App\Feature;

/**
 * Sérialisation JSON canonique et stable.
 *
 * Les clés associatives sont triées récursivement, les listes conservent leur
 * ordre. Le hash d'une définition est donc indépendant de l'ordre des clés
 * fourni par l'administrateur ou par PostgreSQL JSONB.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
