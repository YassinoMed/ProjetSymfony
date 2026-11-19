<?php

declare(strict_types=1);

namespace App\RAG\Embedding;

/**
 * Sélectionne le fournisseur d'embeddings sans changer le code appelant.
 *
 * `RAG_EMBEDDING_PROVIDER=deterministic` (défaut) garantit des tests et un
 * développement hors ligne. `ollama` active le modèle local si disponible.
 */
final class EmbeddingProviderSelector implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly DeterministicEmbeddingProvider $deterministic,
        private readonly OllamaEmbeddingProvider $ollama,
        private readonly string $selected,
    ) {
    }

    public function name(): string
    {
        return $this->useOllama() ? $this->ollama->name() : $this->deterministic->name();
    }

    public function dimensions(): int
    {
        return $this->useOllama() ? $this->ollama->dimensions() : $this->deterministic->dimensions();
    }

    public function embed(string $text): array
    {
        return $this->useOllama()
            ? $this->ollama->embed($text)
            : $this->deterministic->embed($text);
    }

    private function useOllama(): bool
    {
        return 'ollama' === mb_strtolower(trim($this->selected)) && $this->ollama->isConfigured();
    }
}
