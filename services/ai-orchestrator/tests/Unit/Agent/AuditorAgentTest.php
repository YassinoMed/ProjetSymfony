<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\AuditorAgent;
use App\Proposal\FeatureDefinitionShapeValidator;
use App\RAG\RagContext;
use App\RAG\RetrievedChunk;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class AuditorAgentTest extends TestCase
{
    private AuditorAgent $auditor;

    protected function setUp(): void
    {
        $this->auditor = new AuditorAgent(new FeatureDefinitionShapeValidator());
    }

    public function testPassesCleanPayload(): void
    {
        $result = $this->auditor->execute(Fixtures::payload(), RagContext::empty());

        self::assertTrue($result->data['passed']);
        self::assertSame(0, $result->data['failures']);
        self::assertCount(6, $result->data['checks']);
        self::assertSame([], $result->data['risks']);
    }

    public function testFailsOnReservedKeys(): void
    {
        $payload = Fixtures::payload();
        $payload['definition']['schema']['properties']['secret'] = ['type' => 'string'];

        $result = $this->auditor->execute($payload, RagContext::empty());

        self::assertFalse($result->data['passed']);
        self::assertSame(1, $result->data['failures']);
        self::assertSame('AUD-02', $result->data['risks'][0]['id']);
        self::assertSame('high', $result->data['risks'][0]['severity']);
    }

    public function testFailsOnPublicationInstruction(): void
    {
        $payload = Fixtures::payload();
        $payload['definition']['ui']['published'] = true;

        $result = $this->auditor->execute($payload, RagContext::empty());

        self::assertFalse($result->data['passed']);
        self::assertContains(
            'AUD-04',
            array_column($result->data['risks'], 'id'),
        );
    }

    public function testFailsOnInjectedRagContext(): void
    {
        $context = new RagContext(
            [new RetrievedChunk('c1', 'd1', 'Doc', 'source', 'contenu', 0.9)],
            [['chunk_id' => 'c2', 'patterns' => ['ignore_previous'], 'severity' => 'high']],
        );

        $result = $this->auditor->execute(Fixtures::payload(), $context);

        self::assertFalse($result->data['passed']);
        self::assertSame('AUD-05', $result->data['risks'][0]['id']);
    }

    public function testWarnsOnSecretVocabulary(): void
    {
        $payload = Fixtures::payload();
        $payload['description'] = 'Stocke le secret de connexion au service distant.';

        $result = $this->auditor->execute($payload, RagContext::empty());

        self::assertTrue($result->data['passed']);
        self::assertSame(1, $result->data['warnings']);
        self::assertSame('medium', $result->data['risks'][0]['severity']);
    }
}
