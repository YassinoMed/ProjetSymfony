<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\TenantStatus;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class TenantTest extends TestCase
{
    public function testConstructorNormalizesSlug(): void
    {
        $tenant = new Tenant('  Acme Corp ', ' ACME-Corp ');

        self::assertSame('Acme Corp', $tenant->getName());
        self::assertSame('acme-corp', $tenant->getSlug());
        self::assertTrue($tenant->isActive());
        self::assertSame(TenantStatus::Active, $tenant->getStatus());
    }

    public function testSuspension(): void
    {
        $tenant = new Tenant('Acme', 'acme');

        $tenant->setStatus(TenantStatus::Suspended);

        self::assertFalse($tenant->isActive());
    }
}
