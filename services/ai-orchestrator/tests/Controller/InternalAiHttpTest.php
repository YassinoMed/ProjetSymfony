<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Tests HTTP et sécurité de l'API interne de l'orchestrateur IA.
 *
 * En environnement de test, Messenger route les messages vers le transport
 * synchrone : un POST exécute donc réellement le pipeline et permet de vérifier
 * qu'aucune publication automatique n'a lieu.
 */
final class InternalAiHttpTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            self::markTestSkipped('PostgreSQL de test indisponible : '.$exception->getMessage());
        }

        try {
            $tablesExist = $this->connection->createSchemaManager()->tablesExist([
                'ai_requests', 'ai_runs', 'ai_proposals', 'rag_documents', 'rag_chunks',
            ]);
        } catch (\Throwable $exception) {
            self::markTestSkipped('Schéma de test indisponible : '.$exception->getMessage());
        }

        if (!$tablesExist) {
            self::markTestSkipped('Schéma Phase 4 absent : exécuter les migrations avant les tests.');
        }

        $this->truncate();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->truncate();
            $this->entityManager->clear();
        }

        parent::tearDown();
    }

    public function testHealthIsPublicAndDeclaresNoPublication(): void
    {
        $this->client->request('GET', '/health');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = $this->json();
        self::assertSame('ok', $body['status']);
        self::assertSame('ai-orchestrator', $body['service']);
        self::assertSame('forbidden', $body['publication']);
    }

    public function testInternalRoutesRejectMissingOrInvalidToken(): void
    {
        $this->client->jsonRequest('POST', '/internal/ai/requests', ['prompt' => 'Créer une gestion des tâches.'], [
            'HTTP_X_TENANT_ID' => Uuid::v4()->toRfc4122(),
        ]);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('unauthorized', $this->json()['error']);

        $this->client->jsonRequest('POST', '/internal/ai/requests', ['prompt' => 'Créer une gestion des tâches.'], [
            'HTTP_X_INTERNAL_TOKEN' => 'mauvais-jeton',
            'HTTP_X_TENANT_ID' => Uuid::v4()->toRfc4122(),
        ]);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateRequiresTenantHeader(): void
    {
        $this->client->jsonRequest(
            'POST',
            '/internal/ai/requests',
            ['prompt' => 'Créer une gestion des tâches.'],
            ['HTTP_X_INTERNAL_TOKEN' => 'test-internal-token'],
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalid_request', $this->json()['error']);
    }

    public function testCreateRejectsUnknownBodyKey(): void
    {
        $this->client->jsonRequest('POST', '/internal/ai/requests', [
            'prompt' => 'Créer une gestion des tâches avec priorités.',
            'published' => true,
        ], $this->server());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateRejectsInvalidActorRole(): void
    {
        $this->client->jsonRequest(
            'POST',
            '/internal/ai/requests',
            ['prompt' => 'Créer une gestion des tâches avec priorités.'],
            $this->server(role: 'root'),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateRunsPipelineAndNeverPublishes(): void
    {
        $body = $this->createRequest();

        self::assertSame('awaiting_approval', $body['status']);
        self::assertSame('awaiting_approval', $body['proposal']['status']);
        self::assertFalse($body['proposal']['published']);
        self::assertSame('forbidden', $body['proposal']['publication']);
        self::assertSame('forbidden', $body['proposal']['policy']['publication']);
        self::assertTrue($body['proposal']['policy']['requires_human_approval']);
        self::assertCount(4, $body['proposal']['agent_trace']);
        self::assertSame(
            ['architect', 'builder', 'auditor', 'governor'],
            array_column($body['proposal']['agent_trace'], 'agent'),
        );
        self::assertSame('feature_definition', $body['proposal']['payload']['type']);
        self::assertArrayNotHasKey('published', $body['proposal']['payload']);
    }

    public function testCreateIsIdempotentPerTenant(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        $key = 'http:'.Uuid::v4()->toRfc4122();

        $first = $this->createRequest($tenant, $key);
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $second = $this->createRequest($tenant, $key);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotent-Replay'));

        self::assertSame($first['request_id'], $second['request_id']);
        self::assertSame(1, $this->countRows('ai_requests'));
        self::assertSame(1, $this->countRows('ai_proposals'));
    }

    public function testSameIdempotencyKeyIsIndependentAcrossTenants(): void
    {
        $key = 'http:'.Uuid::v4()->toRfc4122();

        $first = $this->createRequest(Uuid::v4()->toRfc4122(), $key);
        $second = $this->createRequest(Uuid::v4()->toRfc4122(), $key);

        self::assertNotSame($first['request_id'], $second['request_id']);
        self::assertSame(2, $this->countRows('ai_requests'));
    }

    public function testCrossTenantReadsAreDenied(): void
    {
        $body = $this->createRequest();
        $requestId = $body['request_id'];
        $proposalId = $body['proposal']['id'];

        $this->client->request('GET', '/internal/ai/requests/'.$requestId, [], [], $this->server(Uuid::v4()->toRfc4122()));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/internal/ai/proposals/'.$proposalId, [], [], $this->server(Uuid::v4()->toRfc4122()));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDecisionApprovesWithoutPublishing(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        $user = Uuid::v4()->toRfc4122();
        $body = $this->createRequest($tenant);

        $this->client->jsonRequest(
            'POST',
            '/internal/ai/proposals/'.$body['proposal']['id'].'/decision',
            ['decision' => 'approve', 'reason' => 'Validé par le comité produit.'],
            $this->server($tenant, $user),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decided = $this->json();
        self::assertSame('approved', $decided['status']);
        self::assertSame($user, $decided['decided_by']);
        self::assertFalse($decided['published']);
        self::assertSame('forbidden', $decided['publication']);

        $this->client->request('GET', '/internal/ai/requests/'.$body['request_id'], [], [], $this->server($tenant));
        self::assertSame('approved', $this->json()['status']);
    }

    public function testSecondDecisionReturnsConflict(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        $body = $this->createRequest($tenant);
        $uri = '/internal/ai/proposals/'.$body['proposal']['id'].'/decision';

        $this->client->jsonRequest('POST', $uri, ['decision' => 'approve', 'reason' => 'Première décision.'], $this->server($tenant, Uuid::v4()->toRfc4122()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', $uri, ['decision' => 'reject', 'reason' => 'Seconde décision.'], $this->server($tenant, Uuid::v4()->toRfc4122()));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('conflict', $this->json()['error']);
    }

    public function testDecisionRequiresActorHeader(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        $body = $this->createRequest($tenant);

        $this->client->jsonRequest(
            'POST',
            '/internal/ai/proposals/'.$body['proposal']['id'].'/decision',
            ['decision' => 'approve', 'reason' => 'Validé par le comité.'],
            $this->server($tenant),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testDecisionRejectsInvalidDecisionValue(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        $body = $this->createRequest($tenant);

        $this->client->jsonRequest(
            'POST',
            '/internal/ai/proposals/'.$body['proposal']['id'].'/decision',
            ['decision' => 'publish', 'reason' => 'Tentative de publication.'],
            $this->server($tenant, Uuid::v4()->toRfc4122()),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testNoPublishRouteExists(): void
    {
        $body = $this->createRequest();

        $this->client->jsonRequest(
            'POST',
            '/internal/ai/proposals/'.$body['proposal']['id'].'/publish',
            [],
            $this->server(),
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function createRequest(?string $tenant = null, ?string $key = null): array
    {
        $tenant ??= Uuid::v4()->toRfc4122();
        $key ??= 'http:'.Uuid::v4()->toRfc4122();

        $this->client->jsonRequest('POST', '/internal/ai/requests', [
            'prompt' => 'Créer une gestion des tâches avec priorités et échéances.',
            'idempotency_key' => $key,
        ], $this->server($tenant, Uuid::v4()->toRfc4122()));

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 202], 'La création doit réussir : '.$this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @return array<string, string>
     */
    private function server(
        ?string $tenant = null,
        ?string $user = null,
        string $role = 'member',
        ?string $token = 'test-internal-token',
    ): array {
        $server = [
            'HTTP_X_TENANT_ID' => $tenant ?? Uuid::v4()->toRfc4122(),
            'HTTP_X_ACTOR_ROLE' => $role,
        ];

        if (null !== $user) {
            $server['HTTP_X_USER_ID'] = $user;
        }

        if (null !== $token) {
            $server['HTTP_X_INTERNAL_TOKEN'] = $token;
        }

        return $server;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function truncate(): void
    {
        $this->connection->executeStatement(
            'TRUNCATE TABLE ai_proposals, ai_runs, rag_chunks, rag_documents, ai_requests RESTART IDENTITY CASCADE',
        );

        try {
            $this->connection->executeStatement('TRUNCATE TABLE messenger_messages RESTART IDENTITY');
        } catch (\Throwable) {
        }
    }
}
