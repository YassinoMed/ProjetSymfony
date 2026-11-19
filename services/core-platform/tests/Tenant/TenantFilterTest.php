<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Tenant\TenantFilter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

final class TenantFilterTest extends TestCase
{
    public function testNonTenantOwnedEntityIsNotFiltered(): void
    {
        $filter = $this->filter();

        self::assertSame('', $filter->addFilterConstraint(new ClassMetadata(Tenant::class), 't0'));
    }

    public function testEnabledWithoutTenantFailsClosed(): void
    {
        $filter = $this->filter();

        self::assertSame('1 = 0', $filter->addFilterConstraint(new ClassMetadata(TenantMembership::class), 'm0'));
    }

    public function testTenantConstraintUsesQuotedUuid(): void
    {
        $filter = $this->filter();
        $filter->setTenantId('0192f0d4-1111-7000-8000-000000000001');

        self::assertSame(
            "m0.tenant_id = '0192f0d4-1111-7000-8000-000000000001'",
            $filter->addFilterConstraint(new ClassMetadata(TenantMembership::class), 'm0'),
        );
    }

    private function filter(): TenantFilter
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('quote')->willReturnCallback(
            static fn (string $value): string => "'".$value."'",
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return new TenantFilter($entityManager);
    }
}
