<?php

declare(strict_types=1);

namespace App\RAG;

final class VectorMath
{
    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $length = \count($a);
        if (0 === $length || $length !== \count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; ++$i) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if (0.0 === $normA || 0.0 === $normB) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
