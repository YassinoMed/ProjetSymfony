<?php

declare(strict_types=1);

namespace App\Tests\Unit\RAG;

use App\RAG\TextChunker;
use PHPUnit\Framework\TestCase;

final class TextChunkerTest extends TestCase
{
    private TextChunker $chunker;

    protected function setUp(): void
    {
        $this->chunker = new TextChunker();
    }

    public function testReturnsEmptyForBlankText(): void
    {
        self::assertSame([], $this->chunker->chunk("   \n\n  "));
    }

    public function testKeepsShortTextAsSingleChunk(): void
    {
        $chunks = $this->chunker->chunk('Une phrase courte.');

        self::assertSame(['Une phrase courte.'], $chunks);
    }

    public function testSplitsOnParagraphs(): void
    {
        $text = str_repeat('Premier paragraphe. ', 10)."\n\n".str_repeat('Second paragraphe. ', 10);

        $chunks = $this->chunker->chunk($text, 120, 20);

        self::assertGreaterThan(1, \count($chunks));
        self::assertStringContainsString('Premier paragraphe.', $chunks[0]);
    }

    public function testHardSplitsLongUnpunctuatedText(): void
    {
        $text = str_repeat('a', 1500);

        $chunks = $this->chunker->chunk($text, 400, 50);

        self::assertGreaterThan(1, \count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(400, mb_strlen($chunk));
        }
    }

    public function testRejectsTooSmallSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chunker->chunk('texte', 50);
    }

    public function testCapsChunkCount(): void
    {
        $text = implode("\n\n", array_fill(0, 150, 'Paragraphe indépendant numéro '.uniqid()));

        $chunks = $this->chunker->chunk($text, 100, 0);

        self::assertLessThanOrEqual(100, \count($chunks));
    }

    public function testOutputIsDeterministic(): void
    {
        $text = str_repeat('Une phrase de test. ', 40);

        self::assertSame($this->chunker->chunk($text), $this->chunker->chunk($text));
    }
}
