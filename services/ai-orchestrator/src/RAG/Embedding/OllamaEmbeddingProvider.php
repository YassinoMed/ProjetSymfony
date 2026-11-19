<?php

declare(strict_types=1);

namespace App\RAG\Embedding;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Embeddings locaux via Ollama (`/api/embeddings`).
 *
 * Utilisé lorsque `RAG_EMBEDDING_PROVIDER=ollama`. Les embeddings produits ont
 * une dimension dépendant du modèle ; les fragments d'une autre dimension sont
 * ignorés à la recherche plutôt que comparés approximativement.
 */
final class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly bool $enabled = true,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function name(): string
    {
        return 'ollama:'.$this->model;
    }

    public function dimensions(): int
    {
        return 0;
    }

    public function isConfigured(): bool
    {
        return $this->enabled && '' !== trim($this->baseUrl) && '' !== trim($this->model);
    }

    public function embed(string $text): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Le fournisseur d’embeddings Ollama n’est pas configuré.');
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/embeddings', [
                'json' => [
                    'model' => $this->model,
                    'prompt' => $text,
                ],
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException('Ollama est injoignable pour les embeddings.', 0, $exception);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf('Ollama a répondu HTTP %d pour les embeddings.', $response->getStatusCode()));
        }

        $data = json_decode($response->getContent(false), true);
        $embedding = \is_array($data) ? ($data['embedding'] ?? null) : null;
        if (!\is_array($embedding) || [] === $embedding) {
            throw new \RuntimeException('Réponse d’embedding Ollama invalide.');
        }

        $vector = [];
        foreach ($embedding as $value) {
            if (!\is_int($value) && !\is_float($value)) {
                throw new \RuntimeException('Un vecteur d’embedding contient une valeur non numérique.');
            }
            $vector[] = (float) $value;
        }

        return $vector;
    }
}
