<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Entity\AuditLog;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    public function testHashIsStableAndDetectsTampering(): void
    {
        $tenant = new Tenant('Acme', 'acme');
        $user = new User('admin@example.com');

        $log = new AuditLog(
            tenant: $tenant,
            actor: $user,
            actorEmail: $user->getEmail(),
            action: AuditAction::MembershipAdd,
            result: AuditResult::Success,
            resourceType: 'tenant_membership',
            resourceId: 'resource-id',
            context: ['role' => 'member', 'nested' => ['b' => 2, 'a' => 1]],
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
            correlationId: '0192f0d4-1111-7000-8000-000000000002',
            previousHash: null,
        );

        self::assertSame($log->getHash(), $log->computeHash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $log->getHash());
        self::assertNull($log->getPreviousHash());

        // Toute altération d'un champ doit invalider l'empreinte recalculée.
        $reflection = new \ReflectionProperty(AuditLog::class, 'actorEmail');
        $reflection->setValue($log, 'attaquant@example.com');

        self::assertNotSame($log->getHash(), $log->computeHash());
    }

    public function testChainHashDependsOnPreviousHash(): void
    {
        $tenant = new Tenant('Acme', 'acme');

        $first = $this->log($tenant, null);
        $second = $this->log($tenant, $first->getHash());
        $fork = $this->log($tenant, str_repeat('f', 64));

        $createdAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $property = new \ReflectionProperty(AuditLog::class, 'createdAt');
        $property->setValue($second, $createdAt);
        $property->setValue($fork, $createdAt);

        self::assertSame($first->getHash(), $second->getPreviousHash());
        self::assertNotSame($second->computeHash(), $fork->computeHash());
    }

    private function log(Tenant $tenant, ?string $previousHash): AuditLog
    {
        return new AuditLog(
            tenant: $tenant,
            actor: null,
            actorEmail: 'anonymous',
            action: AuditAction::AuditViewed,
            result: AuditResult::Success,
            resourceType: 'audit_log',
            resourceId: null,
            context: ['page' => 1],
            ipAddress: null,
            userAgent: null,
            correlationId: 'correlation-id',
            previousHash: $previousHash,
        );
    }
}
