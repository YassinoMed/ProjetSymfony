<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeatureDefinition;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FeatureDefinition>
 */
class FeatureDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeatureDefinition::class);
    }

    public function findOneByTenantAndKey(Tenant $tenant, string $key): ?FeatureDefinition
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'key' => mb_strtolower(trim($key)),
        ]);
    }

    /**
     * @return list<FeatureDefinition>
     */
    public function findAllByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('f.key', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Verrouille la définition pour sérialiser la publication d'une version.
     */
    public function findForUpdate(Uuid $id): ?FeatureDefinition
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
