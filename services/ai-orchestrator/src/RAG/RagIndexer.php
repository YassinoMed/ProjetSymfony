<?php

declare(strict_types=1);

namespace App\RAG;

use App\Entity\RagChunk;
use App\Entity\RagDocument;
use App\RAG\Embedding\EmbeddingProviderInterface;
use App\Repository\RagChunkRepository;
use App\Repository\RagDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Indexe un document RAG pour un tenant donné.
 *
 * Le couple (tenant, checksum) rend l'indexation idempotente : réindexer le
 * même contenu ne crée ni doublon ni fragment supplémentaire.
 */
final class RagIndexer
{
    private const int MAX_CONTENT_BYTES = 100_000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RagDocumentRepository $documents,
        private readonly RagChunkRepository $chunks,
        private readonly EmbeddingProviderInterface $embeddings,
        private readonly TextChunker $chunker,
    ) {
    }

    /**
     * @param list<string> $allowedRoles
     */
    public function index(
        Uuid $tenantId,
        string $title,
        string $source,
        string $content,
        array $allowedRoles = [],
        ?Uuid $createdBy = null,
    ): RagIndexResult {
        $title = trim($title);
        $source = trim($source);
        $content = trim($content);

        if ('' === $title || mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('Le titre RAG doit contenir entre 1 et 180 caractères.');
        }

        if ('' === $source || mb_strlen($source) > 255) {
            throw new \InvalidArgumentException('La source RAG doit contenir entre 1 et 255 caractères.');
        }

        if ('' === $content) {
            throw new \InvalidArgumentException('Le contenu RAG ne peut pas être vide.');
        }

        if (\strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new \InvalidArgumentException(sprintf('Le contenu RAG dépasse la limite de %d octets.', self::MAX_CONTENT_BYTES));
        }

        $roles = RagRoles::normalize($allowedRoles);
        $checksum = hash('sha256', implode("\n", [
            $tenantId->toRfc4122(),
            $title,
            $source,
            implode(',', $roles),
            $content,
        ]));

        return $this->entityManager->wrapInTransaction(function () use ($tenantId, $title, $source, $content, $roles, $checksum, $createdBy): RagIndexResult {
            $existing = $this->documents->findOneByTenantAndChecksum($tenantId, $checksum);
            if (null !== $existing) {
                return new RagIndexResult($existing, $this->chunks->countByDocument($existing), false);
            }

            $document = new RagDocument($tenantId, $title, $source, $content, $roles, $checksum, $createdBy);
            $this->entityManager->persist($document);

            $pieces = $this->chunker->chunk($content);
            foreach ($pieces as $ordinal => $piece) {
                $embedding = $this->embeddings->embed($piece);
                $this->entityManager->persist(new RagChunk($document, $ordinal, $piece, $embedding));
            }

            $this->entityManager->flush();

            return new RagIndexResult($document, \count($pieces), true);
        });
    }
}
