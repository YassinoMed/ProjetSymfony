<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\UserStatus;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testConstructorNormalizesEmailAndRoles(): void
    {
        $user = new User('  John.Doe@Example.COM ', '', ['tenant_admin', 'ROLE_AUDITOR']);

        self::assertSame('john.doe@example.com', $user->getEmail());
        self::assertSame('john.doe@example.com', $user->getUserIdentifier());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertContains('ROLE_TENANT_ADMIN', $user->getRoles());
        self::assertContains('ROLE_AUDITOR', $user->getRoles());
        self::assertTrue($user->isActive());
        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertSame($user->getCreatedAt(), $user->getUpdatedAt());
    }

    public function testSuspension(): void
    {
        $user = new User('user@example.com');

        self::assertTrue($user->isActive());

        $user->setStatus(UserStatus::Suspended);

        self::assertFalse($user->isActive());
        self::assertSame(UserStatus::Suspended, $user->getStatus());
    }

    public function testRolesAreUniqueAndUppercased(): void
    {
        $user = new User('roles@example.com', '', ['role_auditor', 'ROLE_AUDITOR', '']);

        self::assertSame(['ROLE_AUDITOR', 'ROLE_USER'], $user->getRoles());
    }
}
