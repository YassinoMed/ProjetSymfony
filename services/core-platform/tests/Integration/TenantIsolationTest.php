<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLogger;
use App\Entity\AuditLog;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\TenantMembershipRepository;
use App\Tenant\TenantContext;
use App\Tenant\TenantFilter;
use App\Tenant\TenantFilterManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests d'intégration PostgreSQL : filtre tenant, chaîne d'audit et triggers.
 *
 * Les tests sont ignorés si la base de test n'est pas disponible ou si les
 * migrations Phase 2 n'ont pas été exécutées.
 */
final class TenantIsolationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private TenantFilterManager $filterManager;

    private TenantContext $tenantContext;

    private AuditLogger $auditLogger;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->filterManager = $container->get(TenantFilterManager::class);
        $this->tenantContext = $container->get(TenantContext::class);
        $this->auditLogger = $container->get(AuditLogger::class);

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
            self::markTestSkipped('Schéma Phase 2 absent : exécuter les migrations avant les tests d\'intégration.');
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

    public function testTenantFilterScopesMembershipsAndAuditLogs(): void
    {
        [$tenantA, $tenantB, $userA, $userB, $membershipA, $membershipB] = $this->seedTwoTenants();
        $this->persistAudit($tenantA, $userA, 'a-1');
        $this->persistAudit($tenantB, $userB, 'b-1');
        $this->entityManager->flush();

        $this->filterManager->enable($tenantA->getId());
        $this->entityManager->clear();

        /** @var TenantMembershipRepository $memberships */
        $memberships = $this->entityManager->getRepository(TenantMembership::class);
        $visibleMemberships = $memberships->createQueryBuilder('m')
            ->andWhere('m.tenant IN (:tenants)')
            ->setParameter('tenants', [$tenantA->getId(), $tenantB->getId()])
            ->getQuery()
            ->getResult();

        self::assertCount(1, $visibleMemberships);
        self::assertTrue($visibleMemberships[0]->getTenant()->getId()->equals($tenantA->getId()));
        self::assertNull($memberships->find($membershipB->getId()));

        /** @var AuditLogRepository $auditLogs */
        $auditLogs = $this->entityManager->getRepository(AuditLog::class);
        $visibleLogs = $auditLogs->createQueryBuilder('a')
            ->andWhere('a.tenant IN (:tenants)')
            ->setParameter('tenants', [$tenantA->getId(), $tenantB->getId()])
            ->getQuery()
            ->getResult();

        self::assertCount(1, $visibleLogs);
        self::assertSame('a-1', $visibleLogs[0]->getResourceId());

        // Sans tenant, le filtre activé échoue de manière fermée.
        $this->filterManager->disable();
        $this->entityManager->clear();
        $this->entityManager->getFilters()->enable(TenantFilter::NAME);
        self::assertSame(0, (int) $memberships->createQueryBuilder('m')->select('COUNT(m.id)')->getQuery()->getSingleScalarResult());
    }

    public function testAuditLoggerBuildsPerTenantHashChain(): void
    {
        [$tenantA, , , , $membershipA] = $this->seedTwoTenants();
        $this->entityManager->flush();

        $this->tenantContext->setTenant($membershipA);
        $this->filterManager->enable($tenantA->getId());

        $first = $this->auditLogger->log(
            AuditAction::MembershipAdd,
            AuditResult::Success,
            'tenant_membership',
            $membershipA->getId()->toRfc4122(),
            ['role' => MembershipRole::Member->value],
        );
        $second = $this->auditLogger->log(
            AuditAction::MembershipRoleChange,
            AuditResult::Success,
            'tenant_membership',
            $membershipA->getId()->toRfc4122(),
            ['new_role' => MembershipRole::Admin->value],
        );

        self::assertNull($first->getPreviousHash());
        self::assertSame($first->getHash(), $second->getPreviousHash());
        self::assertSame($first->getHash(), $first->computeHash());
        self::assertSame($second->getHash(), $second->computeHash());

        $page = $this->entityManager->getRepository(AuditLog::class)->findPageForTenant($tenantA, 1);
        self::assertCount(2, $page);
    }

    public function testAuditLogUpdateIsRejectedByDatabaseTrigger(): void
    {
        [$tenantA, , $userA] = $this->seedTwoTenants();
        $log = $this->persistAudit($tenantA, $userA, 'immutable');
        $this->entityManager->flush();

        try {
            $this->connection->executeStatement(
                'UPDATE audit_logs SET actor_email = :email WHERE id = :id',
                ['email' => 'tampered@example.com', 'id' => $log->getId()->toRfc4122()],
            );
            self::fail('La mise à jour du journal d\'audit aurait dû être refusée.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        } finally {
            $this->connection->rollBack();
            $this->transactionStarted = false;
            $this->entityManager->clear();
        }
    }

    public function testAuditLogDeleteIsRejectedByDatabaseTrigger(): void
    {
        [$tenantA, , $userA] = $this->seedTwoTenants();
        $log = $this->persistAudit($tenantA, $userA, 'immutable');
        $this->entityManager->flush();

        try {
            $this->connection->executeStatement(
                'DELETE FROM audit_logs WHERE id = :id',
                ['id' => $log->getId()->toRfc4122()],
            );
            self::fail('La suppression du journal d\'audit aurait dû être refusée.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        } finally {
            $this->connection->rollBack();
            $this->transactionStarted = false;
            $this->entityManager->clear();
        }
    }

    /**
     * @return array{Tenant, Tenant, User, User, TenantMembership, TenantMembership}
     */
    private function seedTwoTenants(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Tenant A', 'tenant-a-'.$suffix);
        $tenantB = new Tenant('Tenant B', 'tenant-b-'.$suffix);
        $userA = new User('owner-a-'.$suffix.'@example.com', 'unused');
        $userB = new User('owner-b-'.$suffix.'@example.com', 'unused');
        $membershipA = new TenantMembership($tenantA, $userA, MembershipRole::Owner);
        $membershipB = new TenantMembership($tenantB, $userB, MembershipRole::Owner);

        $this->entityManager->persist($tenantA);
        $this->entityManager->persist($tenantB);
        $this->entityManager->persist($userA);
        $this->entityManager->persist($userB);
        $this->entityManager->persist($membershipA);
        $this->entityManager->persist($membershipB);

        return [$tenantA, $tenantB, $userA, $userB, $membershipA, $membershipB];
    }

    private function persistAudit(Tenant $tenant, User $actor, string $resourceId): AuditLog
    {
        $log = new AuditLog(
            tenant: $tenant,
            actor: $actor,
            actorEmail: $actor->getEmail(),
            action: AuditAction::MembershipAdd,
            result: AuditResult::Success,
            resourceType: 'tenant_membership',
            resourceId: $resourceId,
            context: ['seed' => true],
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
            correlationId: 'correlation-'.$resourceId,
            previousHash: null,
        );

        $this->entityManager->persist($log);

        return $log;
    }
}
