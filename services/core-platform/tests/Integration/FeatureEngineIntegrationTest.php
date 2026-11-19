<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\DynamicRecord;
use App\Entity\Enum\MembershipRole;
use App\Entity\FeatureDefinition;
use App\Entity\FeatureDefinitionVersion;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Feature\CanonicalJson;
use App\Feature\FeatureDefinitionValidator;
use App\Feature\FeatureSchema;
use App\Feature\RecordDataValidator;
use App\Feature\RecordDataNormalizer;
use App\Feature\WorkflowDefinition;
use App\Repository\DynamicRecordRepository;
use App\Repository\FeatureDefinitionRepository;
use App\Repository\FeatureDefinitionVersionRepository;
use App\Tenant\TenantContext;
use App\Tenant\TenantFilterManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests PostgreSQL du moteur Phase 3 : requêtes JSONB, isolation et immutabilité.
 *
 * Chaque test s'exécute dans une transaction annulée en fin de méthode. Les
 * tests de triggers interrompent volontairement la transaction PostgreSQL.
 */
final class FeatureEngineIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private TenantFilterManager $filterManager;

    private TenantContext $tenantContext;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->filterManager = $container->get(TenantFilterManager::class);
        $this->tenantContext = $container->get(TenantContext::class);

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            self::markTestSkipped('PostgreSQL de test indisponible : '.$exception->getMessage());
        }

        try {
            $tablesExist = $this->connection->createSchemaManager()->tablesExist([
                'feature_definitions', 'feature_definition_versions', 'dynamic_records',
            ]);
        } catch (\Throwable $exception) {
            self::markTestSkipped('Schéma de test indisponible : '.$exception->getMessage());
        }

        if (!$tablesExist) {
            self::markTestSkipped('Schéma Phase 3 absent : exécuter les migrations avant les tests.');
        }

        $this->connection->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            $this->filterManager->disable();
            $this->entityManager->clear();

            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }

        $this->tenantContext->reset();
        parent::tearDown();
    }

    public function testSearchFilterAndSortAreRestrictedToSchemaFields(): void
    {
        [$tenant, $user] = $this->seedTenant();
        [$definition, $version] = $this->seedFeature($tenant, $user);

        $this->seedRecord($tenant, $definition, $version->getVersion(), [
            'titre' => 'Alpha',
            'priorite' => 'haute',
            'montant' => 30,
            'urgent' => true,
        ], 'brouillon', $user);
        $this->seedRecord($tenant, $definition, $version->getVersion(), [
            'titre' => 'Bravo',
            'priorite' => 'basse',
            'montant' => 10,
            'urgent' => false,
        ], 'brouillon', $user);
        $this->seedRecord($tenant, $definition, $version->getVersion(), [
            'titre' => 'Charlie',
            'priorite' => 'normale',
            'montant' => 20,
            'urgent' => true,
        ], 'ouvert', $user);
        $this->seedRecord($tenant, $definition, $version->getVersion(), [
            'titre' => 'Delta',
            'priorite' => 'haute',
        ], 'brouillon', $user);

        $schema = FeatureSchema::fromVersion($version);
        $workflow = new WorkflowDefinition($version->getWorkflow());
        self::assertSame('Brouillon', $workflow->stateLabel('brouillon'));

        /** @var DynamicRecordRepository $records */
        $records = $this->entityManager->getRepository(DynamicRecord::class);

        $alpha = $records->search($definition, $schema, ['q' => 'alpha']);
        self::assertSame(1, $alpha['total']);
        self::assertSame('Alpha', $alpha['items'][0]->getData()['titre']);

        $priority = $records->search($definition, $schema, ['q' => 'HAUTE']);
        self::assertSame(2, $priority['total']);
        self::assertSame(['Alpha', 'Delta'], array_map(
            static fn (DynamicRecord $record): string => $record->getData()['titre'],
            $priority['items'],
        ));

        // Les booléens et tableaux ne sont pas des champs de recherche.
        self::assertSame(0, $records->search($definition, $schema, ['q' => 'true'])['total']);
        self::assertSame(0, $records->search($definition, $schema, ['q' => 'urgent'])['total']);

        // Les jokers SQL fournis par l'utilisateur sont échappés.
        self::assertSame(0, $records->search($definition, $schema, ['q' => '%'])['total']);

        $open = $records->search($definition, $schema, ['status' => 'ouvert']);
        self::assertSame(1, $open['total']);
        self::assertSame('Charlie', $open['items'][0]->getData()['titre']);

        $byAmount = $records->search($definition, $schema, ['sort' => 'montant', 'dir' => 'asc']);
        self::assertSame(
            ['Bravo', 'Charlie', 'Alpha', 'Delta'],
            array_map(static fn (DynamicRecord $record): mixed => $record->getData()['titre'] ?? null, $byAmount['items']),
        );

        $byAmountDesc = $records->search($definition, $schema, ['sort' => 'montant', 'dir' => 'desc']);
        self::assertSame(
            ['Alpha', 'Charlie', 'Bravo', 'Delta'],
            array_map(static fn (DynamicRecord $record): mixed => $record->getData()['titre'] ?? null, $byAmountDesc['items']),
        );

        $byTitle = $records->search($definition, $schema, ['sort' => 'titre', 'dir' => 'asc']);
        self::assertSame(
            ['Alpha', 'Bravo', 'Charlie', 'Delta'],
            array_map(static fn (DynamicRecord $record): mixed => $record->getData()['titre'] ?? null, $byTitle['items']),
        );

        $byUrgent = $records->search($definition, $schema, ['sort' => 'urgent', 'dir' => 'asc']);
        self::assertSame('Bravo', $byUrgent['items'][0]->getData()['titre']);
        self::assertSame('Delta', $byUrgent['items'][3]->getData()['titre']);

        // Un champ non autorisé retombe sur le tri par défaut.
        $fallback = $records->search($definition, $schema, ['sort' => 'urgent; DROP TABLE dynamic_records', 'dir' => 'asc']);
        self::assertSame(4, $fallback['total']);

        // Un statut inconnu est filtré par le contrôleur avant l'appel ; la
        // requête reste néanmoins paramétrée et ne renvoie aucune ligne.
        self::assertSame(0, $records->search($definition, $schema, ['status' => "ouvert' OR 1=1 --"])['total']);
    }

    public function testPaginationIsBoundedAndReportsPageCount(): void
    {
        [$tenant, $user] = $this->seedTenant();
        [$definition, $version] = $this->seedFeature($tenant, $user);

        for ($index = 1; $index <= 12; ++$index) {
            $this->seedRecord($tenant, $definition, $version->getVersion(), [
                'titre' => sprintf('Record %02d', $index),
            ], 'brouillon', $user);
        }

        $schema = FeatureSchema::fromVersion($version);

        /** @var DynamicRecordRepository $records */
        $records = $this->entityManager->getRepository(DynamicRecord::class);

        $first = $records->search($definition, $schema, ['per_page' => 10, 'page' => 1]);
        self::assertSame(12, $first['total']);
        self::assertSame(10, $first['per_page']);
        self::assertCount(10, $first['items']);
        self::assertSame(2, $first['page_count']);

        $second = $records->search($definition, $schema, ['per_page' => 10, 'page' => 2]);
        self::assertCount(2, $second['items']);
        self::assertSame(2, $second['page']);

        $beyond = $records->search($definition, $schema, ['per_page' => 10, 'page' => 99]);
        self::assertSame([], $beyond['items']);
        self::assertSame(99, $beyond['page']);
        self::assertSame(2, $beyond['page_count']);

        // 7 n'est pas une taille de page autorisée : retombée sur le défaut du schéma (10).
        $fallback = $records->search($definition, $schema, ['per_page' => 7, 'page' => 1]);
        self::assertSame(10, $fallback['per_page']);
    }

    public function testRecordsAreScopedToDefinitionAndTenant(): void
    {
        [$tenantA, $userA, $membershipA] = $this->seedTenant();
        [$definitionA, $versionA] = $this->seedFeature($tenantA, $userA, 'feature_a');

        [$tenantB, $userB] = $this->seedTenant();
        [$definitionB, $versionB] = $this->seedFeature($tenantB, $userB, 'feature_b');

        $recordA = $this->seedRecord($tenantA, $definitionA, $versionA->getVersion(), [
            'titre' => 'Secret A',
        ], 'brouillon', $userA);
        $recordB = $this->seedRecord($tenantB, $definitionB, $versionB->getVersion(), [
            'titre' => 'Secret B',
        ], 'brouillon', $userB);

        $schemaA = FeatureSchema::fromVersion($versionA);

        /** @var DynamicRecordRepository $records */
        $records = $this->entityManager->getRepository(DynamicRecord::class);

        $result = $records->search($definitionA, $schemaA);
        self::assertSame(1, $result['total']);
        self::assertTrue($result['items'][0]->getId()->equals($recordA->getId()));
        self::assertNull($records->findOneForDefinition($recordB->getId(), $definitionA));
        self::assertNotNull($records->findOneForDefinition($recordA->getId(), $definitionA));

        $this->tenantContext->setTenant($membershipA);
        $this->filterManager->enable($tenantA->getId());
        $this->entityManager->clear();

        self::assertNotNull($records->find($recordA->getId()));
        self::assertNull($records->find($recordB->getId()));

        $this->filterManager->disable();
        $this->entityManager->clear();
        self::assertNotNull($records->find($recordB->getId()));
    }

    public function testPublishedVersionCannotBeUpdated(): void
    {
        [$tenant, $user] = $this->seedTenant();
        [, $version] = $this->seedFeature($tenant, $user);

        try {
            $this->connection->executeStatement(
                'UPDATE feature_definition_versions SET record_schema = :schema WHERE id = :id',
                [
                    'schema' => '{"type":"object"}',
                    'id' => $version->getId()->toRfc4122(),
                ],
            );
            self::fail('La mise à jour d\'une version publiée aurait dû être refusée.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('immuable', $exception->getMessage());
            self::assertStringContainsString('UPDATE', $exception->getMessage());
        } finally {
            $this->abortTransaction();
        }
    }

    public function testPublishedVersionCannotBeDeleted(): void
    {
        [$tenant, $user] = $this->seedTenant();
        [, $version] = $this->seedFeature($tenant, $user);

        try {
            $this->connection->executeStatement(
                'DELETE FROM feature_definition_versions WHERE id = :id',
                ['id' => $version->getId()->toRfc4122()],
            );
            self::fail('La suppression d\'une version publiée aurait dû être refusée.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('immuable', $exception->getMessage());
            self::assertStringContainsString('DELETE', $exception->getMessage());
        } finally {
            $this->abortTransaction();
        }
    }

    public function testVersionsTableCannotBeTruncated(): void
    {
        [$tenant, $user] = $this->seedTenant();
        $this->seedFeature($tenant, $user);

        try {
            $this->connection->executeStatement('TRUNCATE feature_definition_versions CASCADE');
            self::fail('Le TRUNCATE des versions publiées aurait dû être refusé.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('immuable', $exception->getMessage());
            self::assertStringContainsString('TRUNCATE', $exception->getMessage());
        } finally {
            $this->abortTransaction();
        }
    }

    public function testRecordCannotReferenceAnUnknownSchemaVersion(): void
    {
        [$tenant, $user] = $this->seedTenant();
        [$definition] = $this->seedFeature($tenant, $user);

        try {
            $this->connection->executeStatement(
                'INSERT INTO dynamic_records (id, tenant_id, feature_definition_id, schema_version, data, status, created_by, created_at, updated_at)
                 VALUES (:id, :tenant, :definition, :schema_version, :data, :status, NULL, NOW(), NOW())',
                [
                    'id' => '0190f5d0-0000-7000-8000-000000000001',
                    'tenant' => $tenant->getId()->toRfc4122(),
                    'definition' => $definition->getId()->toRfc4122(),
                    'schema_version' => 99,
                    'data' => '{"titre":"Orphelin"}',
                    'status' => 'brouillon',
                ],
            );
            self::fail('Un enregistrement rattaché à une version inexistante aurait dû être refusé.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('fk_dynamic_record_version', $exception->getMessage());
        } finally {
            $this->abortTransaction();
        }
    }

    public function testVersionHashMatchesCanonicalPayload(): void
    {
        [$tenant, $user] = $this->seedTenant();
        [$definition, $version] = $this->seedFeature($tenant, $user);

        self::assertSame(CanonicalJson::hash($version->getPayload()), $version->getHash());
        self::assertSame(1, $version->getVersion());
        self::assertTrue($version->getFeatureDefinition()->getId()->equals($definition->getId()));

        /** @var FeatureDefinitionVersionRepository $versions */
        $versions = $this->entityManager->getRepository(FeatureDefinitionVersion::class);
        self::assertTrue($versions->findCurrent($definition)?->getId()->equals($version->getId()));
        self::assertCount(1, $versions->findAllForDefinition($definition));
    }

    /**
     * @return array{Tenant, User, TenantMembership}
     */
    private function seedTenant(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Tenant '.$suffix, 'tenant-'.$suffix);
        $user = new User('user-'.$suffix.'@example.com', 'unused');
        $membership = new TenantMembership($tenant, $user, MembershipRole::Owner);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return [$tenant, $user, $membership];
    }

    /**
     * @return array{FeatureDefinition, FeatureDefinitionVersion}
     */
    private function seedFeature(Tenant $tenant, User $user, ?string $key = null): array
    {
        $key ??= 'incident_'.bin2hex(random_bytes(3));
        $definition = $this->definitionPayload();
        $draft = (new FeatureDefinitionValidator())->validate($key, 'Incidents', 'Suivi', $definition);

        $feature = new FeatureDefinition($tenant, $key, 'Incidents', 'Suivi');
        $this->entityManager->persist($feature);
        $this->entityManager->flush();

        $version = new FeatureDefinitionVersion(
            tenant: $tenant,
            featureDefinition: $feature,
            version: 1,
            schema: $draft->normalizedSchema,
            ui: $draft->normalizedUi,
            workflow: $draft->normalizedWorkflow,
            hash: $draft->hash,
            createdBy: $user,
        );
        $this->entityManager->persist($version);
        $this->entityManager->flush();

        return [$feature, $version];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function seedRecord(
        Tenant $tenant,
        FeatureDefinition $definition,
        int $schemaVersion,
        array $data,
        string $status,
        User $user,
    ): DynamicRecord {
        $record = new DynamicRecord($tenant, $definition, $schemaVersion, $data, $status, $user);
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionPayload(): array
    {
        return [
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['titre'],
                'properties' => [
                    'titre' => [
                        'type' => 'string',
                        'title' => 'Titre',
                        'minLength' => 3,
                        'maxLength' => 100,
                    ],
                    'priorite' => [
                        'type' => 'string',
                        'title' => 'Priorité',
                        'enum' => ['basse', 'normale', 'haute'],
                        'default' => 'normale',
                    ],
                    'montant' => [
                        'type' => 'number',
                        'title' => 'Montant',
                        'minimum' => 0,
                        'maximum' => 1000000,
                    ],
                    'urgent' => [
                        'type' => 'boolean',
                        'title' => 'Urgent',
                    ],
                    'etiquettes' => [
                        'type' => 'array',
                        'title' => 'Étiquettes',
                        'items' => ['type' => 'string', 'maxLength' => 20],
                        'maxItems' => 5,
                    ],
                ],
            ],
            'ui' => [
                'list_fields' => ['titre', 'priorite', 'status', 'montant'],
                'per_page' => 10,
            ],
            'workflow' => [
                'initial' => 'brouillon',
                'states' => [
                    'brouillon' => ['label' => 'Brouillon'],
                    'ouvert' => ['label' => 'Ouvert'],
                    'ferme' => ['label' => 'Fermé'],
                ],
                'transitions' => [
                    [
                        'from' => 'brouillon',
                        'to' => 'ouvert',
                        'label' => 'Ouvrir',
                        'roles' => ['owner', 'admin', 'member'],
                    ],
                    [
                        'from' => 'ouvert',
                        'to' => 'ferme',
                        'label' => 'Fermer',
                        'roles' => ['owner', 'admin'],
                    ],
                ],
            ],
        ];
    }

    private function abortTransaction(): void
    {
        $this->filterManager->disable();
        $this->entityManager->clear();

        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->transactionStarted = false;
    }
}
