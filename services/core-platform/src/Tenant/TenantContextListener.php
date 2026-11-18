<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\TenantMembershipRepository;
use App\Repository\TenantRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Résout le tenant actif de la requête à partir de :
 *   1. l'en-tête X-Tenant-Id (vérifié côté serveur) ;
 *   2. la session ;
 *   3. l'unique adhésion active de l'utilisateur.
 *
 * Un en-tête explicite invalide ne retombe jamais silencieusement sur un autre
 * tenant : il produit une erreur, afin d'éviter toute confusion d'isolation.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final class TenantContextListener
{
    public const string SESSION_KEY = '_active_tenant_id';

    public const string HEADER = 'X-Tenant-Id';

    public function __construct(
        private readonly Security $security,
        private readonly TenantRepository $tenants,
        private readonly TenantMembershipRepository $memberships,
        private readonly TenantContext $context,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->context->reset();

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return;
        }

        $request = $event->getRequest();

        if (null !== ($header = $request->headers->get(self::HEADER))) {
            $this->resolveFromHeader($request, $user, $header);

            return;
        }

        $this->resolveFromSessionOrUniqueMembership($request, $user);
    }

    private function resolveFromHeader(Request $request, User $user, string $value): void
    {
        try {
            $tenantId = Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException(sprintf('L\'en-tête %s doit contenir un UUID valide.', self::HEADER));
        }

        $tenant = $this->tenants->find($tenantId);
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            throw new AccessDeniedHttpException('Tenant inconnu ou inactif.');
        }

        $membership = $this->memberships->findActiveForUserAndTenant($user, $tenant);
        if (null === $membership) {
            throw new AccessDeniedHttpException('Vous n\'êtes pas membre actif de ce tenant.');
        }

        $this->context->setTenant($membership);

        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, $tenant->getId()->toRfc4122());
        }
    }

    private function resolveFromSessionOrUniqueMembership(Request $request, User $user): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $storedId = $session?->get(self::SESSION_KEY);

        if (is_string($storedId) && '' !== $storedId) {
            $membership = $this->resolveStoredMembership($user, $storedId);
            if (null !== $membership) {
                $this->context->setTenant($membership);

                return;
            }

            $session->remove(self::SESSION_KEY);
        }

        $activeMemberships = $this->memberships->findActiveByUser($user);
        if (1 === count($activeMemberships)) {
            $this->context->setTenant($activeMemberships[0]);

            if (null !== $session) {
                $session->set(self::SESSION_KEY, $activeMemberships[0]->getTenant()->getId()->toRfc4122());
            }
        }
    }

    private function resolveStoredMembership(User $user, string $storedId): ?TenantMembership
    {
        try {
            $tenant = $this->tenants->find(Uuid::fromString($storedId));
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            return null;
        }

        return $this->memberships->findActiveForUserAndTenant($user, $tenant);
    }
}
