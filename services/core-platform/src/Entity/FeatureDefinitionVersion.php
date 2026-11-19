<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeatureDefinitionVersionRepository;
use App\Tenant\TenantOwnedInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Version immuable d'une définition de fonctionnalité.
 *
 * Les triggers PostgreSQL interdisent toute modification ou suppression.
 * Une évolution de définition crée toujours une nouvelle ligne.
 */
#[ORM\Entity(repositoryClass: FeatureDefinitionVersionRepository::class)]
#[ORM\Table(name: 'feature_definition_versions')]
#[ORM\UniqueConstraint(name: 'uniq_feature_version', columns: ['feature_definition_id', 'version'])]
#[ORM\Index(name: 'idx_feature_version_definition', columns: ['feature_definition_id', 'version'])]
#[ORM\Index(name: 'idx_feature_version_hash', columns: ['feature_definition_id', 'hash'])]
class FeatureDefinitionVersion implements TenantOwnedInterface
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

    #[ORM\Column]
    private int $version;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'record_schema', type: Types::JSONB)]
    private array $schema;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $ui;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $workflow;

    #[ORM\Column(length: 64)]
    private string $hash;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $ui
     * @param array<string, mixed> $workflow
     */
    public function __construct(
        Tenant $tenant,
        FeatureDefinition $featureDefinition,
        int $version,
        array $schema,
        array $ui,
        array $workflow,
        string $hash,
        ?User $createdBy,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('Le numéro de version doit être supérieur ou égal à 1.');
        }

        if (1 !== preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \InvalidArgumentException('L\'empreinte de définition doit être un SHA-256 hexadécimal.');
        }

        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->featureDefinition = $featureDefinition;
        $this->version = $version;
        $this->schema = $schema;
        $this->ui = $ui;
        $this->workflow = $workflow;
        $this->hash = $hash;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUi(): array
    {
        return $this->ui;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWorkflow(): array
    {
        return $this->workflow;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array{schema: array<string, mixed>, ui: array<string, mixed>, workflow: array<string, mixed>}
     */
    public function getPayload(): array
    {
        return [
            'schema' => $this->schema,
            'ui' => $this->ui,
            'workflow' => $this->workflow,
        ];
    }

    public function getPayloadJson(): string
    {
        return json_encode(
            $this->getPayload(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
