<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RagChunkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fragment indexé d'un document RAG.
 *
 * L'embedding est stocké en JSONB : le filtrage tenant/rôle est effectué en
 * SQL, puis la similarité cosinus est calculée sur un ensemble borné. Ce choix
 * évite de dépendre d'un index pgvector pour le MVP tout en gardant la
 * possibilité de migrer vers `vector` en Phase 9.
 */
#[ORM\Entity(repositoryClass: RagChunkRepository::class)]
#[ORM\Table(name: 'rag_chunks')]
#[ORM\UniqueConstraint(name: 'uniq_rag_chunk_ordinal', columns: ['document_id', 'ordinal'])]
class RagChunk
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
    private RagDocument $document;

    #[ORM\Column]
    private int $ordinal;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** @var list<float> */
    #[ORM\Column(type: Types::JSONB)]
    private array $embedding;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<float> $embedding
     */
    public function __construct(RagDocument $document, int $ordinal, string $content, array $embedding)
    {
        $this->id = Uuid::v7();
        $this->document = $document;
        $this->ordinal = $ordinal;
        $this->content = $content;
        $this->embedding = $embedding;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): RagDocument
    {
        return $this->document;
    }

    public function getOrdinal(): int
    {
        return $this->ordinal;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @return list<float> */
    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
