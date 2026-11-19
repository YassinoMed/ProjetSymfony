<?php

declare(strict_types=1);

namespace App\Tests\Unit\RAG;

use App\RAG\RagRoles;
use PHPUnit\Framework\TestCase;

final class RagRolesTest extends TestCase
{
    public function testNormalizesCaseWhitespaceAndDuplicates(): void
    {
        self::assertSame(
            ['admin', 'member', 'owner'],
            RagRoles::normalize([' Owner ', 'member', 'admin', 'member']),
        );
    }

    public function testEmptyListIsAllowed(): void
    {
        self::assertSame([], RagRoles::normalize([]));
    }

    public function testRejectsUnknownRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RagRoles::normalize(['root']);
    }

    public function testRejectsNonStringRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RagRoles::normalize([1]);
    }

    public function testAllowedRolesAreTheCoreMembershipRoles(): void
    {
        self::assertSame(['owner', 'admin', 'member', 'auditor'], RagRoles::ALLOWED);
    }
}
