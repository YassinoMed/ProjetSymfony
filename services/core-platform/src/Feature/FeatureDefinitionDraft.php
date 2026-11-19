<?php

declare(strict_types=1);

namespace App\Feature;

/**
 * Définition validée et normalisée, prête à être publiée comme version.
 */
final class FeatureDefinitionDraft
{
    /**
     * @param array<string, mixed> $normalizedSchema
     * @param array<string, mixed> $normalizedUi
     * @param array<string, mixed> $normalizedWorkflow
     */
    public function __construct(
        public readonly FeatureSchema $schema,
        public readonly WorkflowDefinition $workflow,
        public readonly array $normalizedSchema,
        public readonly array $normalizedUi,
        public readonly array $normalizedWorkflow,
        public readonly string $hash,
    ) {
    }

    /**
     * @return array{schema: array<string, mixed>, ui: array<string, mixed>, workflow: array<string, mixed>}
     */
    public function payload(): array
    {
        return [
            'schema' => $this->normalizedSchema,
            'ui' => $this->normalizedUi,
            'workflow' => $this->normalizedWorkflow,
        ];
    }
}
