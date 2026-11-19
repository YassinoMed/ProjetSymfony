<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\GovernorAgent;
use App\RAG\RagContext;
use App\RAG\RetrievedChunk;
use PHPUnit\Framework\TestCase;

final class GovernorAgentTest extends TestCase
{
    public function testRequiresHumanApprovalAndForbidsPublication(): void
    {
        $result = (new GovernorAgent())->execute(
            ['passed' => true, 'failures' => 0, 'warnings' => 0],
            RagContext::empty(),
        );

        $policy = $result->data['policy'];

        self::assertSame('awaiting_approval', $policy['status']);
        self::assertTrue($policy['requires_human_approval']);
        self::assertSame('forbidden', $policy['publication']);
        self::assertSame('low', $policy['risk_level']);
    }

    public function testRejectsWhenAuditFailed(): void
    {
        $result = (new GovernorAgent())->execute(
            ['passed' => false, 'failures' => 2, 'warnings' => 0],
            RagContext::empty(),
        );

        $policy = $result->data['policy'];

        self::assertSame('rejected', $policy['status']);
        self::assertSame('high', $policy['risk_level']);
        self::assertTrue($policy['requires_human_approval']);
        self::assertSame('forbidden', $policy['publication']);
    }

    public function testRejectsWhenInjectionDetected(): void
    {
        $context = new RagContext(
            [new RetrievedChunk('c1', 'd1', 'Doc', 'source', 'contenu', 0.9)],
            [['chunk_id' => 'c2', 'patterns' => ['ignore_previous'], 'severity' => 'high']],
        );

        $result = (new GovernorAgent())->execute(
            ['passed' => true, 'failures' => 0, 'warnings' => 0],
            $context,
        );

        self::assertSame('rejected', $result->data['policy']['status']);
        self::assertSame('fail', $result->data['policy']['checks'][3]['status']);
    }

    public function testMediumRiskOnWarnings(): void
    {
        $result = (new GovernorAgent())->execute(
            ['passed' => true, 'failures' => 0, 'warnings' => 1],
            RagContext::empty(),
        );

        self::assertSame('medium', $result->data['policy']['risk_level']);
        self::assertSame('awaiting_approval', $result->data['policy']['status']);
    }
}
