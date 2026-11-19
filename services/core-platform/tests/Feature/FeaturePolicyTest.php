<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Entity\Enum\MembershipRole;
use App\Feature\FeaturePolicy;
use App\Feature\WorkflowTransition;
use PHPUnit\Framework\TestCase;

final class FeaturePolicyTest extends TestCase
{
    private FeaturePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new FeaturePolicy();
    }

    public function testEveryRoleCanView(): void
    {
        foreach (MembershipRole::cases() as $role) {
            self::assertTrue($this->policy->canView($role), $role->value);
        }
    }

    public function testOnlyOwnerAndAdminCanManageDefinitions(): void
    {
        self::assertTrue($this->policy->canManageDefinitions(MembershipRole::Owner));
        self::assertTrue($this->policy->canManageDefinitions(MembershipRole::Admin));
        self::assertFalse($this->policy->canManageDefinitions(MembershipRole::Member));
        self::assertFalse($this->policy->canManageDefinitions(MembershipRole::Auditor));
    }

    public function testOwnerAdminAndMemberCanCreateAndUpdateRecords(): void
    {
        foreach ([MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member] as $role) {
            self::assertTrue($this->policy->canCreateRecord($role), $role->value);
            self::assertTrue($this->policy->canUpdateRecord($role), $role->value);
        }

        self::assertFalse($this->policy->canCreateRecord(MembershipRole::Auditor));
        self::assertFalse($this->policy->canUpdateRecord(MembershipRole::Auditor));
    }

    public function testOnlyOwnerAndAdminCanDeleteRecords(): void
    {
        self::assertTrue($this->policy->canDeleteRecord(MembershipRole::Owner));
        self::assertTrue($this->policy->canDeleteRecord(MembershipRole::Admin));
        self::assertFalse($this->policy->canDeleteRecord(MembershipRole::Member));
        self::assertFalse($this->policy->canDeleteRecord(MembershipRole::Auditor));
    }

    public function testTransitionIsLimitedToDeclaredRoles(): void
    {
        $memberTransition = new WorkflowTransition(
            from: 'draft',
            to: 'open',
            label: 'Ouvrir',
            roles: [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member],
        );
        $adminTransition = new WorkflowTransition(
            from: 'open',
            to: 'closed',
            label: 'Fermer',
            roles: [MembershipRole::Owner, MembershipRole::Admin],
        );

        self::assertTrue($this->policy->canTransition(MembershipRole::Member, $memberTransition));
        self::assertTrue($this->policy->canTransition(MembershipRole::Owner, $memberTransition));
        self::assertFalse($this->policy->canTransition(MembershipRole::Auditor, $memberTransition));

        self::assertTrue($this->policy->canTransition(MembershipRole::Admin, $adminTransition));
        self::assertFalse($this->policy->canTransition(MembershipRole::Member, $adminTransition));
        self::assertFalse($this->policy->canTransition(MembershipRole::Auditor, $adminTransition));
    }
}
