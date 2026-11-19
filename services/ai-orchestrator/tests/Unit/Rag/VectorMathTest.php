<?php

declare(strict_types=1);

namespace App\Tests\Unit\RAG;

use App\RAG\VectorMath;
use PHPUnit\Framework\TestCase;

final class VectorMathTest extends TestCase
{
    public function testIdenticalVectorsHaveCosineOne(): void
    {
        self::assertEqualsWithDelta(1.0, VectorMath::cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 0.000001);
    }

    public function testOrthogonalVectorsHaveCosineZero(): void
    {
        self::assertSame(0.0, VectorMath::cosine([1.0, 0.0], [0.0, 1.0]));
    }

    public function testOppositeVectorsHaveCosineMinusOne(): void
    {
        self::assertEqualsWithDelta(-1.0, VectorMath::cosine([1.0, 2.0], [-1.0, -2.0]), 0.000001);
    }

    public function testZeroVectorReturnsZero(): void
    {
        self::assertSame(0.0, VectorMath::cosine([0.0, 0.0], [1.0, 2.0]));
    }

    public function testDimensionMismatchReturnsZero(): void
    {
        self::assertSame(0.0, VectorMath::cosine([1.0], [1.0, 2.0]));
    }

    public function testEmptyVectorsReturnZero(): void
    {
        self::assertSame(0.0, VectorMath::cosine([], []));
    }

    public function testScaleInvariance(): void
    {
        self::assertEqualsWithDelta(
            VectorMath::cosine([1.0, 2.0], [3.0, 4.0]),
            VectorMath::cosine([10.0, 20.0], [3.0, 4.0]),
            0.000001,
        );
    }
}
