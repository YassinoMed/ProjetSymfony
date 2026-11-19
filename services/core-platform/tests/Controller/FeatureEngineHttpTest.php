<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\DynamicRecord;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\Enum\MembershipRole;
use App\Entity\FeatureDefinition;
use App\Entity\FeatureDefinitionVersion;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Feature\FeatureDefinitionValidator;
use App\Repository\AuditLogRepository;
use App\Repository\FeatureDefinitionVersionRepository;
use App\Tenant\TenantContext;
use App\Tenant\TenantFilterManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours HTTP Phase 3 : moteur CRUD générique, RBAC, CSRF, audit et isolation.
 *
 * Aucune fonctionnalité ne dispose de contrôleur dédié : les mêmes routes
 * servent toutes les définitions publiées par un tenant.
 */
final class FeatureEngineHttpTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private TenantFilterManager $filterManager;

    private TenantContext $tenantContext;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

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
                'tenants', 'users', 'tenant_memberships', 'audit_logs',
                'feature_definitions', 'feature_definition_versions', 'dynamic_records',
            ]);
        } catch (\Throwable $exception) {
            self::markTestSkipped('Schéma de test indisponible : '.$exception->getMessage());
        }

        if (!$tablesExist) {
            self::markTestSkipped('Schéma Phase 3 absent : exécuter les migrations avant les tests.');
        }

        $this->filterManager->disable();
        $this->tenantContext->reset();
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

    public function testOwnerCreatesFeatureAndRecordThroughGenericEngine(): void
    {
        [$tenant, $owner] = $this->seedTenant();

        $this->createFeatureThroughHttp($owner, $tenant, 'incident');
        $recordId = $this->createRecordThroughHttp($owner, $tenant, 'incident', [
            'titre' => 'Perte de connexion',
            'priorite' => 'haute',
            'montant' => 450,
            'urgent' => true,
            'etiquettes' => 'réseau, incident',
        ]);

        $this->entityManager->clear();
        $record = $this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId));
        self::assertInstanceOf(DynamicRecord::class, $record);
        self::assertSame('Perte de connexion', $record->getData()['titre']);
        self::assertSame('haute', $record->getData()['priorite']);
        self::assertSame(450.0, $record->getData()['montant']);
        self::assertTrue($record->getData()['urgent']);
        self::assertSame(['réseau', 'incident'], $record->getData()['etiquettes']);
        self::assertSame('brouillon', $record->getStatus());
        self::assertSame(1, $record->getSchemaVersion());
        self::assertSame($owner->getEmail(), $record->getCreatedBy()?->getEmail());

        $feature = $this->entityManager->getRepository(FeatureDefinition::class)->findOneBy(['key' => 'incident']);
        self::assertInstanceOf(FeatureDefinition::class, $feature);
        self::assertSame(1, $feature->getCurrentVersion());

        $entries = $this->auditEntries($tenant);
        self::assertContains(AuditAction::FeatureDefinitionCreate, array_map(static fn (AuditLog $log): AuditAction => $log->getAction(), $entries));
        self::assertContains(AuditAction::DynamicRecordCreate, array_map(static fn (AuditLog $log): AuditAction => $log->getAction(), $entries));

        $crawler = $this->get('/features/incident/records', $tenant);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Incidents');
        self::assertStringContainsString('Perte de connexion', (string) $this->client->getResponse()->getContent());

        $this->get('/features/incident/records/'.$recordId, $tenant);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Perte de connexion', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Brouillon', (string) $this->client->getResponse()->getContent());

        $this->editRecordThroughHttp($owner, $tenant, 'incident', $recordId, [
            'titre' => 'Perte de connexion critique',
            'priorite' => 'haute',
            'montant' => 450,
            'urgent' => true,
            'etiquettes' => 'réseau, critique',
        ]);

        $this->entityManager->clear();
        $updated = $this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId));
        self::assertInstanceOf(DynamicRecord::class, $updated);
        self::assertSame('Perte de connexion critique', $updated->getData()['titre']);
        self::assertContains(AuditAction::DynamicRecordUpdate, array_map(static fn (AuditLog $log): AuditAction => $log->getAction(), $this->auditEntries($tenant)));
    }

    public function testMemberCanOpenButCannotCloseOrDeleteRecord(): void
    {
        [$tenant, $owner, , $member] = $this->seedTenant();

        $this->createFeatureThroughHttp($owner, $tenant, 'incident');
        $recordId = $this->createRecordThroughHttp($owner, $tenant, 'incident', [
            'titre' => 'Incident membre',
            'priorite' => 'normale',
        ]);

        $this->client->loginUser($member);
        $crawler = $this->get('/features/incident/records/'.$recordId, $tenant);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ouvrir', (string) $this->client->getResponse()->getContent());

        $this->transitionThroughHttp($member, $tenant, 'incident', $recordId, 'brouillon', 'ouvert');
        self::assertResponseRedirects('/features/incident/records/'.$recordId);
        $this->entityManager->clear();
        $record = $this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId));
        self::assertInstanceOf(DynamicRecord::class, $record);
        self::assertSame('ouvert', $record->getStatus());

        $this->transitionThroughHttp($member, $tenant, 'incident', $recordId, 'ouvert', 'ferme');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->post('/features/incident/records/'.$recordId.'/delete', $tenant, [
            'delete_record' => ['_token' => 'csrf-token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId)));
        self::assertSame('ouvert', $this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId))?->getStatus());

        $this->get('/features/new', $tenant);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $denied = array_filter(
            $this->auditEntries($tenant),
            static fn (AuditLog $log): bool => AuditResult::Denied === $log->getResult(),
        );
        self::assertNotEmpty($denied);
    }

    public function testAuditorIsReadOnly(): void
    {
        [$tenant, $owner, , , $auditor] = $this->seedTenant();

        $this->createFeatureThroughHttp($owner, $tenant, 'incident');
        $recordId = $this->createRecordThroughHttp($owner, $tenant, 'incident', [
            'titre' => 'Incident audité',
        ]);

        $this->client->loginUser($auditor);
        $this->get('/features', $tenant);
        self::assertResponseIsSuccessful();
        $this->get('/features/incident/records', $tenant);
        self::assertResponseIsSuccessful();
        $this->get('/features/incident/records/'.$recordId, $tenant);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Modifier', (string) $this->client->getResponse()->getContent());

        $this->get('/features/new', $tenant);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->get('/features/incident/records/new', $tenant);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->get('/features/incident/records/'.$recordId.'/edit', $tenant);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->transitionThroughHttp($auditor, $tenant, 'incident', $recordId, 'brouillon', 'ouvert');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->post('/features/incident/records/'.$recordId.'/delete', $tenant, [
            'delete_record' => ['_token' => 'csrf-token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId)));
        self::assertSame('brouillon', $this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId))?->getStatus());
    }

    public function testAdminCanDeleteRecordAndAuditIsWritten(): void
    {
        [$tenant, , $admin] = $this->seedTenant();

        $this->createFeatureThroughHttp($admin, $tenant, 'incident');
        $recordId = $this->createRecordThroughHttp($admin, $tenant, 'incident', [
            'titre' => 'À supprimer',
        ]);

        $this->post('/features/incident/records/'.$recordId.'/delete', $tenant, [
            'delete_record' => ['_token' => 'csrf-token'],
        ]);
        self::assertResponseRedirects('/features/incident/records');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId)));

        self::assertContains(
            AuditAction::DynamicRecordDelete,
            array_map(static fn (AuditLog $log): AuditAction => $log->getAction(), $this->auditEntries($tenant)),
        );
    }

    public function testCrossTenantFeatureAndRecordAreNotFound(): void
    {
        [$tenantA, $ownerA] = $this->seedTenant();
        [$tenantB, $ownerB] = $this->seedTenant();

        [$featureA, $recordA] = $this->seedFeatureAndRecord($tenantA, $ownerA, 'feature_a');
        [, $recordB] = $this->seedFeatureAndRecord($tenantB, $ownerB, 'feature_b');

        $this->client->loginUser($ownerA);

        $this->get('/features/feature_b/records', $tenantA);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->get('/features/feature_a/records/'.$recordB->getId()->toRfc4122(), $tenantA);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->get('/features/feature_a/records/'.$recordA->getId()->toRfc4122(), $tenantA);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Secret A', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Secret B', (string) $this->client->getResponse()->getContent());
    }

    public function testCsrfFailureDoesNotChangeStatusOrDeleteRecord(): void
    {
        [$tenant, $owner] = $this->seedTenant();
        [$feature, $record] = $this->seedFeatureAndRecord($tenant, $owner, 'incident');

        $this->client->loginUser($owner);

        $this->post('/features/incident/records/'.$record->getId()->toRfc4122().'/transition', $tenant, [
            'record_transition' => ['from' => 'brouillon', 'to' => 'ouvert'],
        ]);
        self::assertResponseRedirects('/features/incident/records/'.$record->getId()->toRfc4122());

        $this->post('/features/incident/records/'.$record->getId()->toRfc4122().'/delete', $tenant, [
            'delete_record' => [],
        ]);
        self::assertResponseRedirects('/features/incident/records/'.$record->getId()->toRfc4122());

        // Jeton présent mais origine étrangère : la protection CSRF refuse aussi.
        $this->client->request('POST', '/features/incident/records/'.$record->getId()->toRfc4122().'/transition', [
            'record_transition' => ['from' => 'brouillon', 'to' => 'ouvert', '_token' => 'csrf-token'],
        ], [], [
            'HTTP_X_TENANT_ID' => $tenant->getId()->toRfc4122(),
            'HTTP_REFERER' => 'http://evil.example/attack',
        ]);
        self::assertResponseRedirects('/features/incident/records/'.$record->getId()->toRfc4122());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(DynamicRecord::class, $record->getId());
        self::assertInstanceOf(DynamicRecord::class, $stored);
        self::assertSame('brouillon', $stored->getStatus());
    }

    public function testRecordValidationFailureReturns422AndPersistsNothing(): void
    {
        [$tenant, $owner] = $this->seedTenant();
        $this->createFeatureThroughHttp($owner, $tenant, 'incident');

        $this->client->loginUser($owner);
        $crawler = $this->get('/features/incident/records/new', $tenant);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton("Créer l'enregistrement")->form();
        $form['dynamic_record[titre]'] = 'ab';

        $this->client->submit($form);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Au moins 3 caractère(s) sont requis.', (string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->getRepository(DynamicRecord::class)->count([]));

        // Sans jeton CSRF, le formulaire est invalide et aucune ligne n'est créée.
        $this->post('/features/incident/records/new', $tenant, [
            'dynamic_record' => ['titre' => 'Titre valide'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->getRepository(DynamicRecord::class)->count([]));
    }

    public function testDefinitionVersioningPreservesImmutableHistoryAndMetadata(): void
    {
        [$tenant, $owner] = $this->seedTenant();

        $this->createFeatureThroughHttp($owner, $tenant, 'incident');
        $recordId = $this->createRecordThroughHttp($owner, $tenant, 'incident', [
            'titre' => 'Premier enregistrement',
            'priorite' => 'normale',
        ]);

        $this->entityManager->clear();
        $definition = $this->entityManager->getRepository(FeatureDefinition::class)->findOneBy(['key' => 'incident']);
        self::assertInstanceOf(FeatureDefinition::class, $definition);

        /** @var FeatureDefinitionVersionRepository $versions */
        $versions = $this->entityManager->getRepository(FeatureDefinitionVersion::class);
        $versionOne = $versions->findOneBy(['featureDefinition' => $definition, 'version' => 1]);
        self::assertInstanceOf(FeatureDefinitionVersion::class, $versionOne);
        $hashOne = $versionOne->getHash();

        $definitionPayload = $this->definitionPayload();
        $definitionPayload['schema']['properties']['notes'] = [
            'type' => 'string',
            'title' => 'Notes',
            'maxLength' => 200,
        ];
        $definitionPayload['schema']['properties']['echeance'] = [
            'type' => 'string',
            'format' => 'date',
            'title' => 'Échéance',
        ];

        $this->editFeatureThroughHttp($owner, $tenant, 'incident', 'Incidents v2', $definitionPayload);

        $this->entityManager->clear();
        $definition = $this->entityManager->getRepository(FeatureDefinition::class)->findOneBy(['key' => 'incident']);
        self::assertInstanceOf(FeatureDefinition::class, $definition);
        self::assertSame(2, $definition->getCurrentVersion());

        /** @var FeatureDefinitionVersionRepository $versions */
        $versions = $this->entityManager->getRepository(FeatureDefinitionVersion::class);
        self::assertCount(2, $versions->findAllForDefinition($definition));
        $versionOne = $versions->findOneBy(['featureDefinition' => $definition, 'version' => 1]);
        self::assertInstanceOf(FeatureDefinitionVersion::class, $versionOne);
        self::assertSame($hashOne, $versionOne->getHash());

        $newRecordId = $this->createRecordThroughHttp($owner, $tenant, 'incident', [
            'titre' => 'Deuxième enregistrement',
            'notes' => 'Créé avec le schéma v2',
            'echeance' => '2026-12-01',
        ]);

        $this->entityManager->clear();
        $newRecord = $this->entityManager->find(DynamicRecord::class, Uuid::fromString($newRecordId));
        self::assertInstanceOf(DynamicRecord::class, $newRecord);
        self::assertSame(2, $newRecord->getSchemaVersion());
        self::assertSame('Créé avec le schéma v2', $newRecord->getData()['notes']);

        $oldRecord = $this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId));
        self::assertInstanceOf(DynamicRecord::class, $oldRecord);
        self::assertSame(1, $oldRecord->getSchemaVersion());

        $this->editRecordThroughHttp($owner, $tenant, 'incident', $recordId, [
            'titre' => 'Premier enregistrement',
            'priorite' => 'normale',
            'notes' => 'Migré vers v2',
        ]);
        $this->entityManager->clear();
        $oldRecord = $this->entityManager->find(DynamicRecord::class, Uuid::fromString($recordId));
        self::assertInstanceOf(DynamicRecord::class, $oldRecord);
        self::assertSame(2, $oldRecord->getSchemaVersion());
        self::assertSame('Migré vers v2', $oldRecord->getData()['notes']);

        $this->editFeatureMetadataThroughHttp($owner, $tenant, 'incident', 'Incidents renommés');

        $this->entityManager->clear();
        $definition = $this->entityManager->getRepository(FeatureDefinition::class)->findOneBy(['key' => 'incident']);
        self::assertInstanceOf(FeatureDefinition::class, $definition);
        self::assertSame('Incidents renommés', $definition->getName());
        self::assertSame(2, $definition->getCurrentVersion());

        /** @var FeatureDefinitionVersionRepository $versions */
        $versions = $this->entityManager->getRepository(FeatureDefinitionVersion::class);
        self::assertCount(2, $versions->findAllForDefinition($definition));

        self::assertContains(
            AuditAction::FeatureDefinitionVersionCreate,
            array_map(static fn (AuditLog $log): AuditAction => $log->getAction(), $this->auditEntries($tenant)),
        );
        self::assertContains(
            AuditAction::FeatureDefinitionUpdate,
            array_map(static fn (AuditLog $log): AuditAction => $log->getAction(), $this->auditEntries($tenant)),
        );
    }

    public function testInvalidDefinitionIsRejectedWith422(): void
    {
        [$tenant, $owner] = $this->seedTenant();

        $this->client->loginUser($owner);
        $crawler = $this->get('/features/new', $tenant);
        self::assertResponseIsSuccessful();

        $definition = $this->definitionPayload();
        $definition['schema']['properties']['titre']['$ref'] = '#/definitions/titre';

        $form = $crawler->selectButton('Créer la fonctionnalité (version 1)')->form();
        $form['feature_definition[key]'] = 'incident';
        $form['feature_definition[name]'] = 'Incidents';
        $form['feature_definition[description]'] = '';
        $form['feature_definition[definition]'] = json_encode($definition, JSON_THROW_ON_ERROR);

        $this->client->submit($form);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('$ref', (string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(FeatureDefinition::class)->findOneBy(['key' => 'incident']));
    }

    public function testFeatureWriteRateLimitReturns429AfterSixtyMutations(): void
    {
        [$tenant, $owner] = $this->seedTenant();
        $this->seedFeatureAndRecord($tenant, $owner, 'incident');
        $this->entityManager->clear();

        $this->client->loginUser($owner);

        for ($index = 1; $index <= 60; ++$index) {
            $this->post('/features/incident/records/new', $tenant, [
                'dynamic_record' => ['titre' => 'Sans jeton'],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('Mutation %d', $index));
        }

        $this->post('/features/incident/records/new', $tenant, [
            'dynamic_record' => ['titre' => 'Sans jeton'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * @return array{Tenant, User, User, User, User}
     */
    private function seedTenant(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Acme '.$suffix, 'acme-'.$suffix);
        $owner = new User('owner-'.$suffix.'@example.com', 'unused');
        $admin = new User('admin-'.$suffix.'@example.com', 'unused');
        $member = new User('member-'.$suffix.'@example.com', 'unused');
        $auditor = new User('auditor-'.$suffix.'@example.com', 'unused');

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($owner);
        $this->entityManager->persist($admin);
        $this->entityManager->persist($member);
        $this->entityManager->persist($auditor);
        $this->entityManager->persist(new TenantMembership($tenant, $owner, MembershipRole::Owner));
        $this->entityManager->persist(new TenantMembership($tenant, $admin, MembershipRole::Admin));
        $this->entityManager->persist(new TenantMembership($tenant, $member, MembershipRole::Member));
        $this->entityManager->persist(new TenantMembership($tenant, $auditor, MembershipRole::Auditor));
        $this->entityManager->flush();

        return [$tenant, $owner, $admin, $member, $auditor];
    }

    private function createFeatureThroughHttp(
        User $user,
        Tenant $tenant,
        string $key,
        ?array $definition = null,
    ): void {
        $this->client->loginUser($user);
        $crawler = $this->get('/features/new', $tenant);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer la fonctionnalité (version 1)')->form();
        $form['feature_definition[key]'] = $key;
        $form['feature_definition[name]'] = 'Incidents';
        $form['feature_definition[description]'] = 'Suivi des incidents';
        $form['feature_definition[definition]'] = json_encode($definition ?? $this->definitionPayload(), JSON_THROW_ON_ERROR);

        $this->client->submit($form);
        self::assertResponseRedirects('/features/'.$key.'/records');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function editFeatureThroughHttp(
        User $user,
        Tenant $tenant,
        string $key,
        string $name,
        array $definition,
    ): void {
        $this->client->loginUser($user);
        $crawler = $this->get('/features/'.$key.'/edit', $tenant);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Publier si la définition a changé')->form();
        $form['feature_definition[name]'] = $name;
        $form['feature_definition[description]'] = 'Suivi des incidents';
        $form['feature_definition[definition]'] = json_encode($definition, JSON_THROW_ON_ERROR);

        $this->client->submit($form);
        self::assertResponseRedirects('/features/'.$key.'/records');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function editFeatureMetadataThroughHttp(User $user, Tenant $tenant, string $key, string $name): void
    {
        $this->client->loginUser($user);
        $crawler = $this->get('/features/'.$key.'/edit', $tenant);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Publier si la définition a changé')->form();
        $form['feature_definition[name]'] = $name;

        $this->client->submit($form);
        self::assertResponseRedirects('/features/'.$key.'/edit');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('métadonnées', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createRecordThroughHttp(User $user, Tenant $tenant, string $key, array $data): string
    {
        $this->client->loginUser($user);
        $crawler = $this->get('/features/'.$key.'/records/new', $tenant);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton("Créer l'enregistrement")->form();
        foreach ($data as $field => $value) {
            if ('urgent' === $field) {
                if (true === $value) {
                    $checkbox = $form['dynamic_record[urgent]'];
                    \assert($checkbox instanceof ChoiceFormField);
                    $checkbox->tick();
                }

                continue;
            }

            $form['dynamic_record['.$field.']'] = (string) $value;
        }

        $this->client->submit($form);
        self::assertResponseRedirects();

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression(
            '#^/features/'.preg_quote($key, '#').'/records/[0-9a-f-]{36}$#',
            $location,
        );

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        return basename($location);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function editRecordThroughHttp(User $user, Tenant $tenant, string $key, string $id, array $data): void
    {
        $this->client->loginUser($user);
        $crawler = $this->get('/features/'.$key.'/records/'.$id.'/edit', $tenant);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        foreach ($data as $field => $value) {
            if ('urgent' === $field) {
                if (true === $value) {
                    $checkbox = $form['dynamic_record[urgent]'];
                    \assert($checkbox instanceof ChoiceFormField);
                    $checkbox->tick();
                }

                continue;
            }

            $form['dynamic_record['.$field.']'] = (string) $value;
        }

        $this->client->submit($form);
        self::assertResponseRedirects('/features/'.$key.'/records/'.$id);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function transitionThroughHttp(
        User $user,
        Tenant $tenant,
        string $key,
        string $id,
        string $from,
        string $to,
    ): void {
        $this->client->loginUser($user);
        $this->post('/features/'.$key.'/records/'.$id.'/transition', $tenant, [
            'record_transition' => [
                'from' => $from,
                'to' => $to,
                '_token' => 'csrf-token',
            ],
        ]);
    }

    /**
     * @return array{FeatureDefinition, DynamicRecord}
     */
    private function seedFeatureAndRecord(Tenant $tenant, User $user, string $key): array
    {
        $draft = (new FeatureDefinitionValidator())->validate($key, 'Incidents', 'Suivi', $this->definitionPayload());

        $definition = new FeatureDefinition($tenant, $key, 'Incidents', 'Suivi');
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        $version = new FeatureDefinitionVersion(
            tenant: $tenant,
            featureDefinition: $definition,
            version: 1,
            schema: $draft->normalizedSchema,
            ui: $draft->normalizedUi,
            workflow: $draft->normalizedWorkflow,
            hash: $draft->hash,
            createdBy: $user,
        );
        $this->entityManager->persist($version);
        $this->entityManager->flush();

        $record = new DynamicRecord(
            tenant: $tenant,
            featureDefinition: $definition,
            schemaVersion: 1,
            data: ['titre' => 'Secret '.strtoupper(substr($key, -1)), 'priorite' => 'normale'],
            status: 'brouillon',
            createdBy: $user,
        );
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return [$definition, $record];
    }

    /**
     * @return list<AuditLog>
     */
    private function auditEntries(Tenant $tenant): array
    {
        $this->entityManager->clear();

        /** @var AuditLogRepository $logs */
        $logs = $this->entityManager->getRepository(AuditLog::class);

        return $logs->findPageForTenant($tenant, 1, 100);
    }

    private function get(string $uri, Tenant $tenant): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', $uri, [], [], [
            'HTTP_X_TENANT_ID' => $tenant->getId()->toRfc4122(),
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function post(string $uri, Tenant $tenant, array $parameters): void
    {
        $this->client->request('POST', $uri, $parameters, [], [
            'HTTP_X_TENANT_ID' => $tenant->getId()->toRfc4122(),
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
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
                'per_page' => 25,
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
}
