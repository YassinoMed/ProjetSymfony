<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\TenantStatus;
use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenants')]
#[ORM\UniqueConstraint(name: 'uniq_tenants_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'], message: 'Ce slug est déjà utilisé.')]
class Tenant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Le slug doit contenir uniquement des minuscules, chiffres et tirets.')]
    private string $slug;

    #[ORM\Column(length: 20, enumType: TenantStatus::class)]
    private TenantStatus $status = TenantStatus::Active;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, TenantMembership> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: TenantMembership::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    public function __construct(string $name, string $slug)
    {
        $this->id = Uuid::v7();
        $this->name = trim($name);
        $this->slug = mb_strtolower(trim($slug));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->memberships = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);
        $this->touch();

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = mb_strtolower(trim($slug));
        $this->touch();

        return $this;
    }

    public function getStatus(): TenantStatus
    {
        return $this->status;
    }

    public function setStatus(TenantStatus $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return TenantStatus::Active === $this->status;
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
     * @return Collection<int, TenantMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
