<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DynamicRecordRepository;
use App\Tenant\TenantOwnedInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistrement d'une fonctionnalité dynamique.
 *
 * `data` est validé contre le JSON Schema de la version courante avant toute
 * écriture. `status` est contraint par le workflow de la définition.
 */
#[ORM\Entity(repositoryClass: DynamicRecordRepository::class)]
#[ORM\Table(name: 'dynamic_records')]
#[ORM\Index(name: 'idx_dynamic_records_feature', columns: ['tenant_id', 'feature_definition_id', 'created_at'])]
#[ORM\Index(name: 'idx_dynamic_records_status', columns: ['tenant_id', 'feature_definition_id', 'status'])]
class DynamicRecord implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private FeatureDefinition $featureDefinition;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $data;

    #[ORM\Column(length: 30)]
    private string $status;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Tenant $tenant,
        FeatureDefinition $featureDefinition,
        int $schemaVersion,
        array $data,
        string $status,
        ?User $createdBy,
    ) {
        if ($schemaVersion < 1) {
            throw new \InvalidArgumentException('La version de schéma doit être supérieure ou égale à 1.');
        }

        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->featureDefinition = $featureDefinition;
        $this->schemaVersion = $schemaVersion;
        $this->data = $data;
        $this->status = $status;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getFeatureDefinition(): FeatureDefinition
    {
        return $this->featureDefinition;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateData(array $data, int $schemaVersion): static
    {
        $this->data = $data;
        $this->schemaVersion = $schemaVersion;
        $this->touch();

        return $this;
    }

    public function transitionTo(string $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function isOutdated(int $currentSchemaVersion): bool
    {
        return $this->schemaVersion !== $currentSchemaVersion;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
