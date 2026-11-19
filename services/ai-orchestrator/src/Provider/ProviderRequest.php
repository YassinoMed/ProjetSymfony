<?php

declare(strict_types=1);

namespace App\Provider;

final class ProviderRequest
{
    public function __construct(
        public readonly string $agent,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly float $temperature = 0.2,
        public readonly int $maxTokens = 1800,
        public readonly int $timeoutSeconds = 20,
    ) {
    }
}
