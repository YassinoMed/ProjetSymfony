<?php

declare(strict_types=1);

namespace App\RAG;

use App\RAG\Embedding\EmbeddingProviderInterface;
use App\Repository\RagChunkRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Recherche RAG filtrée par tenant et par rôle AVANT similarité.
 *
 * Les fragments détectés comme tentatives d'injection sont exclus du contexte
 * fourni au modèle, mais conservés dans les constats d'audit.
 */
final class RagRetriever
{
    private const int MAX_CANDIDATES = 500;

    public function __construct(
        private readonly RagChunkRepository $chunks,
        private readonly EmbeddingProviderInterface $embeddings,
        private readonly PromptInjectionScanner $scanner,
    ) {
    }

    public function retrieve(
        Uuid $tenantId,
        string $role,
        string $query,
        int $limit = 6,
        float $minScore = 0.05,
    ): RagContext {
        $role = mb_strtolower(trim($role));
        if (!\in_array($role, RagRoles::ALLOWED, true)) {
            throw new \InvalidArgumentException(sprintf('Rôle RAG invalide : %s.', $role));
        }

        $query = trim($query);
        if ('' === $query) {
            return RagContext::empty();
        }

        $limit = max(1, min(20, $limit));
        $minScore = max(-1.0, min(1.0, $minScore));

        $candidates = $this->chunks->findCandidates($tenantId, $role, self::MAX_CANDIDATES);
        if ([] === $candidates) {
            return RagContext::empty();
        }

        $queryEmbedding = $this->embeddings->embed($query);
        $dimensions = \count($queryEmbedding);
        if (0 === $dimensions) {
            return RagContext::empty();
        }

        $retrieved = [];
        $findings = [];

        foreach ($candidates as $candidate) {
            $embedding = $this->decodeEmbedding($candidate['embedding']);
            if (null === $embedding || \count($embedding) !== $dimensions) {
                continue;
            }

            $score = VectorMath::cosine($queryEmbedding, $embedding);
            if ($score < $minScore) {
                continue;
            }

            $scan = $this->scanner->scan($candidate['content']);
            if ('high' === $scan['severity']) {
                $findings[] = [
                    'chunk_id' => $candidate['chunk_id'],
                    'patterns' => $scan['matches'],
                    'severity' => 'high',
                ];

                continue;
            }

            $retrieved[] = new RetrievedChunk(
                chunkId: $candidate['chunk_id'],
                documentId: $candidate['document_id'],
                title: $candidate['title'],
                source: $candidate['source'],
                content: $candidate['content'],
                score: $score,
            );
        }

        usort(
            $retrieved,
            static fn (RetrievedChunk $a, RetrievedChunk $b): int => $b->score <=> $a->score,
        );

        return new RagContext(\array_slice($retrieved, 0, $limit), $findings);
    }

    /**
     * @return list<float>|null
     */
    private function decodeEmbedding(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (!\is_int($value) && !\is_float($value)) {
                return null;
            }
            $vector[] = (float) $value;
        }

        return $vector;
    }
}
