<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function findLatestHashForTenant(?Tenant $tenant): ?string
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.hash')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1);

        if (null === $tenant) {
            $qb->andWhere('a.tenant IS NULL');
        } else {
            $qb->andWhere('a.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        $hash = $qb->getQuery()->getOneOrNullResult();

        return null === $hash ? null : (string) (is_array($hash) ? ($hash['hash'] ?? '') : $hash);
    }

    /**
     * @return list<AuditLog>
     */
    public function findPageForTenant(Tenant $tenant, int $page, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countForTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
