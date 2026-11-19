<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeatureDefinition;
use App\Entity\FeatureDefinitionVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeatureDefinitionVersion>
 */
class FeatureDefinitionVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeatureDefinitionVersion::class);
    }

    public function findCurrent(FeatureDefinition $definition): ?FeatureDefinitionVersion
    {
        return $this->findOneBy([
            'featureDefinition' => $definition,
            'version' => $definition->getCurrentVersion(),
        ]);
    }

    public function findOneForDefinitionAndVersion(FeatureDefinition $definition, int $version): ?FeatureDefinitionVersion
    {
        return $this->findOneBy([
            'featureDefinition' => $definition,
            'version' => $version,
        ]);
    }

    /**
     * @return list<FeatureDefinitionVersion>
     */
    public function findAllForDefinition(FeatureDefinition $definition): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.featureDefinition = :definition')
            ->setParameter('definition', $definition)
            ->orderBy('v.version', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
