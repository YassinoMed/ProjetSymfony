<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AiProposal;
use App\Entity\AiRequest;
use App\Entity\Enum\Decision;
use App\Message\RunGenerationRequest;
use App\Proposal\ProposalConflictException;
use App\Proposal\ProposalDecisionService;
use App\Proposal\ProposalNotFoundException;
use App\RAG\RagRoles;
use App\Repository\AiProposalRepository;
use App\Repository\AiRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * API interne de l'orchestrateur IA.
 *
 * Elle est appelée par le Core ou la Gateway avec un jeton de service et des
 * identifiants tenant/utilisateur fournis par l'appelant authentifié. Aucune
 * route ne publie une proposition.
 */
final class InternalAiController
{
    public function __construct(
        private readonly AiRequestRepository $requests,
        private readonly AiProposalRepository $proposals,
        private readonly ProposalDecisionService $decisions,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => 'ai-orchestrator',
            'phase' => 4,
            'publication' => 'forbidden',
        ]);
    }

    #[Route('/internal/ai/requests', name: 'internal_ai_create_request', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->requiredUuidHeader($request, 'X-Tenant-Id');
            $requestedBy = $this->optionalUuidHeader($request, 'X-User-Id');
            $actorRole = $this->actorRole($request);

            $body = $this->decodeBody($request, ['prompt', 'idempotency_key', 'correlation_id']);

            $prompt = $body['prompt'] ?? null;
            if (!\is_string($prompt) || mb_strlen(trim($prompt)) < 10 || mb_strlen(trim($prompt)) > 4000) {
                throw new \InvalidArgumentException('prompt : 10 à 4000 caractères attendus.');
            }

            $idempotencyKey = $body['idempotency_key'] ?? null;
            if (null !== $idempotencyKey) {
                if (!\is_string($idempotencyKey) || 1 !== preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $idempotencyKey)) {
                    throw new \InvalidArgumentException('idempotency_key : 8 à 120 caractères alphanumériques, points, deux-points ou tirets attendus.');
                }
            } else {
                $idempotencyKey = 'auto:'.Uuid::v4()->toRfc4122();
            }

            $correlationId = null;
            if (isset($body['correlation_id'])) {
                if (!\is_string($body['correlation_id'])) {
                    throw new \InvalidArgumentException('correlation_id : UUID attendu.');
                }
                $correlationId = Uuid::fromString($body['correlation_id']);
            }

            $existing = $this->requests->findByTenantAndIdempotencyKey($tenantId, $idempotencyKey);
            if (null !== $existing) {
                if ('queued' === $existing->getStatus()->value) {
                    $this->bus->dispatch(new RunGenerationRequest($existing->getId()->toRfc4122()));
                }

                return new JsonResponse($this->requestPayload($existing), 200, ['Idempotent-Replay' => 'true']);
            }

            $aiRequest = new AiRequest(
                tenantId: $tenantId,
                prompt: trim($prompt),
                idempotencyKey: $idempotencyKey,
                requestedBy: $requestedBy,
                correlationId: $correlationId,
                actorRole: $actorRole,
            );

            $this->entityManager->persist($aiRequest);
            $this->entityManager->flush();

            try {
                $this->bus->dispatch(new RunGenerationRequest($aiRequest->getId()->toRfc4122()));
            } catch (\Throwable) {
                $aiRequest->markFailed('File asynchrone indisponible.');
                $this->entityManager->flush();

                return $this->error('queue_unavailable', 'La file asynchrone est indisponible.', 503);
            }

            return new JsonResponse($this->requestPayload($aiRequest), 202);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return $this->error('invalid_request', $exception->getMessage(), 400);
        }
    }

    #[Route('/internal/ai/requests/{id}', name: 'internal_ai_show_request', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        try {
            $tenantId = $this->requiredUuidHeader($request, 'X-Tenant-Id');
            $requestId = Uuid::fromString($id);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('invalid_request', $exception->getMessage(), 400);
        }

        $aiRequest = $this->requests->findForTenant($requestId, $tenantId);

        return null === $aiRequest
            ? $this->error('not_found', 'Demande introuvable.', 404)
            : new JsonResponse($this->requestPayload($aiRequest));
    }

    #[Route('/internal/ai/proposals/{id}', name: 'internal_ai_show_proposal', methods: ['GET'])]
    public function showProposal(string $id, Request $request): JsonResponse
    {
        try {
            $tenantId = $this->requiredUuidHeader($request, 'X-Tenant-Id');
            $proposalId = Uuid::fromString($id);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('invalid_request', $exception->getMessage(), 400);
        }

        $proposal = $this->proposals->findForTenant($proposalId, $tenantId);

        return null === $proposal
            ? $this->error('not_found', 'Proposition introuvable.', 404)
            : new JsonResponse($this->proposalPayload($proposal));
    }

    #[Route('/internal/ai/proposals/{id}/decision', name: 'internal_ai_decide_proposal', methods: ['POST'])]
    public function decide(string $id, Request $request): JsonResponse
    {
        try {
            $tenantId = $this->requiredUuidHeader($request, 'X-Tenant-Id');
            $actorId = $this->requiredUuidHeader($request, 'X-User-Id');
            $proposalId = Uuid::fromString($id);

            $body = $this->decodeBody($request, ['decision', 'reason']);

            $decision = Decision::tryFrom((string) ($body['decision'] ?? ''));
            if (null === $decision) {
                throw new \InvalidArgumentException('decision : "approve" ou "reject" attendu.');
            }

            $reason = $body['reason'] ?? null;
            if (!\is_string($reason)) {
                throw new \InvalidArgumentException('reason : motif texte attendu.');
            }

            $proposal = $this->decisions->decide($proposalId, $tenantId, $actorId, $decision, $reason);

            return new JsonResponse($this->proposalPayload($proposal));
        } catch (ProposalNotFoundException $exception) {
            return $this->error('not_found', $exception->getMessage(), 404);
        } catch (ProposalConflictException $exception) {
            return $this->error('conflict', $exception->getMessage(), 409);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return $this->error('invalid_request', $exception->getMessage(), 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(AiRequest $request): array
    {
        $proposal = $this->proposals->findForRequest($request);

        return [
            'request_id' => $request->getId()->toRfc4122(),
            'tenant_id' => $request->getTenantId()->toRfc4122(),
            'status' => $request->getStatus()->value,
            'actor_role' => $request->getActorRole(),
            'provider' => $request->getProvider(),
            'attempts' => $request->getAttempts(),
            'error' => $request->getError(),
            'created_at' => $request->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $request->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'proposal' => null === $proposal ? null : $this->proposalPayload($proposal),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalPayload(AiProposal $proposal): array
    {
        return [
            'id' => $proposal->getId()->toRfc4122(),
            'status' => $proposal->getStatus()->value,
            'payload' => $proposal->getPayload(),
            'rationale' => $proposal->getRationale(),
            'risks' => $proposal->getRisks(),
            'citations' => $proposal->getCitations(),
            'policy' => $proposal->getPolicy(),
            'agent_trace' => $proposal->getAgentTrace(),
            'provider_meta' => $proposal->getProviderMeta(),
            'created_at' => $proposal->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'decided_at' => $proposal->getDecidedAt()?->format(\DateTimeInterface::ATOM),
            'decided_by' => $proposal->getDecidedBy()?->toRfc4122(),
            'decision_reason' => $proposal->getDecisionReason(),
            'publication' => 'forbidden',
            'published' => false,
        ];
    }

    /**
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request, array $allowed): array
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            throw new \InvalidArgumentException('Corps JSON obligatoire.');
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Corps JSON invalide.');
        }

        if (!\is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Un objet JSON est attendu.');
        }

        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('Clé non autorisée : %s.', (string) $key));
            }
        }

        return $data;
    }

    private function requiredUuidHeader(Request $request, string $name): Uuid
    {
        $value = trim((string) $request->headers->get($name, ''));
        if ('' === $value) {
            throw new \InvalidArgumentException(sprintf('En-tête %s obligatoire.', $name));
        }

        return Uuid::fromString($value);
    }

    private function optionalUuidHeader(Request $request, string $name): ?Uuid
    {
        $value = trim((string) $request->headers->get($name, ''));

        return '' === $value ? null : Uuid::fromString($value);
    }

    private function actorRole(Request $request): string
    {
        $role = (string) $request->headers->get('X-Actor-Role', 'member');
        $normalized = RagRoles::normalize([$role]);

        return $normalized[0] ?? 'member';
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}
