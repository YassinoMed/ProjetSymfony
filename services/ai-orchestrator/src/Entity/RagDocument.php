<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RagDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Document indexé pour le RAG.
 *
 * `allowedRoles` est une liste blanche de rôles ; une liste vide signifie
 * « tous les membres du tenant ». Le filtrage est appliqué en SQL avant tout
 * calcul de similarité, jamais après.
 */
#[ORM\Entity(repositoryClass: RagDocumentRepository::class)]
#[ORM\Table(name: 'rag_documents')]
#[ORM\UniqueConstraint(name: 'uniq_rag_document_checksum', columns: ['tenant_id', 'checksum'])]
#[ORM\Index(name: 'idx_rag_documents_tenant', columns: ['tenant_id', 'created_at'])]
class RagDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $source;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** @var list<string> */
    #[ORM\Column(name: 'allowed_roles', type: Types::JSONB)]
    private array $allowedRoles;

    #[ORM\Column(length: 64)]
    private string $checksum;

    #[ORM\Column(name: 'created_by', type: 'uuid', nullable: true)]
    private ?Uuid $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $allowedRoles
     */
    public function __construct(
        Uuid $tenantId,
        string $title,
        string $source,
        string $content,
        array $allowedRoles,
        string $checksum,
        ?Uuid $createdBy = null,
    ) {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->title = $title;
        $this->source = $source;
        $this->content = $content;
        $this->allowedRoles = $allowedRoles;
        $this->checksum = $checksum;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @return list<string> */
    public function getAllowedRoles(): array
    {
        return $this->allowedRoles;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getCreatedBy(): ?Uuid
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isVisibleTo(string $role): bool
    {
        return [] === $this->allowedRoles || \in_array($role, $this->allowedRoles, true);
    }
}
