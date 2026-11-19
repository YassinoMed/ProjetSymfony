<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Active/désactive le filtre Doctrine tenant pour la requête courante.
 *
 * L'abstraction permet de tester le listener sans manipuler un vrai
 * EntityManager et garantit que le filtre est toujours réinitialisé au début
 * de chaque requête (important en mode worker).
 */
class TenantFilterManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function disable(): void
    {
        $filters = $this->entityManager->getFilters();

        if ($filters->isEnabled(TenantFilter::NAME)) {
            $filters->disable(TenantFilter::NAME);
        }
    }

    public function enable(Uuid $tenantId): void
    {
        $filters = $this->entityManager->getFilters();
        $filter = $filters->enable(TenantFilter::NAME);

        if (!$filter instanceof TenantFilter) {
            throw new \LogicException(sprintf('Le filtre "%s" doit être une instance de %s.', TenantFilter::NAME, TenantFilter::class));
        }

        $filter->setTenantId($tenantId->toRfc4122());
    }
}
