<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ProposalStatus;
use App\Repository\AiProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Proposition structurée et explicable produite par le pipeline d'agents.
 *
 * La proposition n'est jamais publiée automatiquement : elle est créée en
 * attente d'approbation humaine ou rejetée par le Gouverneur. Aucun statut
 * `published` n'est défini en Phase 4.
 */
#[ORM\Entity(repositoryClass: AiProposalRepository::class)]
#[ORM\Table(name: 'ai_proposals')]
#[ORM\UniqueConstraint(name: 'uniq_ai_proposal_request', columns: ['request_id'])]
class AiProposal
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(inversedBy: null)]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    private AiRequest $request;

    #[ORM\Column(length: 32, enumType: ProposalStatus::class)]
    private ProposalStatus $status;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $payload;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $rationale;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSONB)]
    private array $risks;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSONB)]
    private array $citations;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $policy;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(name: 'agent_trace', type: Types::JSONB)]
    private array $agentTrace;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'provider_meta', type: Types::JSONB)]
    private array $providerMeta;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'decided_by', type: 'uuid', nullable: true)]
    private ?Uuid $decidedBy = null;

    #[ORM\Column(name: 'decision_reason', type: Types::TEXT, nullable: true)]
    private ?string $decisionReason = null;

    /**
     * @param array<string, mixed>       $payload
     * @param array<string, mixed>       $rationale
     * @param list<array<string, mixed>> $risks
     * @param list<array<string, mixed>> $citations
     * @param array<string, mixed>       $policy
     * @param list<array<string, mixed>> $agentTrace
     * @param array<string, mixed>       $providerMeta
     */
    public function __construct(
        AiRequest $request,
        ProposalStatus $status,
        array $payload,
        array $rationale,
        array $risks,
        array $citations,
        array $policy,
        array $agentTrace,
        array $providerMeta,
    ) {
        $this->id = Uuid::v7();
        $this->request = $request;
        $this->status = $status;
        $this->payload = $payload;
        $this->rationale = $rationale;
        $this->risks = $risks;
        $this->citations = $citations;
        $this->policy = $policy;
        $this->agentTrace = $agentTrace;
        $this->providerMeta = $providerMeta;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRequest(): AiRequest
    {
        return $this->request;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @return array<string, mixed> */
    public function getRationale(): array
    {
        return $this->rationale;
    }

    /** @return list<array<string, mixed>> */
    public function getRisks(): array
    {
        return $this->risks;
    }

    /** @return list<array<string, mixed>> */
    public function getCitations(): array
    {
        return $this->citations;
    }

    /** @return array<string, mixed> */
    public function getPolicy(): array
    {
        return $this->policy;
    }

    /** @return list<array<string, mixed>> */
    public function getAgentTrace(): array
    {
        return $this->agentTrace;
    }

    /** @return array<string, mixed> */
    public function getProviderMeta(): array
    {
        return $this->providerMeta;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?Uuid
    {
        return $this->decidedBy;
    }

    public function getDecisionReason(): ?string
    {
        return $this->decisionReason;
    }

    public function isAwaitingApproval(): bool
    {
        return ProposalStatus::AwaitingApproval === $this->status;
    }

    public function approve(Uuid $actorId, string $reason): void
    {
        $this->assertAwaitingApproval();
        $this->status = ProposalStatus::Approved;
        $this->decidedBy = $actorId;
        $this->decisionReason = mb_substr($reason, 0, 2000);
        $this->decidedAt = new \DateTimeImmutable();
    }

    public function reject(Uuid $actorId, string $reason): void
    {
        $this->assertAwaitingApproval();
        $this->status = ProposalStatus::Rejected;
        $this->decidedBy = $actorId;
        $this->decisionReason = mb_substr($reason, 0, 2000);
        $this->decidedAt = new \DateTimeImmutable();
    }

    private function assertAwaitingApproval(): void
    {
        if (!$this->isAwaitingApproval()) {
            throw new \DomainException('Seule une proposition en attente d’approbation peut être décidée.');
        }
    }
}
