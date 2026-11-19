<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiRequest;
use App\Entity\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AiRequest>
 */
class AiRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiRequest::class);
    }

    public function findForTenant(Uuid $id, Uuid $tenantId): ?AiRequest
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.id = :id')
            ->andWhere('r.tenantId = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByTenantAndIdempotencyKey(Uuid $tenantId, string $key): ?AiRequest
    {
        return $this->findOneBy([
            'tenantId' => $tenantId,
            'idempotencyKey' => $key,
        ]);
    }

    /**
     * Réserve atomiquement une demande en file.
     *
     * Le verrou pessimiste sérialise deux workers concurrents : le second
     * observe un statut différent de `queued` et s'arrête sans retraiter.
     */
    public function claim(Uuid $id): ?AiRequest
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($id): ?AiRequest {
            $request = $this->createQueryBuilder('r')
                ->andWhere('r.id = :id')
                ->setParameter('id', $id)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if (null === $request || RequestStatus::Queued !== $request->getStatus()) {
                return null;
            }

            $request->markAnalyzing();
            $this->getEntityManager()->flush();

            return $request;
        });
    }

    public function countByStatus(Uuid $tenantId, RequestStatus $status): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.tenantId = :tenant')
            ->andWhere('r.status = :status')
            ->setParameter('tenant', $tenantId)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
