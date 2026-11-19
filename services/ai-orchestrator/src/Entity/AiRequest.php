<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\RequestStatus;
use App\Repository\AiRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Demande de génération traitée par l'orchestrateur IA.
 *
 * `tenantId` et `requestedBy` sont des identifiants opaques : l'orchestrateur
 * ne partage aucune table avec le Core et ne fait aucune jointure inter-service.
 */
#[ORM\Entity(repositoryClass: AiRequestRepository::class)]
#[ORM\Table(name: 'ai_requests')]
#[ORM\UniqueConstraint(name: 'uniq_ai_request_idempotency', columns: ['tenant_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_ai_requests_tenant_status', columns: ['tenant_id', 'status', 'created_at'])]
class AiRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(name: 'requested_by', type: 'uuid', nullable: true)]
    private ?Uuid $requestedBy;

    #[ORM\Column(type: Types::TEXT)]
    private string $prompt;

    #[ORM\Column(length: 32, enumType: RequestStatus::class)]
    private RequestStatus $status;

    /**
     * Rôle de l'acteur dans le tenant, transmis par le Core.
     *
     * Il est utilisé pour filtrer les documents RAG avant tout calcul de
     * similarité. La valeur par défaut est le rôle le moins privilégié.
     */
    #[ORM\Column(name: 'actor_role', length: 16, options: ['default' => 'member'])]
    private string $actorRole;

    #[ORM\Column(name: 'idempotency_key', length: 120)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'correlation_id', type: 'uuid')]
    private Uuid $correlationId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $tenantId,
        string $prompt,
        string $idempotencyKey,
        ?Uuid $requestedBy = null,
        ?Uuid $correlationId = null,
        string $actorRole = 'member',
    ) {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->requestedBy = $requestedBy;
        $this->prompt = $prompt;
        $this->status = RequestStatus::Queued;
        $this->actorRole = $this->normalizeActorRole($actorRole);
        $this->idempotencyKey = $idempotencyKey;
        $this->correlationId = $correlationId ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getRequestedBy(): ?Uuid
    {
        return $this->requestedBy;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getStatus(): RequestStatus
    {
        return $this->status;
    }

    public function getActorRole(): string
    {
        return $this->actorRole;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getCorrelationId(): Uuid
    {
        return $this->correlationId;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setProvider(?string $provider): void
    {
        $this->provider = null === $provider ? null : mb_substr($provider, 0, 64);
        $this->touch();
    }

    public function markAnalyzing(): void
    {
        $this->transitionTo(RequestStatus::Analyzing);
    }

    public function markBuilding(): void
    {
        $this->transitionTo(RequestStatus::Building);
    }

    public function markValidating(): void
    {
        $this->transitionTo(RequestStatus::Validating);
    }

    public function markAwaitingApproval(): void
    {
        $this->transitionTo(RequestStatus::AwaitingApproval);
        $this->error = null;
    }

    public function markApproved(): void
    {
        $this->transitionTo(RequestStatus::Approved);
    }

    public function markRejected(?string $reason = null): void
    {
        $this->transitionTo(RequestStatus::Rejected);
        $this->error = null === $reason ? null : mb_substr($reason, 0, 2000);
    }

    public function markFailed(string $error): void
    {
        $this->transitionTo(RequestStatus::Failed);
        $this->error = mb_substr($error, 0, 2000);
    }

    /**
     * Échec transitoire : la demande redevient éligible à un retry Messenger.
     */
    public function markForRetry(string $error): void
    {
        ++$this->attempts;
        $this->transitionTo(RequestStatus::Queued);
        $this->error = mb_substr($error, 0, 2000);
    }

    public function clearError(): void
    {
        $this->error = null;
        $this->touch();
    }

    private function transitionTo(RequestStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    private function normalizeActorRole(string $role): string
    {
        $role = mb_strtolower(trim($role));
        if (!\in_array($role, ['owner', 'admin', 'member', 'auditor'], true)) {
            throw new \InvalidArgumentException(sprintf('Rôle acteur invalide : %s.', $role));
        }

        return $role;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
