<?php

declare(strict_types=1);

namespace App\Feature;

use App\Entity\Enum\MembershipRole;

/**
 * Validation stricte et normalisation d'une définition de fonctionnalité.
 *
 * Le sous-ensemble JSON Schema accepté est volontairement limité :
 *  - objets racine uniquement, `additionalProperties: false` obligatoire ;
 *  - champs primitifs ou tableaux de primitives ;
 *  - aucun `$ref`, `pattern`, `oneOf`, `anyOf`, `allOf`, `not`, objet imbriqué ;
 *  - workflow déclaratif avec états, transitions et rôles connus.
 *
 * Cette allowlist empêche l'injection de mots-clés JSON Schema non maîtrisés
 * et garantit que la validation et le rendu restent déterministes.
 */
final class FeatureDefinitionValidator
{
    private const int MAX_FIELDS = 30;

    private const int MAX_DEFINITION_BYTES = 32768;

    private const int MAX_STATES = 12;

    private const int MAX_TRANSITIONS = 40;

    private const array RESERVED_KEYS = ['new'];

    private const array RESERVED_FIELDS = ['id', 'status', 'created_at', 'updated_at', 'created_by'];

    private const array DEFINITION_KEYS = ['schema', 'ui', 'workflow'];

    private const array SCHEMA_KEYS = ['type', 'additionalProperties', 'required', 'properties'];

    private const array STRING_KEYS = ['type', 'title', 'description', 'format', 'enum', 'minLength', 'maxLength', 'default'];

    private const array INTEGER_KEYS = ['type', 'title', 'description', 'minimum', 'maximum', 'enum', 'default'];

    private const array NUMBER_KEYS = ['type', 'title', 'description', 'minimum', 'maximum', 'enum', 'default'];

    private const array BOOLEAN_KEYS = ['type', 'title', 'description', 'default'];

    private const array ARRAY_KEYS = ['type', 'title', 'description', 'items', 'minItems', 'maxItems', 'default'];

    private const array ITEM_KEYS = ['type', 'minLength', 'maxLength', 'minimum', 'maximum', 'enum'];

    private const array UI_KEYS = ['list_fields', 'per_page'];

    private const array WORKFLOW_KEYS = ['initial', 'states', 'transitions'];

    private const array STATE_KEYS = ['label'];

    private const array TRANSITION_KEYS = ['from', 'to', 'label', 'roles'];

    public function validate(string $key, string $name, ?string $description, mixed $definition): FeatureDefinitionDraft
    {
        $errors = [];

        $key = trim($key);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key)) {
            $errors[] = 'key : la clé doit commencer par une minuscule et contenir uniquement des minuscules, chiffres et underscores (2 à 41 caractères).';
        } elseif (\in_array($key, self::RESERVED_KEYS, true)) {
            $errors[] = sprintf('key : la clé "%s" est réservée.', $key);
        }

        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors[] = 'name : le nom doit contenir entre 2 et 120 caractères.';
        }

        $description = null === $description ? null : trim($description);
        if (null !== $description && mb_strlen($description) > 500) {
            $errors[] = 'description : la description ne peut pas dépasser 500 caractères.';
        }

        if (!\is_array($definition)) {
            throw new DefinitionValidationException([...$errors, 'definition : un objet JSON est attendu.']);
        }

        try {
            $encoded = CanonicalJson::encode($definition);
        } catch (\JsonException) {
            throw new DefinitionValidationException([...$errors, 'definition : le JSON contient des caractères invalides.']);
        }

        if (\strlen($encoded) > self::MAX_DEFINITION_BYTES) {
            $errors[] = sprintf('definition : la définition dépasse la taille maximale de %d octets.', self::MAX_DEFINITION_BYTES);
        }

        $this->assertAllowedKeys($definition, self::DEFINITION_KEYS, 'definition', $errors);

        $normalizedSchema = $this->validateSchema($definition['schema'] ?? null, $errors);
        $normalizedUi = $this->validateUi($definition['ui'] ?? null, $normalizedSchema, $errors);
        $normalizedWorkflow = $this->validateWorkflow($definition['workflow'] ?? null, $errors);

        if ([] !== $errors) {
            throw new DefinitionValidationException($errors);
        }

        try {
            $schema = FeatureSchema::fromArrays($normalizedSchema, $normalizedUi);
            $workflow = new WorkflowDefinition($normalizedWorkflow);
        } catch (\InvalidArgumentException $exception) {
            throw new DefinitionValidationException([$exception->getMessage()]);
        }

        $payload = [
            'schema' => $normalizedSchema,
            'ui' => $normalizedUi,
            'workflow' => $normalizedWorkflow,
        ];

        return new FeatureDefinitionDraft(
            schema: $schema,
            workflow: $workflow,
            normalizedSchema: $normalizedSchema,
            normalizedUi: $normalizedUi,
            normalizedWorkflow: $normalizedWorkflow,
            hash: CanonicalJson::hash($payload),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $allowed
     * @param list<string>         $errors
     */
    private function assertAllowedKeys(array $data, array $allowed, string $path, array &$errors): void
    {
        foreach (array_keys($data) as $key) {
            if (!\is_string($key) || !\in_array($key, $allowed, true)) {
                $errors[] = sprintf('%s.%s : clé non autorisée.', $path, (string) $key);
            }
        }
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function validateSchema(mixed $schema, array &$errors): array
    {
        $fallback = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [],
            'properties' => [],
        ];

        if (!\is_array($schema)) {
            $errors[] = 'schema : un objet est attendu.';

            return $fallback;
        }

        $this->assertAllowedKeys($schema, self::SCHEMA_KEYS, 'schema', $errors);

        if ('object' !== ($schema['type'] ?? null)) {
            $errors[] = 'schema.type : la valeur doit être "object".';
        }

        if (false !== ($schema['additionalProperties'] ?? null)) {
            $errors[] = 'schema.additionalProperties : la valeur doit être false.';
        }

        $properties = $schema['properties'] ?? null;
        if (!\is_array($properties) || [] === $properties) {
            $errors[] = 'schema.properties : au moins un champ est obligatoire.';
            $properties = [];
        }

        if (\count($properties) > self::MAX_FIELDS) {
            $errors[] = sprintf('schema.properties : %d champs maximum.', self::MAX_FIELDS);
        }

        $requiredRaw = $schema['required'] ?? [];
        if (!\is_array($requiredRaw)) {
            $errors[] = 'schema.required : une liste de noms de champs est attendue.';
            $requiredRaw = [];
        }

        $required = [];
        foreach ($requiredRaw as $requiredField) {
            if (!\is_string($requiredField)) {
                $errors[] = 'schema.required : chaque entrée doit être une chaîne.';
                continue;
            }

            if (!\is_array($properties) || !\array_key_exists($requiredField, $properties)) {
                $errors[] = sprintf('schema.required : le champ "%s" n\'existe pas dans properties.', $requiredField);
                continue;
            }

            if (!\in_array($requiredField, $required, true)) {
                $required[] = $requiredField;
            }
        }

        sort($required, SORT_STRING);

        $normalizedProperties = [];
        foreach ($properties as $fieldName => $fieldSpec) {
            if (!\is_string($fieldName) || 1 !== preg_match('/^[a-z][a-z0-9_]{0,40}$/', $fieldName)) {
                $errors[] = sprintf('schema.properties : le nom "%s" doit commencer par une minuscule et contenir uniquement des minuscules, chiffres et underscores.', (string) $fieldName);
                continue;
            }

            if (\in_array($fieldName, self::RESERVED_FIELDS, true)) {
                $errors[] = sprintf('schema.properties.%s : ce nom est réservé au moteur.', $fieldName);
                continue;
            }

            if (!\is_array($fieldSpec)) {
                $errors[] = sprintf('schema.properties.%s : un objet de définition est attendu.', $fieldName);
                continue;
            }

            $normalizedProperties[$fieldName] = $this->validateField($fieldName, $fieldSpec, $errors);
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $normalizedProperties,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string>         $errors
     * @return array<string, mixed>
     */
    private function validateField(string $name, array $spec, array &$errors): array
    {
        $path = 'schema.properties.'.$name;
        $type = $spec['type'] ?? null;

        if (!\is_string($type) || !\in_array($type, FeatureField::TYPES, true)) {
            $errors[] = sprintf('%s.type : type autorisé parmi %s.', $path, implode(', ', FeatureField::TYPES));
            $type = 'string';
        }

        if (isset($spec['title']) && (!\is_string($spec['title']) || mb_strlen(trim($spec['title'])) < 1 || mb_strlen(trim($spec['title'])) > 80)) {
            $errors[] = sprintf('%s.title : le libellé doit contenir entre 1 et 80 caractères.', $path);
        }

        if (isset($spec['description']) && (!\is_string($spec['description']) || mb_strlen($spec['description']) > 200)) {
            $errors[] = sprintf('%s.description : la description ne peut pas dépasser 200 caractères.', $path);
        }

        $allowedKeys = match ($type) {
            'string' => self::STRING_KEYS,
            'integer' => self::INTEGER_KEYS,
            'number' => self::NUMBER_KEYS,
            'boolean' => self::BOOLEAN_KEYS,
            'array' => self::ARRAY_KEYS,
            default => self::STRING_KEYS,
        };

        $this->assertAllowedKeys($spec, $allowedKeys, $path, $errors);

        $normalized = [
            'type' => $type,
        ];

        if (isset($spec['title']) && \is_string($spec['title']) && '' !== trim($spec['title'])) {
            $normalized['title'] = trim($spec['title']);
        }

        if (isset($spec['description']) && \is_string($spec['description']) && '' !== trim($spec['description'])) {
            $normalized['description'] = trim($spec['description']);
        }

        if ('string' === $type) {
            $format = $spec['format'] ?? null;
            if (null !== $format && (!\is_string($format) || !\in_array($format, FeatureField::FORMATS, true))) {
                $errors[] = sprintf('%s.format : format autorisé parmi %s.', $path, implode(', ', FeatureField::FORMATS));
            } elseif (\is_string($format)) {
                $normalized['format'] = $format;
            }

            $this->validateBoundaries($spec, 'minLength', 'maxLength', 0, 5000, $path, $errors, $normalized, true);
            $this->validateEnum($spec, $type, $path, $errors, $normalized);

            if (\array_key_exists('default', $spec)) {
                if (!\is_string($spec['default']) || !$this->matchesEnum($spec['default'], $normalized['enum'] ?? null)) {
                    $errors[] = sprintf('%s.default : la valeur par défaut doit être une chaîne autorisée.', $path);
                } else {
                    $normalized['default'] = $spec['default'];
                }
            }
        } elseif ('integer' === $type) {
            $this->validateBoundaries($spec, 'minimum', 'maximum', null, null, $path, $errors, $normalized, true);
            $this->validateEnum($spec, $type, $path, $errors, $normalized);

            if (\array_key_exists('default', $spec)) {
                if (!\is_int($spec['default']) || !$this->withinBounds($spec['default'], $normalized) || !$this->matchesEnum($spec['default'], $normalized['enum'] ?? null)) {
                    $errors[] = sprintf('%s.default : la valeur par défaut doit être un entier autorisé.', $path);
                } else {
                    $normalized['default'] = $spec['default'];
                }
            }
        } elseif ('number' === $type) {
            $this->validateBoundaries($spec, 'minimum', 'maximum', null, null, $path, $errors, $normalized, false);
            $this->validateEnum($spec, $type, $path, $errors, $normalized);

            if (\array_key_exists('default', $spec)) {
                if ((!\is_int($spec['default']) && !\is_float($spec['default'])) || !$this->withinBounds($spec['default'], $normalized) || !$this->matchesEnum($spec['default'], $normalized['enum'] ?? null)) {
                    $errors[] = sprintf('%s.default : la valeur par défaut doit être un nombre autorisé.', $path);
                } else {
                    $normalized['default'] = $spec['default'];
                }
            }
        } elseif ('boolean' === $type) {
            if (\array_key_exists('default', $spec)) {
                if (!\is_bool($spec['default'])) {
                    $errors[] = sprintf('%s.default : la valeur par défaut doit être un booléen.', $path);
                } else {
                    $normalized['default'] = $spec['default'];
                }
            }
        } elseif ('array' === $type) {
            $normalizedItems = $this->validateItems($spec['items'] ?? null, $path, $errors);
            if ([] !== $normalizedItems) {
                $normalized['items'] = $normalizedItems;
            }

            $this->validateBoundaries($spec, 'minItems', 'maxItems', 0, 100, $path, $errors, $normalized, true);

            if (\array_key_exists('default', $spec)) {
                if (!\is_array($spec['default']) || !array_is_list($spec['default'])) {
                    $errors[] = sprintf('%s.default : la valeur par défaut doit être une liste.', $path);
                } elseif (!$this->arrayMatchesType($spec['default'], $normalizedItems)) {
                    $errors[] = sprintf('%s.default : chaque élément doit respecter le type du tableau.', $path);
                } elseif (isset($normalized['minItems']) && \count($spec['default']) < $normalized['minItems']) {
                    $errors[] = sprintf('%s.default : trop peu d\'éléments pour minItems.', $path);
                } elseif (isset($normalized['maxItems']) && \count($spec['default']) > $normalized['maxItems']) {
                    $errors[] = sprintf('%s.default : trop d\'éléments pour maxItems.', $path);
                } else {
                    $normalized['default'] = array_values($spec['default']);
                }
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string>         $errors
     * @return array<string, mixed>
     */
    private function validateItems(mixed $items, string $path, array &$errors): array
    {
        if (!\is_array($items)) {
            $errors[] = $path.'.items : un objet décrivant les éléments du tableau est obligatoire.';

            return [];
        }

        $this->assertAllowedKeys($items, self::ITEM_KEYS, $path.'.items', $errors);

        $type = $items['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, ['string', 'integer', 'number', 'boolean'], true)) {
            $errors[] = $path.'.items.type : type primitif autorisé (string, integer, number, boolean).';
            $type = 'string';
        }

        $normalized = ['type' => $type];

        if ('string' === $type) {
            $this->validateBoundaries($items, 'minLength', 'maxLength', 0, 1000, $path.'.items', $errors, $normalized, true);
            $this->validateEnum($items, $type, $path.'.items', $errors, $normalized);
        } elseif ('integer' === $type) {
            $this->validateBoundaries($items, 'minimum', 'maximum', null, null, $path.'.items', $errors, $normalized, true);
            $this->validateEnum($items, $type, $path.'.items', $errors, $normalized);
        } elseif ('number' === $type) {
            $this->validateBoundaries($items, 'minimum', 'maximum', null, null, $path.'.items', $errors, $normalized, false);
            $this->validateEnum($items, $type, $path.'.items', $errors, $normalized);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string>         $errors
     * @param array<string, mixed> $normalized
     */
    private function validateBoundaries(
        array $spec,
        string $minKey,
        string $maxKey,
        ?int $minFloor,
        ?int $maxCeiling,
        string $path,
        array &$errors,
        array &$normalized,
        bool $integerOnly = false,
    ): void {
        $min = $spec[$minKey] ?? null;
        $max = $spec[$maxKey] ?? null;

        foreach ([$minKey => $min, $maxKey => $max] as $label => $value) {
            if (null === $value) {
                continue;
            }

            $valid = $integerOnly ? \is_int($value) : (\is_int($value) || \is_float($value));
            if (!$valid) {
                $errors[] = sprintf('%s.%s : une valeur numérique est attendue.', $path, $label);
                continue;
            }

            if (null !== $minFloor && $value < $minFloor) {
                $errors[] = sprintf('%s.%s : la valeur minimale est %d.', $path, $label, $minFloor);
                continue;
            }

            if (null !== $maxCeiling && $value > $maxCeiling) {
                $errors[] = sprintf('%s.%s : la valeur maximale est %d.', $path, $label, $maxCeiling);
                continue;
            }

            $normalized[$label] = $value;
        }

        if (isset($normalized[$minKey], $normalized[$maxKey]) && $normalized[$minKey] > $normalized[$maxKey]) {
            $errors[] = sprintf('%s.%s : la borne minimale doit être inférieure ou égale à la borne maximale.', $path, $minKey);
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string>         $errors
     * @param array<string, mixed> $normalized
     */
    private function validateEnum(array $spec, string $type, string $path, array &$errors, array &$normalized): void
    {
        if (!\array_key_exists('enum', $spec)) {
            return;
        }

        if (!\is_array($spec['enum']) || !array_is_list($spec['enum']) || [] === $spec['enum'] || \count($spec['enum']) > 50) {
            $errors[] = $path.'.enum : une liste non vide de 50 valeurs maximum est attendue.';
            return;
        }

        $values = [];
        foreach ($spec['enum'] as $value) {
            $valid = match ($type) {
                'string' => \is_string($value),
                'integer' => \is_int($value),
                'number' => \is_int($value) || \is_float($value),
                default => false,
            };

            if (!$valid) {
                $errors[] = sprintf('%s.enum : chaque valeur doit respecter le type %s.', $path, $type);
                continue;
            }

            if (\in_array($value, $values, true)) {
                $errors[] = sprintf('%s.enum : la valeur %s est dupliquée.', $path, json_encode($value, JSON_THROW_ON_ERROR));
                continue;
            }

            $values[] = $value;
        }

        if (\count($values) === \count($spec['enum'])) {
            $normalized['enum'] = $values;
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function withinBounds(int|float $value, array $normalized): bool
    {
        if (isset($normalized['minimum']) && $value < $normalized['minimum']) {
            return false;
        }

        if (isset($normalized['maximum']) && $value > $normalized['maximum']) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string|int|float|bool>|null $enum
     */
    private function matchesEnum(mixed $value, ?array $enum): bool
    {
        return null === $enum || \in_array($value, $enum, true);
    }

    /**
     * @param list<mixed>          $values
     * @param array<string, mixed> $items
     */
    private function arrayMatchesType(array $values, array $items): bool
    {
        $type = $items['type'] ?? null;

        foreach ($values as $value) {
            $valid = match ($type) {
                'string' => \is_string($value),
                'integer' => \is_int($value),
                'number' => \is_int($value) || \is_float($value),
                'boolean' => \is_bool($value),
                default => false,
            };

            if (!$valid) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     * @return array<string, mixed>
     */
    private function validateUi(mixed $ui, array $schema, array &$errors): array
    {
        $properties = $schema['properties'] ?? [];
        $available = array_merge(array_keys(\is_array($properties) ? $properties : []), FeatureField::VIRTUAL_FIELDS);
        $defaultList = array_slice(array_keys(\is_array($properties) ? $properties : []), 0, 5);
        $defaultList[] = 'status';
        $defaultList[] = 'created_at';
        $defaultList = array_values(array_unique($defaultList));

        if (!\is_array($ui)) {
            $errors[] = 'ui : un objet est attendu.';

            return ['list_fields' => $defaultList, 'per_page' => 25];
        }

        $this->assertAllowedKeys($ui, self::UI_KEYS, 'ui', $errors);

        $listFields = $defaultList;
        if (\array_key_exists('list_fields', $ui)) {
            $candidate = $ui['list_fields'];
            if (!\is_array($candidate) || !array_is_list($candidate) || [] === $candidate || \count($candidate) > 8) {
                $errors[] = 'ui.list_fields : une liste non vide de 8 champs maximum est attendue.';
            } else {
                $selected = [];
                foreach ($candidate as $field) {
                    if (!\is_string($field)) {
                        $errors[] = 'ui.list_fields : chaque entrée doit être une chaîne.';
                        continue;
                    }

                    if (!\in_array($field, $available, true)) {
                        $errors[] = sprintf('ui.list_fields : le champ "%s" n\'existe pas.', $field);
                        continue;
                    }

                    if (\in_array($field, $selected, true)) {
                        $errors[] = sprintf('ui.list_fields : le champ "%s" est dupliqué.', $field);
                        continue;
                    }

                    $selected[] = $field;
                }

                if ([] !== $selected) {
                    $listFields = $selected;
                }
            }
        }

        $perPage = 25;
        if (\array_key_exists('per_page', $ui)) {
            if (!\is_int($ui['per_page']) || $ui['per_page'] < 5 || $ui['per_page'] > 100) {
                $errors[] = 'ui.per_page : un entier entre 5 et 100 est attendu.';
            } else {
                $perPage = $ui['per_page'];
            }
        }

        return ['list_fields' => $listFields, 'per_page' => $perPage];
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function validateWorkflow(mixed $workflow, array &$errors): array
    {
        $fallback = [
            'initial' => 'draft',
            'states' => ['draft' => ['label' => 'Brouillon']],
            'transitions' => [],
        ];

        if (!\is_array($workflow)) {
            $errors[] = 'workflow : un objet est attendu.';

            return $fallback;
        }

        $this->assertAllowedKeys($workflow, self::WORKFLOW_KEYS, 'workflow', $errors);

        $statesRaw = $workflow['states'] ?? null;
        if (!\is_array($statesRaw) || [] === $statesRaw) {
            $errors[] = 'workflow.states : au moins un état est obligatoire.';
            $statesRaw = [];
        }

        if (\count($statesRaw) > self::MAX_STATES) {
            $errors[] = sprintf('workflow.states : %d états maximum.', self::MAX_STATES);
        }

        $states = [];
        foreach ($statesRaw as $state => $config) {
            if (!\is_string($state) || 1 !== preg_match('/^[a-z][a-z0-9_]{0,24}$/', $state)) {
                $errors[] = sprintf('workflow.states : l\'état "%s" doit commencer par une minuscule et contenir uniquement des minuscules, chiffres et underscores.', (string) $state);
                continue;
            }

            if (!\is_array($config)) {
                $errors[] = sprintf('workflow.states.%s : un objet est attendu.', $state);
                continue;
            }

            $this->assertAllowedKeys($config, self::STATE_KEYS, 'workflow.states.'.$state, $errors);

            $label = $config['label'] ?? null;
            if (!\is_string($label) || mb_strlen(trim($label)) < 1 || mb_strlen(trim($label)) > 60) {
                $errors[] = sprintf('workflow.states.%s.label : un libellé de 1 à 60 caractères est attendu.', $state);
                continue;
            }

            $states[$state] = ['label' => trim($label)];
        }

        $initial = $workflow['initial'] ?? null;
        if (!\is_string($initial) || !isset($states[$initial])) {
            $errors[] = 'workflow.initial : l\'état initial doit exister dans states.';
            $initial = array_key_first($states) ?? 'draft';
        }

        $transitionsRaw = $workflow['transitions'] ?? [];
        if (!\is_array($transitionsRaw) || !array_is_list($transitionsRaw)) {
            $errors[] = 'workflow.transitions : une liste est attendue.';
            $transitionsRaw = [];
        }

        if (\count($transitionsRaw) > self::MAX_TRANSITIONS) {
            $errors[] = sprintf('workflow.transitions : %d transitions maximum.', self::MAX_TRANSITIONS);
        }

        $transitions = [];
        $seen = [];
        foreach ($transitionsRaw as $index => $transition) {
            $path = 'workflow.transitions.'.$index;

            if (!\is_array($transition)) {
                $errors[] = $path.' : un objet est attendu.';
                continue;
            }

            $this->assertAllowedKeys($transition, self::TRANSITION_KEYS, $path, $errors);

            $from = $transition['from'] ?? null;
            $to = $transition['to'] ?? null;
            $label = $transition['label'] ?? null;
            $roles = $transition['roles'] ?? null;

            if (!\is_string($from) || !isset($states[$from])) {
                $errors[] = $path.'.from : état source inconnu.';
                continue;
            }

            if (!\is_string($to) || !isset($states[$to])) {
                $errors[] = $path.'.to : état cible inconnu.';
                continue;
            }

            if ($from === $to) {
                $errors[] = $path.' : une transition doit changer d\'état.';
                continue;
            }

            if (isset($seen[$from.'__'.$to])) {
                $errors[] = sprintf('%s : la transition "%s" → "%s" est dupliquée.', $path, $from, $to);
                continue;
            }
            $seen[$from.'__'.$to] = true;

            if (!\is_string($label) || mb_strlen(trim($label)) < 1 || mb_strlen(trim($label)) > 60) {
                $errors[] = $path.'.label : un libellé de 1 à 60 caractères est attendu.';
                continue;
            }

            if (!\is_array($roles) || !array_is_list($roles) || [] === $roles) {
                $errors[] = $path.'.roles : une liste non vide de rôles est attendue.';
                continue;
            }

            $normalizedRoles = [];
            foreach ($roles as $role) {
                if (!\is_string($role) || null === MembershipRole::tryFrom($role)) {
                    $errors[] = sprintf('%s.roles : rôle inconnu "%s".', $path, (string) $role);
                    continue;
                }

                if (\in_array($role, $normalizedRoles, true)) {
                    $errors[] = sprintf('%s.roles : le rôle "%s" est dupliqué.', $path, $role);
                    continue;
                }

                $normalizedRoles[] = $role;
            }

            if ([] === $normalizedRoles) {
                continue;
            }

            sort($normalizedRoles, SORT_STRING);

            $transitions[] = [
                'from' => $from,
                'to' => $to,
                'label' => trim($label),
                'roles' => $normalizedRoles,
            ];
        }

        if ([] !== $states && [] === $errors) {
            $reachable = [$initial => true];
            $queue = [$initial];

            while ([] !== $queue) {
                $current = array_shift($queue);
                foreach ($transitions as $transition) {
                    if ($transition['from'] === $current && !isset($reachable[$transition['to']])) {
                        $reachable[$transition['to']] = true;
                        $queue[] = $transition['to'];
                    }
                }
            }

            foreach (array_keys($states) as $state) {
                if (!isset($reachable[$state])) {
                    $errors[] = sprintf('workflow.states.%s : l\'état n\'est pas atteignable depuis l\'état initial.', $state);
                }
            }
        }

        return [
            'initial' => $initial,
            'states' => $states,
            'transitions' => $transitions,
        ];
    }
}
