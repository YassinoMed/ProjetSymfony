<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\MembershipRole;
use App\Entity\Enum\MembershipStatus;
use App\Repository\TenantMembershipRepository;
use App\Tenant\TenantOwnedInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TenantMembershipRepository::class)]
#[ORM\Table(name: 'tenant_memberships')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_membership', columns: ['tenant_id', 'user_id'])]
#[ORM\Index(name: 'idx_membership_user', columns: ['user_id'])]
class TenantMembership implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: MembershipRole::class)]
    private MembershipRole $role;

    #[ORM\Column(length: 20, enumType: MembershipStatus::class)]
    private MembershipStatus $status = MembershipStatus::Active;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Tenant $tenant,
        User $user,
        MembershipRole $role,
        MembershipStatus $status = MembershipStatus::Active,
    ) {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->user = $user;
        $this->role = $role;
        $this->status = $status;
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

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function setRole(MembershipRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function setStatus(MembershipStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
