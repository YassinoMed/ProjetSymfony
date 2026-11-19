<?php

declare(strict_types=1);

namespace App\Tests\Unit\RAG;

use App\RAG\Embedding\DeterministicEmbeddingProvider;
use App\RAG\Embedding\EmbeddingProviderSelector;
use App\RAG\Embedding\OllamaEmbeddingProvider;
use App\RAG\VectorMath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EmbeddingTest extends TestCase
{
    public function testDeterministicProviderHasStableDimensionAndValues(): void
    {
        $provider = new DeterministicEmbeddingProvider();

        $first = $provider->embed('Gestion des tâches et des priorités');
        $second = $provider->embed('Gestion des tâches et des priorités');

        self::assertCount(DeterministicEmbeddingProvider::DIMENSIONS, $first);
        self::assertSame($first, $second);
        self::assertSame('deterministic-v1', $provider->name());
    }

    public function testDeterministicProviderIsNormalized(): void
    {
        $vector = (new DeterministicEmbeddingProvider())->embed('facturation clients');

        self::assertEqualsWithDelta(1.0, VectorMath::cosine($vector, $vector), 0.0001);
    }

    public function testEmptyTextProducesZeroVector(): void
    {
        $vector = (new DeterministicEmbeddingProvider())->embed('');

        self::assertSame(array_fill(0, DeterministicEmbeddingProvider::DIMENSIONS, 0.0), $vector);
        self::assertSame(0.0, VectorMath::cosine($vector, $vector));
    }

    public function testSelectorUsesDeterministicByDefault(): void
    {
        $selector = new EmbeddingProviderSelector(
            new DeterministicEmbeddingProvider(),
            $this->ollamaProvider('http://127.0.0.1:11434', true),
            'deterministic',
        );

        self::assertSame('deterministic-v1', $selector->name());
        self::assertCount(DeterministicEmbeddingProvider::DIMENSIONS, $selector->embed('texte'));
    }

    public function testSelectorUsesOllamaWhenConfigured(): void
    {
        $selector = new EmbeddingProviderSelector(
            new DeterministicEmbeddingProvider(),
            $this->ollamaProvider('http://127.0.0.1:11434', true, [0.1, 0.2, 0.3]),
            'ollama',
        );

        self::assertSame('ollama:nomic-embed-text', $selector->name());
        self::assertSame([0.1, 0.2, 0.3], $selector->embed('texte'));
    }

    public function testSelectorFallsBackWhenOllamaDisabled(): void
    {
        $selector = new EmbeddingProviderSelector(
            new DeterministicEmbeddingProvider(),
            $this->ollamaProvider('http://127.0.0.1:11434', false),
            'ollama',
        );

        self::assertSame('deterministic-v1', $selector->name());
    }

    /**
     * @param list<float> $vector
     */
    private function ollamaProvider(string $baseUrl, bool $enabled, array $vector = [1.0]): OllamaEmbeddingProvider
    {
        $httpClient = new MockHttpClient(
            new MockResponse(json_encode(['embedding' => $vector], JSON_THROW_ON_ERROR)),
        );

        return new OllamaEmbeddingProvider($httpClient, $baseUrl, 'nomic-embed-text', $enabled);
    }
}
