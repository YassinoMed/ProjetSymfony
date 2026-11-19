<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Résultat normalisé d'une étape d'agent.
 *
 * Les données métier sont séparées des métadonnées d'exécution afin que le
 * pipeline puisse tracer le fournisseur, le modèle et les tokens sans exposer
 * ces détails dans la proposition.
 */
final class AgentResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $summary,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?int $latencyMs = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {
    }
}
