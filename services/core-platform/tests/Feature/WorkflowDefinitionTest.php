<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Entity\Enum\MembershipRole;
use App\Feature\WorkflowDefinition;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionTest extends TestCase
{
    public function testServerSideGuardsCheckStateTransitionAndRole(): void
    {
        $workflow = $this->workflow();

        self::assertSame('draft', $workflow->initial());
        self::assertSame('Ouvert', $workflow->stateLabel('open'));
        self::assertTrue($workflow->canTransition('draft', 'open', MembershipRole::Member));
        self::assertTrue($workflow->canTransition('draft', 'open', MembershipRole::Admin));
        self::assertFalse($workflow->canTransition('draft', 'open', MembershipRole::Auditor));
        self::assertFalse($workflow->canTransition('open', 'closed', MembershipRole::Member));
        self::assertTrue($workflow->canTransition('open', 'closed', MembershipRole::Owner));
        self::assertFalse($workflow->canTransition('draft', 'closed', MembershipRole::Owner));
        self::assertFalse($workflow->canTransition('unknown', 'open', MembershipRole::Owner));
    }

    public function testAvailableTransitionsAreFilteredByRole(): void
    {
        $workflow = $this->workflow();

        $member = $workflow->availableTransitions('draft', MembershipRole::Member);
        self::assertCount(1, $member);
        self::assertSame('open', $member[0]->to);

        $owner = $workflow->availableTransitions('open', MembershipRole::Owner);
        self::assertCount(1, $owner);
        self::assertSame('closed', $owner[0]->to);

        self::assertSame([], $workflow->availableTransitions('closed', MembershipRole::Owner));
    }

    public function testRejectsUnknownRole(): void
    {
        $definition = $this->workflowDefinition();
        $definition['transitions'][0]['roles'] = ['owner', 'superuser'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('superuser');

        new WorkflowDefinition($definition);
    }

    public function testRejectsTransitionToUnknownState(): void
    {
        $definition = $this->workflowDefinition();
        $definition['transitions'][0]['to'] = 'missing';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('état inconnu');

        new WorkflowDefinition($definition);
    }

    private function workflow(): WorkflowDefinition
    {
        return new WorkflowDefinition($this->workflowDefinition());
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowDefinition(): array
    {
        return [
            'initial' => 'draft',
            'states' => [
                'draft' => ['label' => 'Brouillon'],
                'open' => ['label' => 'Ouvert'],
                'closed' => ['label' => 'Fermé'],
            ],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'open',
                    'label' => 'Ouvrir',
                    'roles' => ['owner', 'admin', 'member'],
                ],
                [
                    'from' => 'open',
                    'to' => 'closed',
                    'label' => 'Fermer',
                    'roles' => ['owner', 'admin'],
                ],
            ],
        ];
    }
}
