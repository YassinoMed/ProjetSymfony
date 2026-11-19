<?php

declare(strict_types=1);

namespace App\Provider;

final class ProviderResponse
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $content,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly int $latencyMs = 0,
    ) {
    }

    /**
     * Décode une réponse JSON, en tolérant les clôtures Markdown fréquentes.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $content = trim($this->content);
        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = (string) preg_replace('/\s*```$/', '', $content);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ProviderResponseException('La réponse du fournisseur n’est pas un JSON valide.', 0, $exception);
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new ProviderResponseException('La réponse du fournisseur doit être un objet JSON.');
        }

        return $decoded;
    }
}
