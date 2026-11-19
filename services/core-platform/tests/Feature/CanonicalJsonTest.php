<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Feature\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function testAssociativeKeysAreSortedRecursively(): void
    {
        $value = [
            'workflow' => ['b' => 2, 'a' => 1],
            'schema' => [
                'properties' => [
                    'titre' => ['maxLength' => 120, 'type' => 'string'],
                ],
                'type' => 'object',
            ],
            'ui' => ['per_page' => 25],
        ];

        self::assertSame(
            '{"schema":{"properties":{"titre":{"maxLength":120,"type":"string"}},"type":"object"},"ui":{"per_page":25},"workflow":{"a":1,"b":2}}',
            CanonicalJson::encode($value),
        );
    }

    public function testListOrderIsPreserved(): void
    {
        self::assertSame('[3,1,2]', CanonicalJson::encode([3, 1, 2]));
        self::assertSame(
            '{"required":["b","a"]}',
            CanonicalJson::encode(['required' => ['b', 'a']]),
        );
    }

    public function testHashIsIndependentFromKeyOrder(): void
    {
        $first = ['b' => ['x' => true, 'y' => [1, 2]], 'a' => 'é'];
        $second = ['a' => 'é', 'b' => ['y' => [1, 2], 'x' => true]];

        self::assertSame(CanonicalJson::hash($first), CanonicalJson::hash($second));
        self::assertSame(64, strlen(CanonicalJson::hash($first)));
        self::assertSame(1, preg_match('/^[a-f0-9]{64}$/', CanonicalJson::hash($first)));
    }

    public function testHashChangesWhenOrderedListChanges(): void
    {
        self::assertNotSame(
            CanonicalJson::hash(['required' => ['a', 'b']]),
            CanonicalJson::hash(['required' => ['b', 'a']]),
        );
    }

    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        self::assertSame(
            '{"label":"Échéance / priorité"}',
            CanonicalJson::encode(['label' => 'Échéance / priorité']),
        );
    }
}
