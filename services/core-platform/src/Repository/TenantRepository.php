<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\TenantStatus;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function findOneBySlug(string $slug): ?Tenant
    {
        return $this->findOneBy(['slug' => mb_strtolower($slug)]);
    }

    /**
     * @return list<Tenant>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.status = :status')
            ->setParameter('status', TenantStatus::Active->value)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
