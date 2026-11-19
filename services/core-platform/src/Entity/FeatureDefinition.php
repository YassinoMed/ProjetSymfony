<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeatureDefinitionRepository;
use App\Tenant\TenantOwnedInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Définition d'une fonctionnalité dynamique, propre à un tenant.
 *
 * La définition ne contient pas de code PHP : elle pointe vers une version
 * immuable (`FeatureDefinitionVersion`) qui embarque le JSON Schema, la
 * configuration d'interface et le workflow.
 */
#[ORM\Entity(repositoryClass: FeatureDefinitionRepository::class)]
#[ORM\Table(name: 'feature_definitions')]
#[ORM\UniqueConstraint(name: 'uniq_feature_def_tenant_key', columns: ['tenant_id', 'feature_key'])]
#[ORM\Index(name: 'idx_feature_def_tenant', columns: ['tenant_id', 'name'])]
class FeatureDefinition implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\Column(name: 'feature_key', length: 50)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description;

    #[ORM\Column(name: 'current_version')]
    private int $currentVersion;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Tenant $tenant, string $key, string $name, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->key = mb_strtolower(trim($key));
        $this->name = trim($name);
        $this->description = self::normalizeDescription($description);
        $this->currentVersion = 1;
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

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCurrentVersion(): int
    {
        return $this->currentVersion;
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
     * Publie une nouvelle version immuable et met à jour les métadonnées
     * de présentation de la définition.
     */
    public function publishVersion(int $version, string $name, ?string $description): static
    {
        if ($version <= $this->currentVersion) {
            throw new \InvalidArgumentException('Une nouvelle version doit être strictement supérieure à la version courante.');
        }

        $this->currentVersion = $version;
        $this->name = trim($name);
        $this->description = self::normalizeDescription($description);
        $this->touch();

        return $this;
    }

    public function updateMetadata(string $name, ?string $description): static
    {
        $this->name = trim($name);
        $this->description = self::normalizeDescription($description);
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private static function normalizeDescription(?string $description): ?string
    {
        if (null === $description) {
            return null;
        }

        $description = trim($description);

        return '' === $description ? null : $description;
    }
}
