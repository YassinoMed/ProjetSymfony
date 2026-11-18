<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Tenant\TenantContext;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    public function testSetTenantExposesTenantAndMembership(): void
    {
        $tenant = new Tenant('Acme', 'acme');
        $user = new User('owner@example.com');
        $membership = new TenantMembership($tenant, $user, MembershipRole::Owner);

        $context = new TenantContext();
        self::assertFalse($context->hasTenant());
        self::assertNull($context->getTenant());
        self::assertNull($context->getMembership());
        self::assertNull($context->getTenantId());

        $context->setTenant($membership);

        self::assertTrue($context->hasTenant());
        self::assertSame($tenant, $context->getTenant());
        self::assertSame($membership, $context->getMembership());
        self::assertTrue($tenant->getId()->equals($context->getTenantId()));
    }

    public function testResetClearsContext(): void
    {
        $tenant = new Tenant('Acme', 'acme');
        $membership = new TenantMembership($tenant, new User('owner@example.com'), MembershipRole::Owner);

        $context = new TenantContext();
        $context->setTenant($membership);
        $context->reset();

        self::assertFalse($context->hasTenant());
        self::assertNull($context->getTenant());
        self::assertNull($context->getMembership());
    }
}
