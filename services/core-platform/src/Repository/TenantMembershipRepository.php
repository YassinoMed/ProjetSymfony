<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\MembershipRole;
use App\Entity\Enum\MembershipStatus;
use App\Entity\Enum\TenantStatus;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    public function findOneByIdForTenant(Uuid $id, Tenant $tenant): ?TenantMembership
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.id = :id')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Toutes les adhésions du tenant, actives ou non, pour l'écran de gestion.
     *
     * @return list<TenantMembership>
     */
    public function findAllByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('m.status', 'ASC')
            ->addOrderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveOwners(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.role = :role')
            ->andWhere('m.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('role', MembershipRole::Owner->value)
            ->setParameter('status', MembershipStatus::Active->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
