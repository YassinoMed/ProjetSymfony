<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\DeployerAgent;
use App\Entity\AiProposal;
use App\Entity\Enum\ProposalStatus;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class DeployerAgentTest extends TestCase
{
    public function testPublicationIsAlwaysForbidden(): void
    {
        $result = (new DeployerAgent())->preflight($this->proposal(ProposalStatus::AwaitingApproval));

        self::assertFalse($result->data['allowed']);
        self::assertSame('forbidden', $result->data['publication']);
        self::assertSame('fail', $result->data['checks'][1]['status']);
    }

    public function testApprovedProposalStillCannotBePublished(): void
    {
        $result = (new DeployerAgent())->preflight($this->proposal(ProposalStatus::Approved));

        self::assertFalse($result->data['allowed']);
        self::assertSame('pass', $result->data['checks'][0]['status']);
        self::assertSame('fail', $result->data['checks'][1]['status']);
    }

    public function testRejectedProposalFailsPreflight(): void
    {
        $result = (new DeployerAgent())->preflight($this->proposal(ProposalStatus::Rejected));

        self::assertSame('fail', $result->data['checks'][0]['status']);
        self::assertFalse($result->data['allowed']);
    }

    private function proposal(ProposalStatus $status): AiProposal
    {
        return new AiProposal(
            request: Fixtures::request(),
            status: $status,
            payload: Fixtures::payload(),
            rationale: ['summary' => 'test'],
            risks: [],
            citations: [],
            policy: ['publication' => 'forbidden'],
            agentTrace: [],
            providerMeta: [],
        );
    }
}
