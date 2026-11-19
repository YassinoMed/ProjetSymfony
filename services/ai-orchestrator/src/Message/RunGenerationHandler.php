<?php

declare(strict_types=1);

namespace App\Message;

use App\Proposal\ProposalPipeline;
use App\Provider\ProviderResponseException;
use App\Provider\ProviderUnavailableException;
use App\Repository\AiRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Exécute le pipeline de manière idempotente.
 *
 * `claim()` verrouille la demande et ne la rend que si elle est encore en file.
 * Un message dupliqué ou un worker concurrent s'arrête donc immédiatement.
 */
#[AsMessageHandler]
final class RunGenerationHandler
{
    public function __construct(
        private readonly AiRequestRepository $requests,
        private readonly ProposalPipeline $pipeline,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts = 3,
    ) {
    }

    public function __invoke(RunGenerationRequest $message): void
    {
        $id = Uuid::fromString($message->requestId);
        $request = $this->requests->claim($id);

        if (null === $request) {
            $this->logger->info('Demande IA ignorée : déjà traitée ou en cours.', [
                'request_id' => $message->requestId,
            ]);

            return;
        }

        try {
            $this->pipeline->run($request);
        } catch (ProviderUnavailableException|ProviderResponseException $exception) {
            $fresh = $this->requests->find($id);
            if (null === $fresh) {
                return;
            }

            if ($fresh->getAttempts() < max(0, $this->maxAttempts - 1)) {
                $fresh->markForRetry($exception->getMessage());
                $this->entityManager->flush();

                $this->logger->warning('Fournisseur IA indisponible : nouvelle tentative programmée.', [
                    'request_id' => $message->requestId,
                    'attempts' => $fresh->getAttempts(),
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $fresh->markFailed($exception->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Demande IA échouée après épuisement des tentatives.', [
                'request_id' => $message->requestId,
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            $fresh = $this->requests->find($id);
            if (null !== $fresh && !$fresh->getStatus()->isTerminal()) {
                $fresh->markFailed($exception->getMessage());
                $this->entityManager->flush();
            }

            $this->logger->error('Demande IA échouée définitivement.', [
                'request_id' => $message->requestId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
