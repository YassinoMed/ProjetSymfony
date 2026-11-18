<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Enum\MembershipRole;
use App\Entity\Enum\TenantStatus;
use App\Entity\Enum\UserStatus;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\TenantMembershipRepository;
use App\Security\TenantVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class TenantVoterTest extends TestCase
{
    public function testOwnerCanViewManageAuditAndOwn(): void
    {
        [$tenant, $user, $membership] = $this->membership(MembershipRole::Owner);
        $voter = $this->voterReturning($membership);
        $token = $this->token($user);

        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::VIEW]));
        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::MANAGE]));
        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::AUDIT]));
        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::OWNER]));
    }

    public function testMemberCanViewButNotManageOrAudit(): void
    {
        [$tenant, $user, $membership] = $this->membership(MembershipRole::Member);
        $voter = $this->voterReturning($membership);
        $token = $this->token($user);

        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::VIEW]));
        self::assertSame(TenantVoter::ACCESS_DENIED, $voter->vote($token, $tenant, [TenantVoter::MANAGE]));
        self::assertSame(TenantVoter::ACCESS_DENIED, $voter->vote($token, $tenant, [TenantVoter::AUDIT]));
        self::assertSame(TenantVoter::ACCESS_DENIED, $voter->vote($token, $tenant, [TenantVoter::OWNER]));
    }

    public function testTenantAuditorCanAudit(): void
    {
        [$tenant, $user, $membership] = $this->membership(MembershipRole::Auditor);
        $voter = $this->voterReturning($membership);
        $token = $this->token($user);

        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::AUDIT]));
        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::VIEW]));
        self::assertSame(TenantVoter::ACCESS_DENIED, $voter->vote($token, $tenant, [TenantVoter::MANAGE]));
    }

    public function testNonMemberIsDenied(): void
    {
        [$tenant, $user] = $this->membership(MembershipRole::Owner);
        $voter = $this->voterReturning(null);

        self::assertSame(TenantVoter::ACCESS_DENIED, $voter->vote($this->token($user), $tenant, [TenantVoter::VIEW]));
    }

    public function testSuspendedUserIsDenied(): void
    {
        [$tenant, $user, $membership] = $this->membership(MembershipRole::Owner);
        $user->setStatus(UserStatus::Suspended);
        $voter = $this->voterReturning($membership);

        self::assertSame(TenantVoter::ACCESS_DENIED, $voter->vote($this->token($user), $tenant, [TenantVoter::VIEW]));
    }

    public function testSuspendedTenantIsDenied(): void
    {
        [$tenant, $user, $membership] = $this->membership(MembershipRole::Owner);
        $tenant->setStatus(TenantStatus::Suspended);
        $voter = $this->voterReturning($membership);

        self::assertSame(TenantVoter::ACCESS_DENIED, $voter->vote($this->token($user), $tenant, [TenantVoter::VIEW]));
    }

    public function testPlatformAdminBypassesMembership(): void
    {
        [$tenant, $user] = $this->membership(MembershipRole::Member);
        $user->setRoles(['ROLE_PLATFORM_ADMIN']);
        $voter = $this->voterReturning(null);

        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($this->token($user), $tenant, [TenantVoter::MANAGE]));
    }

    public function testGlobalAuditorCanOnlyViewAndAudit(): void
    {
        [$tenant, $user] = $this->membership(MembershipRole::Member);
        $user->setRoles(['ROLE_AUDITOR']);
        $voter = $this->voterReturning(null);
        $token = $this->token($user);

        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::AUDIT]));
        self::assertSame(TenantVoter::ACCESS_GRANTED, $voter->vote($token, $tenant, [TenantVoter::VIEW]));
        self::assertSame(TenantVoter::ACCESS_DENIED, $voter->vote($token, $tenant, [TenantVoter::MANAGE]));
    }

    public function testUnknownAttributeAbstains(): void
    {
        [$tenant, $user, $membership] = $this->membership(MembershipRole::Owner);
        $voter = $this->voterReturning($membership);

        self::assertSame(TenantVoter::ACCESS_ABSTAIN, $voter->vote($this->token($user), $tenant, ['TENANT_DELETE']));
    }

    /**
     * @return array{Tenant, User, TenantMembership}
     */
    private function membership(MembershipRole $role): array
    {
        $tenant = new Tenant('Acme', 'acme');
        $user = new User('user@example.com');
        $membership = new TenantMembership($tenant, $user, $role);

        return [$tenant, $user, $membership];
    }

    private function voterReturning(?TenantMembership $membership): TenantVoter
    {
        $repository = $this->createStub(TenantMembershipRepository::class);
        $repository->method('findActiveForUserAndTenant')->willReturn($membership);

        return new TenantVoter($repository);
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
