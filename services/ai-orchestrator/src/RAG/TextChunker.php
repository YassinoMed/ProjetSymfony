<?php

declare(strict_types=1);

namespace App\RAG;

/**
 * Découpage déterministe en fragments bornés.
 *
 * Le découpage privilégie les paragraphes puis les phrases ; les textes sans
 * ponctuation sont découpés durement avec un chevauchement borné.
 */
final class TextChunker
{
    private const int MAX_CHUNKS = 100;

    /**
     * @return list<string>
     */
    public function chunk(string $text, int $size = 800, int $overlap = 120): array
    {
        if ($size < 100) {
            throw new \InvalidArgumentException('La taille de fragment doit être au moins 100.');
        }

        $overlap = max(0, min($overlap, intdiv($size, 2)));
        $text = trim((string) preg_replace("/\r\n?/", "\n", $text));
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        if ('' === $text) {
            return [];
        }

        $chunks = [];
        $current = '';
        $paragraphs = preg_split("/\n\s*\n/", $text) ?: [$text];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ('' === $paragraph) {
                continue;
            }

            if (mb_strlen($current) + mb_strlen($paragraph) + 2 <= $size) {
                $current = '' === $current ? $paragraph : $current."\n\n".$paragraph;
                continue;
            }

            if ('' !== $current) {
                $chunks[] = $current;
                $current = '';
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ('' === $sentence) {
                    continue;
                }

                if (mb_strlen($sentence) > $size) {
                    foreach ($this->hardSplit($sentence, $size, $overlap) as $piece) {
                        $chunks[] = $piece;
                    }

                    continue;
                }

                if (mb_strlen($current) + mb_strlen($sentence) + 1 <= $size) {
                    $current = '' === $current ? $sentence : $current.' '.$sentence;
                } else {
                    if ('' !== $current) {
                        $chunks[] = $current;
                    }
                    $current = $sentence;
                }
            }
        }

        if ('' !== $current) {
            $chunks[] = $current;
        }

        $chunks = array_values(array_filter(
            array_map(static fn (string $chunk): string => trim($chunk), $chunks),
            static fn (string $chunk): bool => '' !== $chunk,
        ));

        return \array_slice($chunks, 0, self::MAX_CHUNKS);
    }

    /**
     * @return list<string>
     */
    private function hardSplit(string $text, int $size, int $overlap): array
    {
        $pieces = [];
        $length = mb_strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $piece = trim(mb_substr($text, $offset, $size));
            if ('' !== $piece) {
                $pieces[] = $piece;
            }

            if ($offset + $size >= $length) {
                break;
            }

            $offset += max(1, $size - $overlap);
        }

        return $pieces;
    }
}
