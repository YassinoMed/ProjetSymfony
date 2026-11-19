<?php

declare(strict_types=1);

namespace App\Feature;

/**
 * Champ scalaire ou tableau de primitives d'un JSON Schema de fonctionnalité.
 *
 * Les objets imbriqués, les références `$ref`, les expressions régulières et
 * les clés inconnues sont interdits par `FeatureDefinitionValidator`.
 */
final class FeatureField
{
    public const array TYPES = ['string', 'integer', 'number', 'boolean', 'array'];

    public const array FORMATS = ['date', 'email', 'uri'];

    public const array VIRTUAL_FIELDS = ['status', 'created_at', 'updated_at', 'created_by'];

    /**
     * @param list<string|int|float|bool> $enum
     * @param array<string, mixed>        $items
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $required,
        public readonly ?string $description = null,
        public readonly ?string $format = null,
        public readonly array $enum = [],
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
        public readonly array $items = [],
        public readonly ?int $minItems = null,
        public readonly ?int $maxItems = null,
        public readonly mixed $default = null,
    ) {
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function fromArray(string $name, array $spec, bool $required): self
    {
        $type = (string) ($spec['type'] ?? 'string');

        return new self(
            name: $name,
            type: $type,
            label: isset($spec['title']) && \is_string($spec['title']) && '' !== trim($spec['title'])
                ? trim($spec['title'])
                : self::defaultLabel($name),
            required: $required,
            description: isset($spec['description']) && \is_string($spec['description']) && '' !== trim($spec['description'])
                ? trim($spec['description'])
                : null,
            format: isset($spec['format']) && \is_string($spec['format']) ? $spec['format'] : null,
            enum: isset($spec['enum']) && \is_array($spec['enum']) ? array_values($spec['enum']) : [],
            minLength: isset($spec['minLength']) && \is_int($spec['minLength']) ? $spec['minLength'] : null,
            maxLength: isset($spec['maxLength']) && \is_int($spec['maxLength']) ? $spec['maxLength'] : null,
            minimum: isset($spec['minimum']) && (\is_int($spec['minimum']) || \is_float($spec['minimum'])) ? $spec['minimum'] : null,
            maximum: isset($spec['maximum']) && (\is_int($spec['maximum']) || \is_float($spec['maximum'])) ? $spec['maximum'] : null,
            items: isset($spec['items']) && \is_array($spec['items']) ? $spec['items'] : [],
            minItems: isset($spec['minItems']) && \is_int($spec['minItems']) ? $spec['minItems'] : null,
            maxItems: isset($spec['maxItems']) && \is_int($spec['maxItems']) ? $spec['maxItems'] : null,
            default: $spec['default'] ?? null,
        );
    }

    public function itemsType(): ?string
    {
        $type = $this->items['type'] ?? null;

        return \is_string($type) ? $type : null;
    }

    public function isScalar(): bool
    {
        return 'array' !== $this->type;
    }

    public function isNumeric(): bool
    {
        return \in_array($this->type, ['integer', 'number'], true);
    }

    public function isSortable(): bool
    {
        return $this->isScalar();
    }

    public function isSearchable(): bool
    {
        return 'boolean' !== $this->type && 'array' !== $this->type;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function choiceLabels(): array
    {
        $choices = [];

        foreach ($this->enum as $value) {
            if (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value)) {
                $choices[(string) $value] = \is_string($value) ? ucfirst($value) : (string) $value;
            }
        }

        return $choices;
    }

    /**
     * Schéma JSON minimal transmis à Opis : uniquement les mots-clés de
     * validation, sans annotations de présentation.
     *
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        $schema = ['type' => $this->type];

        if ('array' === $this->type) {
            if ([] !== $this->items) {
                $schema['items'] = $this->items;
            }

            if (null !== $this->minItems) {
                $schema['minItems'] = $this->minItems;
            }

            if (null !== $this->maxItems) {
                $schema['maxItems'] = $this->maxItems;
            }

            return $schema;
        }

        if (null !== $this->format && 'string' === $this->type) {
            $schema['format'] = $this->format;
        }

        if (null !== $this->minLength && 'string' === $this->type) {
            $schema['minLength'] = $this->minLength;
        }

        if (null !== $this->maxLength && 'string' === $this->type) {
            $schema['maxLength'] = $this->maxLength;
        }

        if (null !== $this->minimum && $this->isNumeric()) {
            $schema['minimum'] = $this->minimum;
        }

        if (null !== $this->maximum && $this->isNumeric()) {
            $schema['maximum'] = $this->maximum;
        }

        if ([] !== $this->enum) {
            $schema['enum'] = $this->enum;
        }

        return $schema;
    }

    private static function defaultLabel(string $name): string
    {
        $label = str_replace('_', ' ', $name);

        return ucfirst($label);
    }
}
