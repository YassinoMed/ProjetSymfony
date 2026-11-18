<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\MembershipStatus;
use App\Entity\Enum\TenantStatus;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantMembership>
 */
class TenantMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantMembership::class);
    }

    public function findActiveForUserAndTenant(User $user, Tenant $tenant): ?TenantMembership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('status', MembershipStatus::Active->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne les adhésions actives dont le tenant est lui-même actif.
     *
     * @return list<TenantMembership>
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('t')
            ->join('m.tenant', 't')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :membership_status')
            ->andWhere('t.status = :tenant_status')
            ->setParameter('user', $user)
            ->setParameter('membership_status', MembershipStatus::Active->value)
            ->setParameter('tenant_status', TenantStatus::Active->value)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.tenant', 't')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :membership_status')
            ->andWhere('t.status = :tenant_status')
            ->setParameter('user', $user)
            ->setParameter('membership_status', MembershipStatus::Active->value)
            ->setParameter('tenant_status', TenantStatus::Active->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
