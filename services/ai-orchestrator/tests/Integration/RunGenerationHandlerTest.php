<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agent\ArchitectAgent;
use App\Agent\AuditorAgent;
use App\Agent\BuilderAgent;
use App\Agent\GovernorAgent;
use App\Entity\Enum\RequestStatus;
use App\Message\RunGenerationHandler;
use App\Message\RunGenerationRequest;
use App\Proposal\ArchitectSpecificationValidator;
use App\Proposal\ProposalPipeline;
use App\Provider\AiProviderInterface;
use App\Provider\ProviderRegistry;
use App\Provider\ProviderRequest;
use App\Provider\ProviderResponse;
use App\Provider\ProviderUnavailableException;
use App\RAG\RagRetriever;
use App\Repository\AiProposalRepository;
use App\Repository\AiRequestRepository;
use App\Repository\AiRunRepository;
use App\Tests\Support\Fixtures;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class RunGenerationHandlerTest extends AiDatabaseTestCase
{
    public function testHandlerRunsPipelineOnceAndIgnoresDuplicates(): void
    {
        $handler = static::getContainer()->get(RunGenerationHandler::class);
        $requests = static::getContainer()->get(AiRequestRepository::class);
        $proposals = static::getContainer()->get(AiProposalRepository::class);

        $request = Fixtures::request();
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $message = new RunGenerationRequest($request->getId()->toRfc4122());
        $handler($message);

        self::assertSame(RequestStatus::AwaitingApproval, $request->getStatus());
        self::assertSame(1, $proposals->count([]));

        $handler($message);

        self::assertSame(1, $proposals->count([]));
        self::assertSame(RequestStatus::AwaitingApproval, $request->getStatus());
        self::assertNull($requests->claim($request->getId()));
    }

    public function testUnknownRequestIsIgnored(): void
    {
        $handler = static::getContainer()->get(RunGenerationHandler::class);

        $handler(new RunGenerationRequest(Uuid::v4()->toRfc4122()));

        self::assertSame(0, static::getContainer()->get(AiProposalRepository::class)->count([]));
    }

    public function testProviderFailureIsRetriedThenFailsDefinitively(): void
    {
        $container = static::getContainer();
        $requests = $container->get(AiRequestRepository::class);

        $handler = new RunGenerationHandler(
            $requests,
            $this->failingPipeline(),
            $this->entityManager,
            new NullLogger(),
            3,
        );

        $request = Fixtures::request();
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $message = new RunGenerationRequest($request->getId()->toRfc4122());

        try {
            $handler($message);
            self::fail('La première panne doit programmer une nouvelle tentative.');
        } catch (ProviderUnavailableException) {
        }

        self::assertSame(RequestStatus::Queued, $request->getStatus());
        self::assertSame(1, $request->getAttempts());

        try {
            $handler($message);
            self::fail('La deuxième panne doit programmer une nouvelle tentative.');
        } catch (ProviderUnavailableException) {
        }

        self::assertSame(RequestStatus::Queued, $request->getStatus());
        self::assertSame(2, $request->getAttempts());

        $handler($message);

        self::assertSame(RequestStatus::Failed, $request->getStatus());
        self::assertSame(2, $request->getAttempts());
        self::assertStringContainsString('Fournisseur simulé indisponible.', (string) $request->getError());
    }

    public function testUnexpectedFailureMarksRequestFailed(): void
    {
        $container = static::getContainer();

        $pipeline = new class(
            $this->entityManager,
            $container->get(AiProposalRepository::class),
            $container->get(AiRunRepository::class),
            $container->get(RagRetriever::class),
            $container->get(ArchitectAgent::class),
            $container->get(BuilderAgent::class),
            $container->get(AuditorAgent::class),
            $container->get(GovernorAgent::class),
        ) extends ProposalPipeline {
            public function run(\App\Entity\AiRequest $request): \App\Entity\AiProposal
            {
                throw new \RuntimeException('Erreur interne simulée.');
            }
        };

        $handler = new RunGenerationHandler(
            $container->get(AiRequestRepository::class),
            $pipeline,
            $this->entityManager,
            new NullLogger(),
            3,
        );

        $request = Fixtures::request();
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $handler(new RunGenerationRequest($request->getId()->toRfc4122()));

        self::assertSame(RequestStatus::Failed, $request->getStatus());
        self::assertStringContainsString('Erreur interne simulée.', (string) $request->getError());
    }

    public function testMessageRejectsInvalidIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunGenerationRequest('pas-un-uuid');
    }

    private function failingPipeline(): ProposalPipeline
    {
        $container = static::getContainer();

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

        return new ProposalPipeline(
            $this->entityManager,
            $container->get(AiProposalRepository::class),
            $container->get(AiRunRepository::class),
            $container->get(RagRetriever::class),
            new ArchitectAgent(new ProviderRegistry([$failingProvider], 'failing'), new ArchitectSpecificationValidator()),
            $container->get(BuilderAgent::class),
            $container->get(AuditorAgent::class),
            $container->get(GovernorAgent::class),
        );
    }
}
