<?php

declare(strict_types=1);

namespace App\Agent;

use App\Entity\AiProposal;

/**
 * Agent Déployeur : préflight uniquement.
 *
 * En Phase 4, le Déployeur ne peut ni activer ni publier une proposition. Ce
 * service existe pour prouver que la chaîne d'activation reste fermée et pour
 * préparer les préconditions vérifiables de la Phase 6.
 */
final class DeployerAgent
{
    public function preflight(AiProposal $proposal): AgentResult
    {
        $checks = [
            [
                'id' => 'DEP-01',
                'label' => 'Proposition approuvée',
                'status' => 'approved' === $proposal->getStatus()->value ? 'pass' : 'fail',
                'evidence' => sprintf('Statut courant : %s.', $proposal->getStatus()->value),
            ],
            [
                'id' => 'DEP-02',
                'label' => 'Capacité de publication',
                'status' => 'fail',
                'evidence' => 'La publication est désactivée en Phase 4 ; aucun environnement cible n’est configuré.',
            ],
            [
                'id' => 'DEP-03',
                'label' => 'Aucun effet de bord',
                'status' => 'pass',
                'evidence' => 'Le préflight est en lecture seule et ne modifie aucune entité.',
            ],
        ];

        return new AgentResult(
            data: [
                'allowed' => false,
                'publication' => 'forbidden',
                'checks' => $checks,
            ],
            summary: 'Préflight du Déployeur : publication interdite en Phase 4.',
        );
    }
}
