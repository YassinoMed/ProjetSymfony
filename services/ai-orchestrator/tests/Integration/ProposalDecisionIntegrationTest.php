<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Enum\Decision;
use App\Entity\Enum\ProposalStatus;
use App\Entity\Enum\RequestStatus;
use App\Proposal\ProposalConflictException;
use App\Proposal\ProposalDecisionService;
use App\Proposal\ProposalNotFoundException;
use App\Proposal\ProposalPipeline;
use App\Tests\Support\Fixtures;
use Symfony\Component\Uid\Uuid;

final class ProposalDecisionIntegrationTest extends AiDatabaseTestCase
{
    public function testApprovalIsRecordedButNeverPublishes(): void
    {
        [$request, $proposal] = $this->seedProposal();
        $actor = Uuid::v4();

        $decided = $this->decisions()->decide(
            $proposal->getId(),
            $request->getTenantId(),
            $actor,
            Decision::Approve,
            'Validé par le comité produit.',
        );

        self::assertSame(ProposalStatus::Approved, $decided->getStatus());
        self::assertSame($actor->toRfc4122(), $decided->getDecidedBy()?->toRfc4122());
        self::assertNotNull($decided->getDecidedAt());
        self::assertSame('Validé par le comité produit.', $decided->getDecisionReason());
        self::assertSame(RequestStatus::Approved, $request->getStatus());
        self::assertSame('forbidden', $decided->getPolicy()['publication']);
    }

    public function testRejectionIsRecorded(): void
    {
        [$request, $proposal] = $this->seedProposal();

        $decided = $this->decisions()->decide(
            $proposal->getId(),
            $request->getTenantId(),
            Uuid::v4(),
            Decision::Reject,
            'Le besoin métier n’est pas confirmé.',
        );

        self::assertSame(ProposalStatus::Rejected, $decided->getStatus());
        self::assertSame(RequestStatus::Rejected, $request->getStatus());
        self::assertSame('Le besoin métier n’est pas confirmé.', $request->getError());
    }

    public function testSecondDecisionIsRejectedAsConflict(): void
    {
        [$request, $proposal] = $this->seedProposal();
        $decisions = $this->decisions();

        $decisions->decide($proposal->getId(), $request->getTenantId(), Uuid::v4(), Decision::Approve, 'Première décision.');

        $this->expectException(ProposalConflictException::class);
        $decisions->decide($proposal->getId(), $request->getTenantId(), Uuid::v4(), Decision::Reject, 'Seconde décision.');
    }

    public function testDecisionIsRejectedForAnotherTenant(): void
    {
        [$request, $proposal] = $this->seedProposal();

        $this->expectException(ProposalNotFoundException::class);
        $this->decisions()->decide(
            $proposal->getId(),
            Uuid::v4(),
            Uuid::v4(),
            Decision::Approve,
            'Tentative inter-tenant.',
        );
    }

    public function testReasonMustBeBounded(): void
    {
        [$request, $proposal] = $this->seedProposal();

        $this->expectException(\InvalidArgumentException::class);
        $this->decisions()->decide(
            $proposal->getId(),
            $request->getTenantId(),
            Uuid::v4(),
            Decision::Approve,
            'ok',
        );
    }

    /**
     * @return array{0: \App\Entity\AiRequest, 1: \App\Entity\AiProposal}
     */
    private function seedProposal(): array
    {
        $pipeline = static::getContainer()->get(ProposalPipeline::class);

        $request = Fixtures::request();
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $proposal = $pipeline->run($request);

        return [$request, $proposal];
    }

    private function decisions(): ProposalDecisionService
    {
        return static::getContainer()->get(ProposalDecisionService::class);
    }
}
