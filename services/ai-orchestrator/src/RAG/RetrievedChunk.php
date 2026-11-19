<?php

declare(strict_types=1);

namespace App\RAG;

/**
 * Fragment RAG retenu après filtrage tenant/rôle et calcul de similarité.
 */
final readonly class RetrievedChunk
{
    public function __construct(
        public string $chunkId,
        public string $documentId,
        public string $title,
        public string $source,
        public string $content,
        public float $score,
    ) {
    }

    /**
     * @return array{document_id: string, title: string, source: string, score: float}
     */
    public function citation(): array
    {
        return [
            'document_id' => $this->documentId,
            'title' => $this->title,
            'source' => $this->source,
            'score' => round($this->score, 4),
        ];
    }
}
