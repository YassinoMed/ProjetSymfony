<?php

declare(strict_types=1);

namespace App\Proposal;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Valide la spécification produite par l'Architecte.
 *
 * Le schéma est volontairement fermé (`additionalProperties: false`) : le
 * modèle ne peut pas introduire de clés que le Builder ne saurait pas traiter.
 * Les contraintes sémantiques (unicité, options de select, workflow) sont
 * vérifiées après la validation structurelle.
 */
final class ArchitectSpecificationValidator
{
    private const array FIELD_TYPES = ['string', 'integer', 'number', 'boolean', 'date', 'datetime', 'select', 'text'];

    private const array ROLES = ['owner', 'admin', 'member', 'auditor'];

    private const array RESERVED_FIELDS = ['id', 'status', 'created_at', 'updated_at', 'created_by'];

    private readonly Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator();
    }

    /**
     * @param array<string, mixed> $specification
     *
     * @return array<string, mixed>
     */
    public function validate(array $specification): array
    {
        $schema = $this->schema();
        $data = json_decode(json_encode($specification, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $schemaObject = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = $this->validator->validate($data, $schemaObject);
        if (!$result->isValid()) {
            $error = $result->error();
            $messages = null === $error ? ['Spécification invalide.'] : $this->flatten((new ErrorFormatter())->format($error));

            throw new InvalidSpecificationException($messages);
        }

        /** @var array<string, mixed> $normalized */
        $normalized = json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSemantics($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $specification
     */
    private function assertSemantics(array $specification): void
    {
        $errors = [];

        if ('new' === $specification['key']) {
            throw new InvalidSpecificationException(['key : la clé "new" est réservée par le Core.']);
        }

        $seen = [];
        foreach ($specification['fields'] as $field) {
            $name = $field['name'];

            if (isset($seen[$name])) {
                $errors[] = sprintf('fields.%s : champ dupliqué.', $name);
            }
            $seen[$name] = true;

            if (\in_array($name, self::RESERVED_FIELDS, true)) {
                $errors[] = sprintf('fields.%s : nom réservé par le moteur.', $name);
            }

            $type = $field['type'];
            $options = $field['options'] ?? null;

            if ('select' === $type && (!\is_array($options) || [] === $options)) {
                $errors[] = sprintf('fields.%s : un champ select doit définir au moins une option.', $name);
            }

            if ('select' !== $type && null !== $options) {
                $errors[] = sprintf('fields.%s : options n’est autorisé que pour le type select.', $name);
            }

            if (!\in_array($type, self::FIELD_TYPES, true)) {
                $errors[] = sprintf('fields.%s : type inconnu.', $name);
            }
        }

        $workflow = $specification['workflow'];
        $states = array_keys($workflow['states']);

        if (!\in_array($workflow['initial'], $states, true)) {
            $errors[] = 'workflow.initial : l’état initial doit exister dans states.';
        }

        foreach ($workflow['transitions'] as $index => $transition) {
            if (!\in_array($transition['from'], $states, true)) {
                $errors[] = sprintf('workflow.transitions.%d.from : état inconnu.', $index);
            }

            if (!\in_array($transition['to'], $states, true)) {
                $errors[] = sprintf('workflow.transitions.%d.to : état inconnu.', $index);
            }

            foreach ($transition['roles'] as $role) {
                if (!\in_array($role, self::ROLES, true)) {
                    $errors[] = sprintf('workflow.transitions.%d.roles : rôle inconnu %s.', $index, $role);
                }
            }
        }

        if ([] !== $errors) {
            throw new InvalidSpecificationException($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'required' => ['key', 'name', 'description', 'fields', 'workflow', 'assumptions', 'open_questions'],
            'additionalProperties' => false,
            'properties' => [
                'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,40}$'],
                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'fields' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 30,
                    'items' => [
                        'type' => 'object',
                        'required' => ['name', 'type'],
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,40}$'],
                            'type' => ['enum' => self::FIELD_TYPES],
                            'required' => ['type' => 'boolean'],
                            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                            'description' => ['type' => 'string', 'maxLength' => 200],
                            'options' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => 50,
                                'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                            ],
                        ],
                    ],
                ],
                'workflow' => [
                    'type' => 'object',
                    'required' => ['initial', 'states', 'transitions'],
                    'additionalProperties' => false,
                    'properties' => [
                        'initial' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,40}$'],
                        'states' => [
                            'type' => 'object',
                            'minProperties' => 1,
                            'maxProperties' => 12,
                            'additionalProperties' => [
                                'type' => 'object',
                                'required' => ['label'],
                                'additionalProperties' => false,
                                'properties' => [
                                    'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                                ],
                            ],
                        ],
                        'transitions' => [
                            'type' => 'array',
                            'maxItems' => 40,
                            'items' => [
                                'type' => 'object',
                                'required' => ['from', 'to', 'label', 'roles'],
                                'additionalProperties' => false,
                                'properties' => [
                                    'from' => ['type' => 'string'],
                                    'to' => ['type' => 'string'],
                                    'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                                    'roles' => [
                                        'type' => 'array',
                                        'minItems' => 1,
                                        'maxItems' => 4,
                                        'items' => ['enum' => self::ROLES],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'assumptions' => [
                    'type' => 'array',
                    'maxItems' => 10,
                    'items' => ['type' => 'string', 'maxLength' => 300],
                ],
                'open_questions' => [
                    'type' => 'array',
                    'maxItems' => 10,
                    'items' => ['type' => 'string', 'maxLength' => 300],
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $messages
     *
     * @return list<string>
     */
    private function flatten(array $messages): array
    {
        $flat = [];

        foreach ($messages as $pointer => $message) {
            if (\is_array($message)) {
                foreach ($this->flatten($message) as $nested) {
                    $flat[] = $nested;
                }
                continue;
            }

            $flat[] = (string) $pointer.' : '.(string) $message;
        }

        return [] === $flat ? ['Spécification invalide.'] : $flat;
    }
}
