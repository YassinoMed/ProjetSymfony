<?php

declare(strict_types=1);

namespace App\Agent;

use App\Proposal\FeatureDefinitionShapeValidator;

/**
 * Agent Builder : convertit une spécification validée en définition exploitable.
 *
 * Le Builder est volontairement déterministe et sans accès IA : il ne peut ni
 * inventer de champs, ni modifier le Core, ni publier. Sa seule sortie est une
 * définition conforme au sous-ensemble accepté par le moteur de fonctionnalités.
 */
final class BuilderAgent
{
    public function __construct(private readonly FeatureDefinitionShapeValidator $validator)
    {
    }

    /**
     * @param array<string, mixed> $specification
     */
    public function execute(array $specification): AgentResult
    {
        $properties = [];
        $required = [];
        $fieldNames = [];

        foreach ($specification['fields'] as $field) {
            $name = (string) $field['name'];
            $fieldNames[] = $name;

            if (true === ($field['required'] ?? false)) {
                $required[] = $name;
            }

            $properties[$name] = $this->buildField($field);
        }

        sort($required, SORT_STRING);

        $listFields = \array_slice($fieldNames, 0, 5);
        $listFields[] = 'status';
        $listFields[] = 'created_at';
        $listFields = array_values(array_unique($listFields));

        $definition = [
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => $required,
                'properties' => $properties,
            ],
            'ui' => [
                'list_fields' => $listFields,
                'per_page' => 25,
            ],
            'workflow' => [
                'initial' => $specification['workflow']['initial'],
                'states' => $specification['workflow']['states'],
                'transitions' => $specification['workflow']['transitions'],
            ],
        ];

        $payload = [
            'type' => 'feature_definition',
            'key' => $specification['key'],
            'name' => $specification['name'],
            'description' => $specification['description'],
            'definition' => $definition,
        ];

        $this->validator->validate($payload);

        return new AgentResult(
            data: ['payload' => $payload],
            summary: sprintf(
                'Définition construite et validée : %d champ(s), %d transition(s).',
                \count($properties),
                \count($definition['workflow']['transitions']),
            ),
        );
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private function buildField(array $field): array
    {
        $type = (string) $field['type'];
        $title = isset($field['title']) && \is_string($field['title']) && '' !== trim($field['title'])
            ? trim($field['title'])
            : $this->humanize((string) $field['name']);

        $spec = ['title' => mb_substr($title, 0, 80)];

        if (isset($field['description']) && \is_string($field['description']) && '' !== trim($field['description'])) {
            $spec['description'] = mb_substr(trim($field['description']), 0, 200);
        }

        return match ($type) {
            'integer' => ['type' => 'integer', ...$spec],
            'number' => ['type' => 'number', ...$spec],
            'boolean' => ['type' => 'boolean', ...$spec],
            'date' => ['type' => 'string', 'format' => 'date', ...$spec],
            'datetime' => ['type' => 'string', ...$spec],
            'select' => ['type' => 'string', ...$spec, 'enum' => array_values(array_map(
                static fn (mixed $option): string => mb_substr((string) $option, 0, 80),
                $field['options'] ?? [],
            ))],
            'text' => ['type' => 'string', ...$spec, 'maxLength' => 5000],
            default => ['type' => 'string', ...$spec, 'maxLength' => 255],
        };
    }

    private function humanize(string $name): string
    {
        $label = str_replace('_', ' ', $name);

        return mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }
}
