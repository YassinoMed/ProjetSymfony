<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Enum\MembershipRole;
use App\Security\MembershipPolicy;
use PHPUnit\Framework\TestCase;

final class MembershipPolicyTest extends TestCase
{
    private MembershipPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new MembershipPolicy();
    }

    public function testOwnerCanAddAnyRoleIncludingOwner(): void
    {
        foreach (MembershipRole::cases() as $role) {
            self::assertTrue($this->policy->canAdd(MembershipRole::Owner, $role), $role->value);
        }
    }

    public function testAdminCannotAddOwnerButCanAddOtherRoles(): void
    {
        self::assertFalse($this->policy->canAdd(MembershipRole::Admin, MembershipRole::Owner));
        self::assertTrue($this->policy->canAdd(MembershipRole::Admin, MembershipRole::Admin));
        self::assertTrue($this->policy->canAdd(MembershipRole::Admin, MembershipRole::Member));
        self::assertTrue($this->policy->canAdd(MembershipRole::Admin, MembershipRole::Auditor));
    }

    public function testNonManagerCannotAdd(): void
    {
        self::assertFalse($this->policy->canAdd(MembershipRole::Member, MembershipRole::Member));
        self::assertFalse($this->policy->canAdd(MembershipRole::Auditor, MembershipRole::Member));
    }

    public function testAdminCannotChangeOwnerRole(): void
    {
        self::assertFalse($this->policy->canChangeRole(
            MembershipRole::Admin,
            false,
            MembershipRole::Owner,
            MembershipRole::Member,
        ));
    }

    public function testAdminCannotPromoteToOwner(): void
    {
        self::assertFalse($this->policy->canChangeRole(
            MembershipRole::Admin,
            false,
            MembershipRole::Member,
            MembershipRole::Owner,
        ));
    }

    public function testAdminCanChangeAnotherAdminRole(): void
    {
        self::assertTrue($this->policy->canChangeRole(
            MembershipRole::Admin,
            false,
            MembershipRole::Admin,
            MembershipRole::Member,
        ));
    }

    public function testOwnerCanChangeAnotherOwnerRole(): void
    {
        self::assertTrue($this->policy->canChangeRole(
            MembershipRole::Owner,
            false,
            MembershipRole::Owner,
            MembershipRole::Admin,
        ));
    }

    public function testNobodyCanChangeOwnRole(): void
    {
        self::assertFalse($this->policy->canChangeRole(
            MembershipRole::Owner,
            true,
            MembershipRole::Owner,
            MembershipRole::Admin,
        ));
    }

    public function testLastOwnerCannotBeDemoted(): void
    {
        self::assertFalse($this->policy->preservesAtLeastOneOwner(
            MembershipRole::Owner,
            MembershipRole::Admin,
            1,
        ));
    }

    public function testOwnerCanBeDemotedWhenAnotherOwnerRemains(): void
    {
        self::assertTrue($this->policy->preservesAtLeastOneOwner(
            MembershipRole::Owner,
            MembershipRole::Admin,
            2,
        ));
    }

    public function testNonOwnerDemotionIsAlwaysAllowedByOwnerRule(): void
    {
        self::assertTrue($this->policy->preservesAtLeastOneOwner(
            MembershipRole::Admin,
            MembershipRole::Member,
            1,
        ));
    }

    public function testAdminCannotRemoveOwner(): void
    {
        self::assertFalse($this->policy->canRemove(
            MembershipRole::Admin,
            false,
            MembershipRole::Owner,
            2,
        ));
    }

    public function testLastOwnerCannotBeRemoved(): void
    {
        self::assertFalse($this->policy->canRemove(
            MembershipRole::Owner,
            false,
            MembershipRole::Owner,
            1,
        ));
    }

    public function testOwnerCanRemoveOwnerWhenAnotherRemains(): void
    {
        self::assertTrue($this->policy->canRemove(
            MembershipRole::Owner,
            false,
            MembershipRole::Owner,
            2,
        ));
    }

    public function testNobodyCanRemoveSelf(): void
    {
        self::assertFalse($this->policy->canRemove(
            MembershipRole::Owner,
            true,
            MembershipRole::Owner,
            2,
        ));
    }

    public function testAdminCanRemoveMember(): void
    {
        self::assertTrue($this->policy->canRemove(
            MembershipRole::Admin,
            false,
            MembershipRole::Member,
            1,
        ));
    }

    public function testAssignableRolesByActor(): void
    {
        self::assertCount(4, $this->policy->assignableRoles(MembershipRole::Owner));
        self::assertSame(
            [MembershipRole::Admin, MembershipRole::Member, MembershipRole::Auditor],
            $this->policy->assignableRoles(MembershipRole::Admin),
        );
        self::assertSame([], $this->policy->assignableRoles(MembershipRole::Member));
        self::assertSame([], $this->policy->assignableRoles(MembershipRole::Auditor));
    }
}
