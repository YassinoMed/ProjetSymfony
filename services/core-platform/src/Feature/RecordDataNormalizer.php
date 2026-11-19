<?php

declare(strict_types=1);

namespace App\Feature;

/**
 * Normalise les valeurs issues d'un formulaire HTML vers les types du schéma.
 *
 * Les champs facultatifs vides sont retirés. Les champs obligatoires vides sont
 * conservés sous forme absente afin que la validation les signale.
 */
final class RecordDataNormalizer
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(FeatureSchema $schema, array $input): array
    {
        $data = [];

        foreach ($schema->fields() as $name => $field) {
            if (!\array_key_exists($name, $input)) {
                if ('boolean' === $field->type && $schema->isRequired($name)) {
                    $data[$name] = false;
                }

                continue;
            }

            $value = $this->normalizeValue($field, $input[$name]);
            if (null === $value) {
                continue;
            }

            $data[$name] = $value;
        }

        return $data;
    }

    /**
     * Prépare les données d'un enregistrement pour un formulaire Symfony.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function formData(FeatureSchema $schema, array $data): array
    {
        $formData = [];

        foreach ($schema->fields() as $name => $field) {
            $value = $data[$name] ?? null;

            $formData[$name] = match ($field->type) {
                'array' => \is_array($value) ? implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value)) : '',
                'boolean' => (bool) $value,
                default => $value,
            };
        }

        return $formData;
    }

    private function normalizeValue(FeatureField $field, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        return match ($field->type) {
            'string' => $this->normalizeString($value),
            'integer' => $this->normalizeInteger($value),
            'number' => $this->normalizeNumber($value),
            'boolean' => $this->normalizeBoolean($value),
            'array' => $this->normalizeArray($field, $value),
            default => $value,
        };
    }

    private function normalizeString(mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function normalizeInteger(mixed $value): mixed
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value)) {
            $value = trim($value);

            if ('' === $value) {
                return null;
            }

            if (1 === preg_match('/^-?\d+$/', $value)) {
                return (int) $value;
            }
        }

        if (\is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }

    private function normalizeNumber(mixed $value): mixed
    {
        if (\is_int($value) || \is_float($value)) {
            return $value;
        }

        if (\is_string($value)) {
            $value = trim($value);

            if ('' === $value) {
                return null;
            }

            if (is_numeric($value)) {
                return 1 === preg_match('/^-?\d+$/', $value) ? (int) $value : (float) $value;
            }
        }

        return $value;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return 1 === $value;
        }

        if (\is_string($value)) {
            return \in_array(mb_strtolower(trim($value)), ['1', 'true', 'on', 'yes', 'oui'], true);
        }

        return (bool) $value;
    }

    private function normalizeArray(FeatureField $field, mixed $value): mixed
    {
        if (\is_string($value)) {
            $value = '' === trim($value) ? [] : array_map('trim', explode(',', $value));
        }

        if (!\is_array($value)) {
            return $value;
        }

        $values = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' === trim($item)) {
                continue;
            }

            $values[] = $this->normalizeItem($field->itemsType(), $item);
        }

        return [] === $values ? null : $values;
    }

    private function normalizeItem(?string $type, mixed $item): mixed
    {
        return match ($type) {
            'string' => \is_string($item) ? trim($item) : $item,
            'integer' => $this->normalizeInteger($item),
            'number' => $this->normalizeNumber($item),
            'boolean' => $this->normalizeBoolean($item),
            default => $item,
        };
    }
}
