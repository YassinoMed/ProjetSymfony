<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Repository\AuditLogRepository;
use App\Tenant\TenantOwnedInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Journal d'audit append-only.
 *
 * Chaque entrée est chaînée au sein d'un tenant : `previous_hash` référence
 * l'empreinte de l'entrée précédente et `hash` couvre l'ensemble des champs.
 * Toute modification ou suppression est refusée par un trigger PostgreSQL
 * créé dans la migration Phase 2.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'idx_audit_tenant_created', columns: ['tenant_id', 'created_at'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_id'])]
#[ORM\UniqueConstraint(name: 'uniq_audit_hash', columns: ['hash'])]
class AuditLog implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(length: 180)]
    private string $actorEmail;

    #[ORM\Column(length: 60, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(length: 20, enumType: AuditResult::class)]
    private AuditResult $result;

    #[ORM\Column(name: 'resource_type', length: 80)]
    private string $resourceType;

    #[ORM\Column(name: 'resource_id', length: 64, nullable: true)]
    private ?string $resourceId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $context;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(name: 'correlation_id', length: 64)]
    private string $correlationId;

    #[ORM\Column(name: 'previous_hash', length: 64, nullable: true)]
    private ?string $previousHash;

    #[ORM\Column(length: 64)]
    private string $hash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        ?Tenant $tenant,
        ?User $actor,
        string $actorEmail,
        AuditAction $action,
        AuditResult $result,
        string $resourceType,
        ?string $resourceId,
        array $context,
        ?string $ipAddress,
        ?string $userAgent,
        string $correlationId,
        ?string $previousHash,
    ) {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->actor = $actor;
        $this->actorEmail = mb_substr($actorEmail, 0, 180);
        $this->action = $action;
        $this->result = $result;
        $this->resourceType = mb_substr($resourceType, 0, 80);
        $this->resourceId = null === $resourceId ? null : mb_substr($resourceId, 0, 64);
        $this->context = $context;
        $this->ipAddress = null === $ipAddress ? null : mb_substr($ipAddress, 0, 45);
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->correlationId = mb_substr($correlationId, 0, 64);
        $this->previousHash = $previousHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->hash = $this->computeHash();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorEmail(): string
    {
        return $this->actorEmail;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getResult(): AuditResult
    {
        return $this->result;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getPreviousHash(): ?string
    {
        return $this->previousHash;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Recalcule l'empreinte à partir des données de l'entrée.
     *
     * Utilisé par les tests d'intégrité et par un futur outil de vérification
     * de chaîne ; la valeur stockée reste `hash`.
     */
    public function computeHash(): string
    {
        $payload = [
            'tenant' => $this->tenant?->getId()->toRfc4122() ?? '',
            'actor' => $this->actor?->getId()->toRfc4122() ?? '',
            'actor_email' => $this->actorEmail,
            'action' => $this->action->value,
            'result' => $this->result->value,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId ?? '',
            'context' => $this->canonicalize($this->context),
            'ip' => $this->ipAddress ?? '',
            'user_agent' => $this->userAgent ?? '',
            'correlation_id' => $this->correlationId,
            'previous_hash' => $this->previousHash ?? '',
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s.uP'),
        ];

        return hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
