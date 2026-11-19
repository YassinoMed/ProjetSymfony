<?php

declare(strict_types=1);

namespace App\Proposal;

use App\Agent\AgentResult;
use App\Agent\ArchitectAgent;
use App\Agent\AuditorAgent;
use App\Agent\BuilderAgent;
use App\Agent\GovernorAgent;
use App\Entity\AiProposal;
use App\Entity\AiRequest;
use App\Entity\AiRun;
use App\Entity\Enum\AgentName;
use App\Entity\Enum\ProposalStatus;
use App\RAG\RagRetriever;
use App\Repository\AiProposalRepository;
use App\Repository\AiRunRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pipeline déterministe Architecte → Builder → Auditeur → Gouverneur.
 *
 * Chaque étape produit une trace horodatée. Une proposition n'est créée
 * qu'après le passage de l'audit et de la politique ; elle reste en attente
 * d'approbation humaine ou rejetée, jamais publiée.
 */
class ProposalPipeline
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AiProposalRepository $proposals,
        private readonly AiRunRepository $runs,
        private readonly RagRetriever $retriever,
        private readonly ArchitectAgent $architect,
        private readonly BuilderAgent $builder,
        private readonly AuditorAgent $auditor,
        private readonly GovernorAgent $governor,
    ) {
    }

    public function run(AiRequest $request): AiProposal
    {
        $existing = $this->proposals->findForRequest($request);
        if (null !== $existing) {
            return $existing;
        }

        $context = $this->retriever->retrieve(
            $request->getTenantId(),
            $request->getActorRole(),
            $request->getPrompt(),
        );

        $architectResult = $this->step(
            $request,
            AgentName::Architect,
            fn (): AgentResult => $this->architect->execute($request, $context),
        );

        $request->markBuilding();
        $this->entityManager->flush();

        $builderResult = $this->step(
            $request,
            AgentName::Builder,
            fn (): AgentResult => $this->builder->execute($architectResult->data['specification']),
        );

        $request->markValidating();
        $this->entityManager->flush();

        $auditorResult = $this->step(
            $request,
            AgentName::Auditor,
            fn (): AgentResult => $this->auditor->execute($builderResult->data['payload'], $context),
        );

        $governorResult = $this->step(
            $request,
            AgentName::Governor,
            fn (): AgentResult => $this->governor->execute($auditorResult->data, $context),
        );

        $specification = $architectResult->data['specification'];
        $payload = $builderResult->data['payload'];
        $policy = $governorResult->data['policy'];

        $status = 'awaiting_approval' === $policy['status']
            ? ProposalStatus::AwaitingApproval
            : ProposalStatus::Rejected;

        $trace = array_map(
            static fn (AiRun $run): array => $run->toTrace(),
            $this->runs->findForRequest($request),
        );

        $proposal = new AiProposal(
            request: $request,
            status: $status,
            payload: $payload,
            rationale: [
                'summary' => $specification['description'],
                'decisions' => [
                    ['decision' => 'Spécification', 'reason' => $architectResult->summary],
                    ['decision' => 'Construction', 'reason' => $builderResult->summary],
                    ['decision' => 'Audit', 'reason' => $auditorResult->summary],
                    ['decision' => 'Gouvernance', 'reason' => $governorResult->summary],
                ],
                'assumptions' => $specification['assumptions'],
                'open_questions' => $specification['open_questions'],
            ],
            risks: $auditorResult->data['risks'],
            citations: $context->citations(),
            policy: $policy,
            agentTrace: $trace,
            providerMeta: $architectResult->data['provider_meta'],
        );

        $this->entityManager->persist($proposal);
        $request->setProvider($architectResult->provider);

        if (ProposalStatus::AwaitingApproval === $status) {
            $request->markAwaitingApproval();
        } else {
            $request->markRejected((string) $policy['reason']);
        }

        $this->entityManager->flush();

        return $proposal;
    }

    private function step(AiRequest $request, AgentName $agent, callable $action): AgentResult
    {
        $run = new AiRun($request, $agent);
        $this->entityManager->persist($run);

        try {
            $result = $action();
            $run->succeed(
                summary: $result->summary,
                provider: $result->provider,
                model: $result->model,
                latencyMs: $result->latencyMs,
                inputTokens: $result->inputTokens,
                outputTokens: $result->outputTokens,
            );
            $this->entityManager->flush();

            return $result;
        } catch (\Throwable $exception) {
            $run->fail($exception->getMessage());
            $request->markFailed($exception->getMessage());
            $this->entityManager->flush();

            throw $exception;
        }
    }
}
