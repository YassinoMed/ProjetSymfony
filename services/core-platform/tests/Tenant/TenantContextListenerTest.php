<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Entity\Enum\MembershipRole;
use App\Entity\Enum\TenantStatus;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\TenantMembershipRepository;
use App\Repository\TenantRepository;
use App\Tenant\TenantContext;
use App\Tenant\TenantContextListener;
use App\Tenant\TenantFilterManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TenantContextListenerTest extends TestCase
{
    public function testAnonymousRequestLeavesContextEmptyAndDisablesFilter(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $tenants = $this->createMock(TenantRepository::class);
        $tenants->expects(self::never())->method('find');

        $filter = $this->createMock(TenantFilterManager::class);
        $filter->expects(self::once())->method('disable');
        $filter->expects(self::never())->method('enable');

        $context = new TenantContext();
        $listener = new TenantContextListener($security, $tenants, $this->createStub(TenantMembershipRepository::class), $context, $filter);
        $listener($this->event(new Request()));

        self::assertFalse($context->hasTenant());
    }

    public function testHeaderSelectsVerifiedTenantAndEnablesFilter(): void
    {
        $tenant = new Tenant('Acme', 'acme');
        $user = new User('user@example.com');
        $membership = new TenantMembership($tenant, $user, MembershipRole::Member);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->headers->set(TenantContextListener::HEADER, $tenant->getId()->toRfc4122());

        $context = new TenantContext();
        $filter = $this->createMock(TenantFilterManager::class);
        $filter->expects(self::once())->method('disable');
        $filter->expects(self::once())->method('enable')->with($tenant->getId());

        $listener = $this->listener($user, $tenant, $membership, $context, $filter);
        $listener($this->event($request));

        self::assertSame($tenant, $context->getTenant());
        self::assertSame($membership, $context->getMembership());
        self::assertSame($tenant->getId()->toRfc4122(), $request->getSession()->get(TenantContextListener::SESSION_KEY));
    }

    public function testMalformedHeaderIsRejected(): void
    {
        $user = new User('user@example.com');
        $request = new Request();
        $request->headers->set(TenantContextListener::HEADER, 'not-a-uuid');

        $this->expectException(BadRequestHttpException::class);

        $this->listener($user, new Tenant('Acme', 'acme'), null, new TenantContext())($this->event($request));
    }

    public function testUserWithoutMembershipIsRejected(): void
    {
        $tenant = new Tenant('Acme', 'acme');
        $request = new Request();
        $request->headers->set(TenantContextListener::HEADER, $tenant->getId()->toRfc4122());

        $this->expectException(AccessDeniedHttpException::class);

        $this->listener(new User('user@example.com'), $tenant, null, new TenantContext())($this->event($request));
    }

    public function testInactiveTenantIsRejected(): void
    {
        $tenant = new Tenant('Acme', 'acme');
        $tenant->setStatus(TenantStatus::Suspended);
        $user = new User('user@example.com');
        $membership = new TenantMembership($tenant, $user, MembershipRole::Owner);

        $request = new Request();
        $request->headers->set(TenantContextListener::HEADER, $tenant->getId()->toRfc4122());

        $this->expectException(AccessDeniedHttpException::class);

        $this->listener($user, $tenant, $membership, new TenantContext())($this->event($request));
    }

    public function testUniqueMembershipIsSelectedAutomatically(): void
    {
        $tenant = new Tenant('Acme', 'acme');
        $user = new User('user@example.com');
        $membership = new TenantMembership($tenant, $user, MembershipRole::Member);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $tenants = $this->createMock(TenantRepository::class);
        $tenants->expects(self::never())->method('find');

        $memberships = $this->createStub(TenantMembershipRepository::class);
        $memberships->method('findActiveByUser')->willReturn([$membership]);

        $filter = $this->createMock(TenantFilterManager::class);
        $filter->expects(self::once())->method('disable');
        $filter->expects(self::once())->method('enable')->with($tenant->getId());

        $context = new TenantContext();
        $listener = new TenantContextListener($security, $tenants, $memberships, $context, $filter);
        $listener($this->event(new Request()));

        self::assertSame($tenant, $context->getTenant());
    }

    private function listener(
        User $user,
        Tenant $tenant,
        ?TenantMembership $membership,
        TenantContext $context,
        ?TenantFilterManager $filter = null,
    ): TenantContextListener {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('find')->willReturn($tenant);

        $memberships = $this->createStub(TenantMembershipRepository::class);
        $memberships->method('findActiveForUserAndTenant')->willReturn($membership);

        return new TenantContextListener(
            $security,
            $tenants,
            $memberships,
            $context,
            $filter ?? $this->createStub(TenantFilterManager::class),
        );
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
