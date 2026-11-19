<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\TenantMembershipRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parcours HTTP Phase 2 : isolation, RBAC, CSRF, politiques de membres et audit.
 *
 * Les écritures sont encapsulées dans une transaction annulée en fin de test.
 */
final class MembershipSecurityTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private bool $transactionStarted = false;

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
                'tenants', 'users', 'tenant_memberships', 'audit_logs',
            ]);
        } catch (\Throwable $exception) {
            self::markTestSkipped('Schéma de test indisponible : '.$exception->getMessage());
        }

        if (!$tablesExist) {
            self::markTestSkipped('Schéma Phase 2 absent : exécuter les migrations avant les tests.');
        }

        $this->connection->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            $this->entityManager->clear();
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }

        parent::tearDown();
    }

    public function testOwnerCanAddMemberAndAuditEntryIsWritten(): void
    {
        [$tenant, $owner, , $newUser] = $this->seedTenantWithActors();
        $this->client->loginUser($owner);

        $crawler = $this->get('/tenant/members', $tenant);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="add_member"]');

        $form = $crawler->selectButton('Ajouter au tenant')->form();
        $form['add_member[email]'] = $newUser->getEmail();
        $form['add_member[role]'] = MembershipRole::Member->value;
        $this->client->submit($form);

        self::assertResponseRedirects('/tenant/members');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'a été ajouté');

        $this->entityManager->clear();
        $membership = $this->entityManager->getRepository(TenantMembership::class)->findOneBy([
            'tenant' => $tenant,
            'user' => $newUser,
        ]);
        self::assertNotNull($membership);
        self::assertSame(MembershipRole::Member, $membership->getRole());
        self::assertTrue($membership->isActive());

        /** @var AuditLogRepository $logs */
        $logs = $this->entityManager->getRepository(AuditLog::class);
        $entries = $logs->findPageForTenant($tenant, 1);
        self::assertNotEmpty($entries);
        self::assertSame(AuditAction::MembershipAdd, $entries[0]->getAction());
        self::assertSame(AuditResult::Success, $entries[0]->getResult());
    }

    public function testMemberCannotAddMember(): void
    {
        [$tenant, , $member, $newUser] = $this->seedTenantWithActors();
        $this->client->loginUser($member);

        $this->post('/tenant/members', $tenant, [
            'add_member' => [
                'email' => $newUser->getEmail(),
                'role' => MembershipRole::Member->value,
            ],
            '_token' => 'csrf-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(TenantMembership::class)->findOneBy([
            'tenant' => $tenant,
            'user' => $newUser,
        ]));
    }

    public function testAdminCannotDemoteOwner(): void
    {
        [$tenant, $owner, , , $admin, $ownerMembership] = $this->seedTenantWithActors();
        $this->client->loginUser($admin);

        // Le formulaire du owner n'est pas rendu pour un admin (défense UI),
        // mais le jeton CSRF est partagé par identifiant de formulaire.
        $crawler = $this->get('/tenant/members', $tenant);
        $token = $crawler->filter('input[name="change_member_role[_token]"]')->first()->attr('value');
        self::assertIsString($token);

        $this->post('/tenant/members/'.$ownerMembership->getId()->toRfc4122().'/role', $tenant, [
            'change_member_role' => [
                'role' => MembershipRole::Member->value,
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->entityManager->clear();
        $stored = $this->entityManager->find(TenantMembership::class, $ownerMembership->getId());
        self::assertNotNull($stored);
        self::assertSame(MembershipRole::Owner, $stored->getRole());

        /** @var AuditLogRepository $logs */
        $logs = $this->entityManager->getRepository(AuditLog::class);
        $entries = $logs->findPageForTenant($tenant, 1);
        self::assertNotEmpty($entries);
        self::assertSame(AuditResult::Denied, $entries[0]->getResult());
    }

    public function testCrossTenantMembershipIsNotFound(): void
    {
        [$tenantA, $ownerA, , , , , $tenantB, , $membershipB] = $this->seedTwoTenants();
        $this->client->loginUser($ownerA);

        $this->post('/tenant/members/'.$membershipB->getId()->toRfc4122().'/role', $tenantA, [
            'change_member_role' => ['role' => MembershipRole::Member->value],
            '_token' => 'csrf-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOwnerCannotRemoveSelf(): void
    {
        [$tenant, $owner, , , , $ownerMembership] = $this->seedTenantWithActors();
        $this->client->loginUser($owner);

        $this->post('/tenant/members/'.$ownerMembership->getId()->toRfc4122().'/remove', $tenant, [
            // `remove_member` est un jeton CSRF stateless : la valeur est
            // constante et la requête est validée par la même origine.
            'remove_member' => ['_token' => 'csrf-token'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->entityManager->clear();
        $stored = $this->entityManager->find(TenantMembership::class, $ownerMembership->getId());
        self::assertNotNull($stored);
        self::assertTrue($stored->isActive());
    }

    public function testMemberCannotReadAuditButOwnerCan(): void
    {
        [$tenant, $owner, $member] = $this->seedTenantWithActors();
        $this->loginAs($member);
        $this->get('/audit', $tenant);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->loginAs($owner);
        $this->get('/audit', $tenant);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Journal d\'audit');
    }

    public function testAuditPageNeverShowsAnotherTenantEntries(): void
    {
        [$tenantA, $ownerA, , , , , $tenantB] = $this->seedTwoTenants();

        $this->entityManager->persist($this->auditFor($tenantA, 'VISIBLE-A'));
        $this->entityManager->persist($this->auditFor($tenantB, 'SECRET-B'));
        $this->entityManager->flush();

        $this->client->loginUser($ownerA);
        $this->get('/audit', $tenantA);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('VISIBLE-A', $content);
        self::assertStringNotContainsString('SECRET-B', $content);
    }

    public function testAuditRateLimitReturns429AfterSixtyRequests(): void
    {
        [$tenant, $owner] = $this->seedTenantWithActors();
        $this->client->loginUser($owner);

        for ($i = 0; $i < 60; ++$i) {
            $this->get('/audit', $tenant);
            self::assertResponseIsSuccessful(sprintf('Requête %d', $i + 1));
        }

        $this->get('/audit', $tenant);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * @return array{Tenant, User, User, User, User, TenantMembership}
     */
    private function seedTenantWithActors(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Acme', 'acme-'.$suffix);
        $owner = new User('owner-'.$suffix.'@example.com', 'unused');
        $member = new User('member-'.$suffix.'@example.com', 'unused');
        $admin = new User('admin-'.$suffix.'@example.com', 'unused');
        $newUser = new User('new-'.$suffix.'@example.com', 'unused');
        $ownerMembership = new TenantMembership($tenant, $owner, MembershipRole::Owner);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($owner);
        $this->entityManager->persist($member);
        $this->entityManager->persist($admin);
        $this->entityManager->persist($newUser);
        $this->entityManager->persist($ownerMembership);
        $this->entityManager->persist(new TenantMembership($tenant, $member, MembershipRole::Member));
        $this->entityManager->persist(new TenantMembership($tenant, $admin, MembershipRole::Admin));
        $this->entityManager->flush();

        return [$tenant, $owner, $member, $newUser, $admin, $ownerMembership];
    }

    /**
     * @return array{Tenant, User, User, User, User, TenantMembership, Tenant, User, TenantMembership}
     */
    private function seedTwoTenants(): array
    {
        [$tenantA, $ownerA, , , , $membershipA] = $this->seedTenantWithActors();
        $suffix = bin2hex(random_bytes(5));
        $tenantB = new Tenant('Beta', 'beta-'.$suffix);
        $ownerB = new User('owner-b-'.$suffix.'@example.com', 'unused');
        $membershipB = new TenantMembership($tenantB, $ownerB, MembershipRole::Owner);

        $this->entityManager->persist($tenantB);
        $this->entityManager->persist($ownerB);
        $this->entityManager->persist($membershipB);
        $this->entityManager->flush();

        return [$tenantA, $ownerA, null, null, null, $membershipA, $tenantB, $ownerB, $membershipB];
    }

    private function auditFor(Tenant $tenant, string $resourceId): AuditLog
    {
        return new AuditLog(
            tenant: $tenant,
            actor: null,
            actorEmail: 'seed@example.com',
            action: AuditAction::MembershipAdd,
            result: AuditResult::Success,
            resourceType: 'tenant_membership',
            resourceId: $resourceId,
            context: ['seed' => true],
            ipAddress: null,
            userAgent: 'phpunit',
            correlationId: 'correlation-'.$resourceId,
            previousHash: null,
        );
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user);
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
}
