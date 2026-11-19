<?php

declare(strict_types=1);

namespace App\RAG\Embedding;

interface EmbeddingProviderInterface
{
    public function name(): string;

    /**
     * Nombre de dimensions, ou 0 si le fournisseur ne le connaît pas à l'avance.
     */
    public function dimensions(): int;

    /**
     * @return list<float>
     */
    public function embed(string $text): array;
}
