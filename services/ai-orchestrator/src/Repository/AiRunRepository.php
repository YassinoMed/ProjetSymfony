<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiRequest;
use App\Entity\AiRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiRun>
 */
class AiRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiRun::class);
    }

    /**
     * @return list<AiRun>
     */
    public function findForRequest(AiRequest $request): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.request = :request')
            ->setParameter('request', $request)
            ->orderBy('r.startedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
