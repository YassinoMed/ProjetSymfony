<?php

declare(strict_types=1);

namespace App\Feature;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Validation des données d'un enregistrement contre le JSON Schema courant.
 *
 * Opis valide les types, bornes, formats, énumérations et tableaux. Une passe
 * complémentaire vérifie qu'un champ obligatoire n'est ni vide ni nul.
 */
final class RecordDataValidator
{
    private readonly Validator $validator;

    public function __construct(
        private readonly RecordDataNormalizer $normalizer,
    ) {
        $this->validator = new Validator(null, max_errors: 50, stop_at_first_error: false);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(FeatureSchema $schema, array $input): array
    {
        $errors = [];

        // `additionalProperties: false` est aussi appliqué à l'entrée brute :
        // un champ inconnu est signalé, jamais silencieusement ignoré.
        foreach (array_keys($input) as $field) {
            if (!\is_string($field) || !$schema->hasField($field)) {
                $errors['_form'] = 'Des champs non autorisés ont été fournis.';
            }
        }

        $data = $this->normalizer->normalize($schema, $input);

        foreach ($schema->requiredFields() as $name) {
            $value = $data[$name] ?? null;

            if (!\array_key_exists($name, $data) || null === $value || '' === $value || [] === $value) {
                $errors[$name] = 'Ce champ est obligatoire.';
            }
        }

        $result = $this->validator->validate((object) $data, $schema->jsonSchemaObject());

        if (!$result->isValid() && null !== ($error = $result->error())) {
            $this->collectErrors($error, $schema, $errors);
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @param array<string, string> $errors
     */
    private function collectErrors(ValidationError $error, FeatureSchema $schema, array &$errors): void
    {
        $keyword = $error->keyword();

        if ('required' === $keyword) {
            $missing = $error->args()['missing'] ?? [];
            if (\is_array($missing)) {
                foreach ($missing as $field) {
                    if (\is_string($field)) {
                        $errors[$field] ??= 'Ce champ est obligatoire.';
                    }
                }
            }

            return;
        }

        if ('additionalProperties' === $keyword) {
            $errors['_form'] ??= 'Des champs non autorisés ont été fournis.';

            return;
        }

        if (\in_array($keyword, ['properties', 'items', ''], true)) {
            foreach ($error->subErrors() as $subError) {
                $this->collectErrors($subError, $schema, $errors);
            }

            return;
        }

        foreach ($error->subErrors() as $subError) {
            $this->collectErrors($subError, $schema, $errors);
        }

        $field = $this->fieldFromPath($error->data()->fullPath(), $schema);
        if (null === $field) {
            $errors['_form'] ??= 'La donnée ne respecte pas le schéma.';

            return;
        }

        $errors[$field] ??= $this->message($error);
    }

    /**
     * @param array<int|string> $path
     */
    private function fieldFromPath(array $path, FeatureSchema $schema): ?string
    {
        foreach ($path as $segment) {
            if (\is_string($segment) && $schema->hasField($segment)) {
                return $segment;
            }
        }

        return null;
    }

    private function message(ValidationError $error): string
    {
        $args = $error->args();

        return match ($error->keyword()) {
            'minLength' => sprintf('Au moins %d caractère(s) sont requis.', (int) ($args['min'] ?? 0)),
            'maxLength' => sprintf('Au plus %d caractère(s) sont autorisés.', (int) ($args['max'] ?? 0)),
            'minimum' => sprintf('La valeur minimale est %s.', (string) ($args['min'] ?? '')),
            'maximum' => sprintf('La valeur maximale est %s.', (string) ($args['max'] ?? '')),
            'minItems' => sprintf('Au moins %d élément(s) sont requis.', (int) ($args['min'] ?? 0)),
            'maxItems' => sprintf('Au plus %d élément(s) sont autorisés.', (int) ($args['max'] ?? 0)),
            'enum' => 'Valeur non autorisée.',
            'format' => match ($args['format'] ?? '') {
                'email' => 'Adresse email invalide.',
                'date' => 'Date invalide (format attendu : AAAA-MM-JJ).',
                'uri' => 'URL invalide.',
                default => 'Format invalide.',
            },
            'type' => 'Type de valeur invalide.',
            'additionalProperties' => 'Champ non autorisé.',
            default => 'Valeur invalide.',
        };
    }
}
