<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiProposal;
use App\Entity\AiRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AiProposal>
 */
class AiProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiProposal::class);
    }

    public function findForRequest(AiRequest $request): ?AiProposal
    {
        return $this->findOneBy(['request' => $request]);
    }

    public function existsForRequest(AiRequest $request): bool
    {
        return null !== $this->findForRequest($request);
    }

    public function findForTenant(Uuid $id, Uuid $tenantId): ?AiProposal
    {
        return $this->createQueryBuilder('p')
            ->join('p.request', 'r')
            ->andWhere('p.id = :id')
            ->andWhere('r.tenantId = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForTenantForUpdate(Uuid $id, Uuid $tenantId): ?AiProposal
    {
        return $this->createQueryBuilder('p')
            ->join('p.request', 'r')
            ->andWhere('p.id = :id')
            ->andWhere('r.tenantId = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
