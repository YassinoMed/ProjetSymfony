<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantMembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Tenant>
 */
final class TenantVoter extends Voter
{
    public const string VIEW = 'TENANT_VIEW';

    public const string MANAGE = 'TENANT_MANAGE';

    public const string AUDIT = 'TENANT_AUDIT';

    public const string OWNER = 'TENANT_OWNER';

    private const array ATTRIBUTES = [self::VIEW, self::MANAGE, self::AUDIT, self::OWNER];

    public function __construct(
        private readonly TenantMembershipRepository $memberships,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Tenant && in_array($attribute, self::ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        \assert($subject instanceof Tenant);

        if (!$subject->isActive()) {
            return false;
        }

        // Rôles plateforme : administration globale et audit transverse.
        if ($user->hasRole('ROLE_PLATFORM_ADMIN')) {
            return true;
        }

        if ($user->hasRole('ROLE_AUDITOR') && \in_array($attribute, [self::VIEW, self::AUDIT], true)) {
            return true;
        }

        $membership = $this->memberships->findActiveForUserAndTenant($user, $subject);
        if (null === $membership) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::MANAGE => $membership->getRole()->canManage(),
            self::AUDIT => $membership->getRole()->canAudit(),
            self::OWNER => $membership->getRole()->isOwner(),
            default => false,
        };
    }
}
