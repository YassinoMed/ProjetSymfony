<?php

declare(strict_types=1);

namespace App\Provider;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fournisseur local Ollama (`/api/chat`), utilisé en développement.
 *
 * Aucune donnée n'est envoyée à un tiers : l'appel reste sur le réseau local
 * ou le réseau interne Docker. Le modèle et l'URL sont configurables.
 */
final class OllamaProvider implements AiProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly bool $enabled = true,
    ) {
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function isConfigured(): bool
    {
        return $this->enabled && '' !== trim($this->baseUrl);
    }

    public function complete(ProviderRequest $request): ProviderResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/chat', [
                'headers' => ['Accept' => 'application/json'],
                'json' => [
                    'model' => $this->model,
                    'stream' => false,
                    'format' => 'json',
                    'messages' => [
                        ['role' => 'system', 'content' => $request->systemPrompt],
                        ['role' => 'user', 'content' => $request->userPrompt],
                    ],
                    'options' => [
                        'temperature' => $request->temperature,
                        'num_predict' => $request->maxTokens,
                    ],
                ],
                'timeout' => $request->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $exception) {
            throw new ProviderUnavailableException('Ollama est injoignable.', 0, $exception);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new ProviderUnavailableException(sprintf('Ollama a répondu HTTP %d.', $status));
        }

        $body = $response->getContent(false);
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new ProviderResponseException('Réponse Ollama non JSON.');
        }

        $content = $data['message']['content'] ?? null;
        if (!\is_string($content) || '' === trim($content)) {
            throw new ProviderResponseException('Réponse Ollama vide.');
        }

        return new ProviderResponse(
            provider: 'ollama',
            model: \is_string($data['model'] ?? null) ? $data['model'] : $this->model,
            content: $content,
            inputTokens: \is_int($data['prompt_eval_count'] ?? null) ? $data['prompt_eval_count'] : null,
            outputTokens: \is_int($data['eval_count'] ?? null) ? $data['eval_count'] : null,
            latencyMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }
}
