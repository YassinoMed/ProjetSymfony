<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RagChunk;
use App\Entity\RagDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RagChunk>
 */
class RagChunkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RagChunk::class);
    }

    public function countByDocument(RagDocument $document): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Candidats filtrés par tenant et par rôle AVANT tout calcul de similarité.
     *
     * @return list<array{chunk_id: string, content: string, embedding: string, document_id: string, title: string, source: string}>
     */
    public function findCandidates(Uuid $tenantId, string $role, int $limit = 500): array
    {
        $sql = <<<'SQL'
            SELECT c.id AS chunk_id,
                   c.content,
                   c.embedding::text AS embedding,
                   d.id AS document_id,
                   d.title,
                   d.source
            FROM rag_chunks c
            INNER JOIN rag_documents d ON d.id = c.document_id
            WHERE d.tenant_id = :tenant
              AND (
                    jsonb_array_length(d.allowed_roles) = 0
                    OR jsonb_exists(d.allowed_roles, :role)
                  )
            ORDER BY d.created_at DESC, c.ordinal ASC
            LIMIT :limit
            SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'tenant' => $tenantId->toRfc4122(),
                'role' => $role,
                'limit' => $limit,
            ],
            [
                'tenant' => ParameterType::STRING,
                'role' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        return $rows;
    }
}
