<?php

declare(strict_types=1);

namespace App\RAG\Embedding;

/**
 * Embedding déterministe par hachage de tokens.
 *
 * Ce fournisseur ne prétend pas capturer la sémantique : il garantit un
 * comportement reproductible, sans réseau, pour les tests et le développement.
 * La similarité cosinus reste calculée de la même façon qu'avec un vrai modèle.
 */
final class DeterministicEmbeddingProvider implements EmbeddingProviderInterface
{
    public const int DIMENSIONS = 64;

    public function name(): string
    {
        return 'deterministic-v1';
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS;
    }

    public function embed(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }

            $hash = crc32($token);
            $index = $hash % self::DIMENSIONS;
            $sign = 0 === (($hash >> 8) & 1) ? 1.0 : -1.0;
            $vector[$index] += $sign;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }

        if (0.0 === $norm) {
            return $vector;
        }

        $norm = sqrt($norm);
        foreach ($vector as $index => $value) {
            $vector[$index] = round($value / $norm, 8);
        }

        return $vector;
    }
}
