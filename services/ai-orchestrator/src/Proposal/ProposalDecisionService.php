<?php

declare(strict_types=1);

namespace App\Proposal;

use App\Entity\AiProposal;
use App\Entity\Enum\Decision;
use App\Repository\AiProposalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Applique une décision humaine à une proposition.
 *
 * Le verrou pessimiste sérialise deux décisions concurrentes. Une approbation
 * change uniquement le statut de la proposition et de la demande : elle ne
 * déclenche aucune publication.
 */
final class ProposalDecisionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AiProposalRepository $proposals,
    ) {
    }

    public function decide(
        Uuid $proposalId,
        Uuid $tenantId,
        Uuid $actorId,
        Decision $decision,
        string $reason,
    ): AiProposal {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Le motif de décision doit contenir entre 3 et 1000 caractères.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($proposalId, $tenantId, $actorId, $decision, $reason): AiProposal {
            $proposal = $this->proposals->findForTenantForUpdate($proposalId, $tenantId);
            if (null === $proposal) {
                throw new ProposalNotFoundException('Proposition introuvable pour ce tenant.');
            }

            if (!$proposal->isAwaitingApproval()) {
                throw new ProposalConflictException('Cette proposition a déjà reçu une décision.');
            }

            if (Decision::Approve === $decision) {
                $proposal->approve($actorId, $reason);
                $proposal->getRequest()->markApproved();
            } else {
                $proposal->reject($actorId, $reason);
                $proposal->getRequest()->markRejected($reason);
            }

            $this->entityManager->flush();

            return $proposal;
        });
    }
}
