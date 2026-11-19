<?php

declare(strict_types=1);

namespace App\RAG;

use App\Entity\RagDocument;

final readonly class RagIndexResult
{
    public function __construct(
        public RagDocument $document,
        public int $chunks,
        public bool $created,
    ) {
    }
}
