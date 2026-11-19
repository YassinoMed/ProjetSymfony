<?php

declare(strict_types=1);

namespace App\Proposal;

/**
 * Valide la définition construite par le Builder avant persistance.
 *
 * Cette validation reproduit volontairement le sous-ensemble accepté par
 * `FeatureDefinitionValidator` du Core. Le Core revalidera la définition à la
 * réception : cette barrière empêche le pipeline IA de produire une proposition
 * que le moteur métier refuserait.
 */
final class FeatureDefinitionShapeValidator
{
    private const array TYPES = ['string', 'integer', 'number', 'boolean', 'array'];

    private const array FORMATS = ['date', 'email', 'uri'];

    private const array ROLES = ['owner', 'admin', 'member', 'auditor'];

    private const array RESERVED_FIELDS = ['id', 'status', 'created_at', 'updated_at', 'created_by'];

    private const array VIRTUAL_FIELDS = ['status', 'created_at', 'updated_at', 'created_by'];

    private const int MAX_BYTES = 32768;

    private const int MAX_FIELDS = 30;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $errors = [];

        $this->assertKeys($payload, ['type', 'key', 'name', 'description', 'definition'], 'proposal', $errors);

        if ('feature_definition' !== ($payload['type'] ?? null)) {
            $errors[] = 'proposal.type : la valeur doit être "feature_definition".';
        }

        $key = $payload['key'] ?? null;
        if (!\is_string($key) || 1 !== preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key)) {
            $errors[] = 'proposal.key : clé invalide (minuscules, chiffres, underscores, 2 à 41 caractères).';
        } elseif ('new' === $key) {
            $errors[] = 'proposal.key : la clé "new" est réservée.';
        }

        $name = $payload['name'] ?? null;
        if (!\is_string($name) || mb_strlen(trim($name)) < 2 || mb_strlen(trim($name)) > 120) {
            $errors[] = 'proposal.name : le nom doit contenir entre 2 et 120 caractères.';
        }

        $description = $payload['description'] ?? null;
        if (!\is_string($description) || mb_strlen($description) > 500) {
            $errors[] = 'proposal.description : la description doit être une chaîne de 500 caractères maximum.';
        }

        $definition = $payload['definition'] ?? null;
        if (!\is_array($definition)) {
            throw new InvalidDefinitionException([...$errors, 'proposal.definition : un objet est attendu.']);
        }

        $this->assertKeys($definition, ['schema', 'ui', 'workflow'], 'definition', $errors);

        $schema = $this->validateSchema($definition['schema'] ?? null, $errors);
        $this->validateUi($definition['ui'] ?? null, $schema, $errors);
        $this->validateWorkflow($definition['workflow'] ?? null, $errors);

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors[] = 'proposal : le JSON contient des caractères invalides.';
            $encoded = '';
        }

        if ('' !== $encoded && \strlen($encoded) > self::MAX_BYTES) {
            $errors[] = sprintf('proposal : la définition dépasse %d octets.', self::MAX_BYTES);
        }

        if ([] !== $errors) {
            throw new InvalidDefinitionException($errors);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $allowed
     * @param list<string>         $errors
     */
    private function assertKeys(array $data, array $allowed, string $path, array &$errors): void
    {
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowed, true)) {
                $errors[] = sprintf('%s.%s : clé non autorisée.', $path, (string) $key);
            }
        }
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    private function validateSchema(mixed $schema, array &$errors): array
    {
        if (!\is_array($schema)) {
            $errors[] = 'definition.schema : un objet est attendu.';

            return [];
        }

        $this->assertKeys($schema, ['type', 'additionalProperties', 'required', 'properties'], 'definition.schema', $errors);

        if ('object' !== ($schema['type'] ?? null)) {
            $errors[] = 'definition.schema.type : la valeur doit être "object".';
        }

        if (false !== ($schema['additionalProperties'] ?? null)) {
            $errors[] = 'definition.schema.additionalProperties : la valeur doit être false.';
        }

        $properties = $schema['properties'] ?? null;
        if (!\is_array($properties) || [] === $properties) {
            $errors[] = 'definition.schema.properties : au moins un champ est obligatoire.';

            return [];
        }

        if (\count($properties) > self::MAX_FIELDS) {
            $errors[] = sprintf('definition.schema.properties : %d champs maximum.', self::MAX_FIELDS);
        }

        $required = $schema['required'] ?? [];
        if (!\is_array($required) || !array_is_list($required)) {
            $errors[] = 'definition.schema.required : une liste est attendue.';
            $required = [];
        }

        foreach ($required as $name) {
            if (!\is_string($name) || !\array_key_exists($name, $properties)) {
                $errors[] = sprintf('definition.schema.required : le champ "%s" est inconnu.', (string) $name);
            }
        }

        foreach ($properties as $name => $spec) {
            if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
                $errors[] = sprintf('definition.schema.properties.%s : nom de champ invalide.', (string) $name);
                continue;
            }

            if (\in_array($name, self::RESERVED_FIELDS, true)) {
                $errors[] = sprintf('definition.schema.properties.%s : nom réservé.', $name);
            }

            if (!\is_array($spec)) {
                $errors[] = sprintf('definition.schema.properties.%s : un objet est attendu.', $name);
                continue;
            }

            $this->validateField($name, $spec, $errors);
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string>         $errors
     */
    private function validateField(string $name, array $spec, array &$errors): void
    {
        $path = 'definition.schema.properties.'.$name;
        $type = $spec['type'] ?? null;

        if (!\is_string($type) || !\in_array($type, self::TYPES, true)) {
            $errors[] = sprintf('%s.type : type autorisé parmi %s.', $path, implode(', ', self::TYPES));
            $type = 'string';
        }

        $allowed = match ($type) {
            'string' => ['type', 'title', 'description', 'format', 'enum', 'minLength', 'maxLength', 'default'],
            'integer', 'number' => ['type', 'title', 'description', 'minimum', 'maximum', 'enum', 'default'],
            'boolean' => ['type', 'title', 'description', 'default'],
            'array' => ['type', 'title', 'description', 'items', 'minItems', 'maxItems', 'default'],
            default => ['type'],
        };

        $this->assertKeys($spec, $allowed, $path, $errors);

        if (isset($spec['title']) && (!\is_string($spec['title']) || mb_strlen(trim($spec['title'])) < 1 || mb_strlen(trim($spec['title'])) > 80)) {
            $errors[] = sprintf('%s.title : 1 à 80 caractères attendus.', $path);
        }

        if (isset($spec['description']) && (!\is_string($spec['description']) || mb_strlen($spec['description']) > 200)) {
            $errors[] = sprintf('%s.description : 200 caractères maximum.', $path);
        }

        if ('string' === $type && isset($spec['format']) && (!\is_string($spec['format']) || !\in_array($spec['format'], self::FORMATS, true))) {
            $errors[] = sprintf('%s.format : format autorisé parmi %s.', $path, implode(', ', self::FORMATS));
        }

        if (isset($spec['enum'])) {
            if (!\is_array($spec['enum']) || [] === $spec['enum'] || !array_is_list($spec['enum'])) {
                $errors[] = sprintf('%s.enum : une liste non vide est attendue.', $path);
            } else {
                foreach ($spec['enum'] as $value) {
                    if (!\is_scalar($value)) {
                        $errors[] = sprintf('%s.enum : chaque valeur doit être scalaire.', $path);
                    }
                }
            }
        }

        if ('array' === $type) {
            $items = $spec['items'] ?? null;
            if (!\is_array($items) || !\in_array($items['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)) {
                $errors[] = sprintf('%s.items : type primitif obligatoire.', $path);
            }
        }

        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $boundary) {
            if (isset($spec[$boundary]) && !\is_int($spec[$boundary])) {
                $errors[] = sprintf('%s.%s : un entier est attendu.', $path, $boundary);
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private function validateUi(mixed $ui, array $schema, array &$errors): void
    {
        if (!\is_array($ui)) {
            $errors[] = 'definition.ui : un objet est attendu.';

            return;
        }

        $this->assertKeys($ui, ['list_fields', 'per_page'], 'definition.ui', $errors);

        $available = array_merge(array_keys($schema), self::VIRTUAL_FIELDS);
        $listFields = $ui['list_fields'] ?? [];
        if (!\is_array($listFields) || !array_is_list($listFields)) {
            $errors[] = 'definition.ui.list_fields : une liste est attendue.';
        } else {
            foreach ($listFields as $field) {
                if (!\is_string($field) || !\in_array($field, $available, true)) {
                    $errors[] = sprintf('definition.ui.list_fields : champ inconnu "%s".', (string) $field);
                }
            }
        }

        if (isset($ui['per_page']) && (!\is_int($ui['per_page']) || $ui['per_page'] < 5 || $ui['per_page'] > 100)) {
            $errors[] = 'definition.ui.per_page : entier attendu entre 5 et 100.';
        }
    }

    /**
     * @param list<string> $errors
     */
    private function validateWorkflow(mixed $workflow, array &$errors): void
    {
        if (!\is_array($workflow)) {
            $errors[] = 'definition.workflow : un objet est attendu.';

            return;
        }

        $this->assertKeys($workflow, ['initial', 'states', 'transitions'], 'definition.workflow', $errors);

        $states = $workflow['states'] ?? null;
        if (!\is_array($states) || [] === $states || \count($states) > 12) {
            $errors[] = 'definition.workflow.states : 1 à 12 états attendus.';
            $states = [];
        }

        foreach ($states as $state => $config) {
            if (!\is_string($state) || !\is_array($config)) {
                $errors[] = 'definition.workflow.states : chaque état doit être un objet.';
                continue;
            }

            if (!\is_string($config['label'] ?? null) || '' === trim((string) $config['label']) || mb_strlen((string) $config['label']) > 80) {
                $errors[] = sprintf('definition.workflow.states.%s.label : libellé invalide.', $state);
            }
        }

        $initial = $workflow['initial'] ?? null;
        if (!\is_string($initial) || !\array_key_exists($initial, $states)) {
            $errors[] = 'definition.workflow.initial : l’état initial doit exister.';
        }

        $transitions = $workflow['transitions'] ?? [];
        if (!\is_array($transitions) || !array_is_list($transitions) || \count($transitions) > 40) {
            $errors[] = 'definition.workflow.transitions : liste de 40 transitions maximum attendue.';
            $transitions = [];
        }

        foreach ($transitions as $index => $transition) {
            if (!\is_array($transition)) {
                $errors[] = sprintf('definition.workflow.transitions.%d : objet attendu.', $index);
                continue;
            }

            foreach (['from', 'to', 'label', 'roles'] as $key) {
                if (!\array_key_exists($key, $transition)) {
                    $errors[] = sprintf('definition.workflow.transitions.%d.%s : clé obligatoire.', $index, $key);
                }
            }

            $from = $transition['from'] ?? null;
            $to = $transition['to'] ?? null;
            if (!\is_string($from) || !\array_key_exists($from, $states) || !\is_string($to) || !\array_key_exists($to, $states)) {
                $errors[] = sprintf('definition.workflow.transitions.%d : état inconnu.', $index);
            }

            $roles = $transition['roles'] ?? null;
            if (!\is_array($roles) || [] === $roles) {
                $errors[] = sprintf('definition.workflow.transitions.%d.roles : au moins un rôle est requis.', $index);
            } else {
                foreach ($roles as $role) {
                    if (!\is_string($role) || !\in_array($role, self::ROLES, true)) {
                        $errors[] = sprintf('definition.workflow.transitions.%d.roles : rôle inconnu "%s".', $index, (string) $role);
                    }
                }
            }
        }
    }
}
