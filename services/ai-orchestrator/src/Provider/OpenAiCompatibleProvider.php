<?php

declare(strict_types=1);

namespace App\Provider;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fournisseur cloud compatible OpenAI (`/chat/completions`).
 *
 * Le fournisseur n'est activé que si une clé API est fournie. Le prompt envoyé
 * est minimisé : identifiants tenant/utilisateur exclus, contexte RAG borné.
 */
final class OpenAiCompatibleProvider implements AiProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly bool $enabled = true,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->enabled && '' !== trim($this->apiKey) && '' !== trim($this->baseUrl);
    }

    public function complete(ProviderRequest $request): ProviderResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/chat/completions', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->apiKey,
                ],
                'json' => [
                    'model' => $this->model,
                    'temperature' => $request->temperature,
                    'max_tokens' => $request->maxTokens,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $request->systemPrompt],
                        ['role' => 'user', 'content' => $request->userPrompt],
                    ],
                ],
                'timeout' => $request->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $exception) {
            throw new ProviderUnavailableException('Le fournisseur cloud est injoignable.', 0, $exception);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new ProviderUnavailableException(sprintf('Le fournisseur cloud a répondu HTTP %d.', $status));
        }

        $body = $response->getContent(false);
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new ProviderResponseException('Réponse cloud non JSON.');
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!\is_string($content) || '' === trim($content)) {
            throw new ProviderResponseException('Réponse cloud vide.');
        }

        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new ProviderResponse(
            provider: 'openai',
            model: \is_string($data['model'] ?? null) ? $data['model'] : $this->model,
            content: $content,
            inputTokens: \is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : null,
            outputTokens: \is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : null,
            latencyMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }
}
