<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 20, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Active;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, TenantMembership> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: TenantMembership::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    /**
     * @param list<string> $roles
     */
    public function __construct(string $email, string $passwordHash = '', array $roles = [])
    {
        $this->id = Uuid::v7();
        $this->email = mb_strtolower(trim($email));
        $this->password = $passwordHash;
        $this->roles = $this->normalizeRoles($roles);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->memberships = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));
        $this->touch();

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $this->normalizeRoles($roles);
        $this->touch();

        return $this;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        $this->touch();

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return UserStatus::Active === $this->status;
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

    public function addMembership(TenantMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setUser($this);
        }

        return $this;
    }

    public function eraseCredentials(): void
    {
        // Aucune donnée sensible temporaire à effacer.
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function normalizeRoles(array $roles): array
    {
        $roles = array_map(static function (string $role): string {
            $role = strtoupper(trim($role));

            if ('' === $role) {
                return '';
            }

            return str_starts_with($role, 'ROLE_') ? $role : 'ROLE_'.$role;
        }, $roles);

        return array_values(array_unique(array_filter($roles, static fn (string $role): bool => '' !== $role)));
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
