<?php

declare(strict_types=1);

namespace App\Feature;

use App\Entity\FeatureDefinitionVersion;

/**
 * Vue typée du JSON Schema et de la configuration d'interface d'une version.
 *
 * Construite à partir de données déjà validées et stockées en JSONB.
 */
final class FeatureSchema
{
    private const int DEFAULT_PER_PAGE = 25;

    /**
     * @param array<string, FeatureField> $fields
     * @param list<string>                $required
     * @param list<string>                $listFields
     */
    private function __construct(
        private readonly array $fields,
        private readonly array $required,
        private readonly array $listFields,
        private readonly int $perPage,
    ) {
    }

    public static function fromVersion(FeatureDefinitionVersion $version): self
    {
        return self::fromArrays($version->getSchema(), $version->getUi());
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $ui
     */
    public static function fromArrays(array $schema, array $ui): self
    {
        $properties = $schema['properties'] ?? [];
        if (!\is_array($properties)) {
            throw new \InvalidArgumentException('Le schéma doit contenir un objet properties.');
        }

        $required = array_values(array_filter(
            \is_array($schema['required'] ?? null) ? $schema['required'] : [],
            static fn (mixed $field): bool => \is_string($field),
        ));

        $fields = [];
        foreach ($properties as $name => $spec) {
            if (!\is_string($name) || !\is_array($spec)) {
                continue;
            }

            $fields[$name] = FeatureField::fromArray($name, $spec, \in_array($name, $required, true));
        }

        $listFields = self::normalizeListFields($fields, $ui['list_fields'] ?? null);
        $perPage = \is_int($ui['per_page'] ?? null) ? $ui['per_page'] : self::DEFAULT_PER_PAGE;

        return new self($fields, $required, $listFields, max(5, min(100, $perPage)));
    }

    /**
     * @return array<string, FeatureField>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    public function getField(string $name): FeatureField
    {
        if (!isset($this->fields[$name])) {
            throw new \OutOfBoundsException(sprintf('Champ inconnu : %s.', $name));
        }

        return $this->fields[$name];
    }

    /**
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return $this->required;
    }

    public function isRequired(string $name): bool
    {
        return \in_array($name, $this->required, true);
    }

    /**
     * @return list<string>
     */
    public function listFields(): array
    {
        return $this->listFields;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function isSortable(string $name): bool
    {
        if (\in_array($name, ['status', 'created_at', 'updated_at'], true)) {
            return true;
        }

        return $this->hasField($name) && $this->fields[$name]->isSortable();
    }

    public function isSearchable(string $name): bool
    {
        return $this->hasField($name) && $this->fields[$name]->isSearchable();
    }

    /**
     * @return list<string>
     */
    public function searchableFields(): array
    {
        $fields = [];

        foreach ($this->fields as $name => $field) {
            if ($field->isSearchable()) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    public function sortableFields(): array
    {
        $fields = ['status', 'created_at', 'updated_at'];

        foreach ($this->fields as $name => $field) {
            if ($field->isSortable()) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields as $name => $field) {
            if (null !== $field->default) {
                $defaults[$name] = $field->default;
            } elseif ('boolean' === $field->type) {
                $defaults[$name] = false;
            } else {
                $defaults[$name] = null;
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        $properties = [];

        foreach ($this->fields as $name => $field) {
            $properties[$name] = $field->toJsonSchema();
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $this->required,
            'properties' => $properties,
        ];
    }

    public function jsonSchemaObject(): object
    {
        $encoded = json_encode($this->toJsonSchema(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);

        if (!$decoded instanceof \stdClass) {
            throw new \LogicException('Le schéma JSON doit être un objet.');
        }

        return $decoded;
    }

    /**
     * @param array<string, FeatureField> $fields
     * @return list<string>
     */
    private static function normalizeListFields(array $fields, mixed $candidate): array
    {
        $available = array_merge(array_keys($fields), FeatureField::VIRTUAL_FIELDS);
        $default = self::defaultListFields($fields);

        if (!\is_array($candidate) || [] === $candidate) {
            return $default;
        }

        $list = [];
        foreach ($candidate as $field) {
            if (\is_string($field) && \in_array($field, $available, true) && !\in_array($field, $list, true)) {
                $list[] = $field;
            }
        }

        return [] === $list ? $default : array_slice($list, 0, 8);
    }

    /**
     * @param array<string, FeatureField> $fields
     * @return list<string>
     */
    private static function defaultListFields(array $fields): array
    {
        $list = array_slice(array_keys($fields), 0, 5);
        $list[] = 'status';
        $list[] = 'created_at';

        return array_values(array_unique($list));
    }
}
