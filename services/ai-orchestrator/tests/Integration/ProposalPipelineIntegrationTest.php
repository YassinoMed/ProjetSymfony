<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agent\ArchitectAgent;
use App\Agent\AuditorAgent;
use App\Agent\BuilderAgent;
use App\Agent\GovernorAgent;
use App\Entity\Enum\ProposalStatus;
use App\Entity\Enum\RequestStatus;
use App\Entity\Enum\RunStatus;
use App\Proposal\ArchitectSpecificationValidator;
use App\Proposal\ProposalPipeline;
use App\Provider\AiProviderInterface;
use App\Provider\ProviderRegistry;
use App\Provider\ProviderRequest;
use App\Provider\ProviderResponse;
use App\Provider\ProviderUnavailableException;
use App\RAG\RagRetriever;
use App\Repository\AiProposalRepository;
use App\Repository\AiRunRepository;
use App\Tests\Support\Fixtures;
use Symfony\Component\Uid\Uuid;

final class ProposalPipelineIntegrationTest extends AiDatabaseTestCase
{
    public function testPipelineProducesUnpublishedProposalAwaitingApproval(): void
    {
        $pipeline = static::getContainer()->get(ProposalPipeline::class);

        $request = Fixtures::request();
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $proposal = $pipeline->run($request);

        self::assertSame(ProposalStatus::AwaitingApproval, $proposal->getStatus());
        self::assertSame(RequestStatus::AwaitingApproval, $request->getStatus());
        self::assertSame('feature_definition', $proposal->getPayload()['type']);
        self::assertArrayNotHasKey('published', $proposal->getPayload());

        $policy = $proposal->getPolicy();
        self::assertSame('forbidden', $policy['publication']);
        self::assertTrue($policy['requires_human_approval']);
        self::assertSame('awaiting_approval', $policy['status']);
        self::assertSame('low', $policy['risk_level']);

        $trace = $proposal->getAgentTrace();
        self::assertSame(['architect', 'builder', 'auditor', 'governor'], array_column($trace, 'agent'));
        self::assertSame(
            ['succeeded', 'succeeded', 'succeeded', 'succeeded'],
            array_column($trace, 'status'),
        );

        self::assertSame('stub', $proposal->getProviderMeta()['provider']);
        self::assertSame('deterministic-v1', $proposal->getProviderMeta()['model']);
        self::assertArrayHasKey('decisions', $proposal->getRationale());
        self::assertCount(4, $proposal->getRationale()['decisions']);
        self::assertSame([], $proposal->getCitations());
    }

    public function testPipelineIsIdempotentForTheSameRequest(): void
    {
        $pipeline = static::getContainer()->get(ProposalPipeline::class);

        $request = Fixtures::request();
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $first = $pipeline->run($request);
        $second = $pipeline->run($request);

        self::assertSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());

        $proposals = static::getContainer()->get(AiProposalRepository::class);
        self::assertSame(1, $proposals->count([]));
    }

    public function testProviderFailureMarksRequestAndRunAsFailed(): void
    {
        $container = static::getContainer();
        $em = $this->entityManager;

        $failingProvider = new class implements AiProviderInterface {
            public function name(): string
            {
                return 'failing';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function complete(ProviderRequest $request): ProviderResponse
            {
                throw new ProviderUnavailableException('Fournisseur simulé indisponible.');
            }
        };

        $architect = new ArchitectAgent(
            new ProviderRegistry([$failingProvider], 'failing'),
            new ArchitectSpecificationValidator(),
        );

        $pipeline = new ProposalPipeline(
            $em,
            $container->get(AiProposalRepository::class),
            $container->get(AiRunRepository::class),
            $container->get(RagRetriever::class),
            $architect,
            $container->get(BuilderAgent::class),
            $container->get(AuditorAgent::class),
            $container->get(GovernorAgent::class),
        );

        $request = Fixtures::request();
        $em->persist($request);
        $em->flush();

        try {
            $pipeline->run($request);
            self::fail('Une panne de fournisseur doit interrompre le pipeline.');
        } catch (ProviderUnavailableException $exception) {
            self::assertStringContainsString('Fournisseur simulé indisponible.', $exception->getMessage());
        }

        $em->clear();
        $reloaded = $container->get(\App\Repository\AiRequestRepository::class)->find($request->getId());

        self::assertNotNull($reloaded);
        self::assertSame(RequestStatus::Failed, $reloaded->getStatus());
        self::assertStringContainsString('Fournisseur simulé indisponible.', (string) $reloaded->getError());

        $runs = $container->get(AiRunRepository::class)->findForRequest($reloaded);
        self::assertCount(1, $runs);
        self::assertSame(RunStatus::Failed, $runs[0]->getStatus());
        self::assertSame('architect', $runs[0]->getAgent()->value);
    }

    public function testProposalIsScopedToTenant(): void
    {
        $pipeline = static::getContainer()->get(ProposalPipeline::class);
        $proposals = static::getContainer()->get(AiProposalRepository::class);

        $tenant = Uuid::v4();
        $request = Fixtures::request($tenant);
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $proposal = $pipeline->run($request);

        self::assertNotNull($proposals->findForTenant($proposal->getId(), $tenant));
        self::assertNull($proposals->findForTenant($proposal->getId(), Uuid::v4()));
    }
}
