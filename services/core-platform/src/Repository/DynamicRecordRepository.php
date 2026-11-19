<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DynamicRecord;
use App\Entity\FeatureDefinition;
use App\Feature\FeatureField;
use App\Feature\FeatureSchema;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DynamicRecord>
 */
class DynamicRecordRepository extends ServiceEntityRepository
{
    private const array PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DynamicRecord::class);
    }

    public function findOneForDefinition(Uuid $id, FeatureDefinition $definition): ?DynamicRecord
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.id = :id')
            ->andWhere('r.featureDefinition = :definition')
            ->setParameter('id', $id)
            ->setParameter('definition', $definition)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForDefinition(FeatureDefinition $definition): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.featureDefinition = :definition')
            ->setParameter('definition', $definition)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recherche, filtre, trie et pagine les enregistrements d'une définition.
     *
     * Les seuls fragments SQL variables sont des noms de champs validés par le
     * schéma et des directions limitées à ASC/DESC. Les valeurs (termes de
     * recherche, chemins JSONB, statut) sont toujours liées en paramètres.
     *
     * @param array{q?: string, status?: string, sort?: string, dir?: string, page?: int, per_page?: int} $criteria
     * @return array{items: list<DynamicRecord>, total: int, page: int, per_page: int, page_count: int}
     */
    public function search(FeatureDefinition $definition, FeatureSchema $schema, array $criteria = []): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $where = [
            'r.tenant_id = CAST(:tenant_id AS uuid)',
            'r.feature_definition_id = CAST(:definition_id AS uuid)',
        ];
        $params = [
            'tenant_id' => $definition->getTenant()->getId()->toRfc4122(),
            'definition_id' => $definition->getId()->toRfc4122(),
        ];
        $types = [];

        $status = trim((string) ($criteria['status'] ?? ''));
        if ('' !== $status) {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }

        $term = trim((string) ($criteria['q'] ?? ''));
        if ('' !== $term) {
            $term = mb_substr($term, 0, 80);
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term));
            $search = [];

            foreach ($schema->searchableFields() as $index => $field) {
                $pathParameter = 'search_path_'.$index;
                $termParameter = 'search_term_'.$index;
                $search[] = sprintf("LOWER(COALESCE(r.data ->> :%s, '')) LIKE :%s", $pathParameter, $termParameter);
                $params[$pathParameter] = $field;
                $params[$termParameter] = '%'.$escaped.'%';
            }

            if ([] !== $search) {
                $where[] = '('.implode(' OR ', $search).')';
            }
        }

        $sort = (string) ($criteria['sort'] ?? 'created_at');
        if (!$schema->isSortable($sort)) {
            $sort = 'created_at';
        }

        $dir = 'asc' === mb_strtolower((string) ($criteria['dir'] ?? 'desc')) ? 'ASC' : 'DESC';

        $orderBy = match ($sort) {
            'status' => 'r.status '.$dir.' NULLS LAST',
            'created_at' => 'r.created_at '.$dir.' NULLS LAST',
            'updated_at' => 'r.updated_at '.$dir.' NULLS LAST',
            default => $this->orderByField($schema->getField($sort), $sort, $dir, $params),
        };

        $perPage = (int) ($criteria['per_page'] ?? $schema->perPage());
        if (!\in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = $schema->perPage();
        }

        $page = max(1, (int) ($criteria['page'] ?? 1));

        $whereSql = implode(' AND ', $where);

        $total = (int) $connection->fetchOne(
            'SELECT COUNT(r.id) FROM dynamic_records r WHERE '.$whereSql,
            $params,
            $types,
        );

        $params['limit'] = $perPage;
        $params['offset'] = ($page - 1) * $perPage;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $ids = $connection->fetchFirstColumn(
            'SELECT r.id FROM dynamic_records r WHERE '.$whereSql
            .' ORDER BY '.$orderBy.', r.created_at DESC, r.id ASC'
            .' LIMIT :limit OFFSET :offset',
            $params,
            $types,
        );

        $items = [];
        if ([] !== $ids) {
            $uuids = array_map(static fn (string $id): Uuid => Uuid::fromString($id), $ids);
            $records = $this->createQueryBuilder('r')
                ->andWhere('r.id IN (:ids)')
                ->setParameter('ids', $uuids)
                ->getQuery()
                ->getResult();

            $byId = [];
            foreach ($records as $record) {
                \assert($record instanceof DynamicRecord);
                $byId[$record->getId()->toRfc4122()] = $record;
            }

            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $items[] = $byId[$id];
                }
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'page_count' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function orderByField(FeatureField $field, string $sort, string $dir, array &$params): string
    {
        if ($field->isNumeric()) {
            $params['sort_path_type'] = $sort;
            $params['sort_path_value'] = $sort;

            return sprintf(
                "CASE WHEN jsonb_typeof(r.data -> :sort_path_type) = 'number' THEN (r.data ->> :sort_path_value)::numeric END %s NULLS LAST",
                $dir,
            );
        }

        if ('boolean' === $field->type) {
            $params['sort_path_type'] = $sort;
            $params['sort_path_value'] = $sort;

            return sprintf(
                "CASE WHEN jsonb_typeof(r.data -> :sort_path_type) = 'boolean' THEN (r.data ->> :sort_path_value)::boolean END %s NULLS LAST",
                $dir,
            );
        }

        $params['sort_path'] = $sort;

        return sprintf('LOWER(COALESCE(r.data ->> :sort_path, \'\')) %s NULLS LAST', $dir);
    }
}
