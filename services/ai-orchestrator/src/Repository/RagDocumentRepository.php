<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RagDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RagDocument>
 */
class RagDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RagDocument::class);
    }

    public function findOneByTenantAndChecksum(Uuid $tenantId, string $checksum): ?RagDocument
    {
        return $this->findOneBy([
            'tenantId' => $tenantId,
            'checksum' => $checksum,
        ]);
    }

    public function countByTenant(Uuid $tenantId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
