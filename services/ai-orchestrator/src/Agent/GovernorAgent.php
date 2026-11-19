<?php

declare(strict_types=1);

namespace App\Agent;

use App\RAG\RagContext;

/**
 * Agent Gouverneur : applique la politique de risque de la Phase 4.
 *
 * L'approbation humaine est toujours requise et la publication est toujours
 * interdite. Un audit en échec produit une proposition rejetée, jamais une
 * proposition publiée.
 */
final class GovernorAgent
{
    /**
     * @param array<string, mixed> $audit
     */
    public function execute(array $audit, RagContext $context): AgentResult
    {
        $auditPassed = true === ($audit['passed'] ?? false);
        $injection = $context->hasHighSeverityInjection();
        $warnings = (int) ($audit['warnings'] ?? 0);
        $failures = (int) ($audit['failures'] ?? 0);

        $checks = [
            [
                'id' => 'GOV-01',
                'label' => 'Approbation humaine obligatoire',
                'status' => 'pass',
                'evidence' => 'Aucune proposition ne peut être publiée sans décision humaine explicite.',
            ],
            [
                'id' => 'GOV-02',
                'label' => 'Publication désactivée en Phase 4',
                'status' => 'pass',
                'evidence' => 'Le statut published n’existe pas et le Déployeur est limité à un préflight.',
            ],
            [
                'id' => 'GOV-03',
                'label' => 'Verdict de l’audit',
                'status' => $auditPassed ? 'pass' : 'fail',
                'evidence' => $auditPassed ? 'Tous les contrôles bloquants sont passés.' : sprintf('%d contrôle(s) en échec.', $failures),
            ],
            [
                'id' => 'GOV-04',
                'label' => 'Contexte RAG',
                'status' => $injection ? 'fail' : 'pass',
                'evidence' => $injection
                    ? sprintf('%d tentative(s) d’injection détectée(s).', \count($context->injectionFindings()))
                    : 'Aucune injection détectée.',
            ],
        ];

        $allowed = $auditPassed && !$injection;

        $riskLevel = match (true) {
            !$allowed => 'high',
            $warnings > 0 => 'medium',
            default => 'low',
        };

        $reason = $allowed
            ? 'Proposition conforme, en attente d’approbation humaine.'
            : 'Proposition rejetée par la politique : audit en échec ou contexte non sûr.';

        $policy = [
            'phase' => 4,
            'decision' => $allowed ? 'awaiting_approval' : 'rejected',
            'status' => $allowed ? 'awaiting_approval' : 'rejected',
            'risk_level' => $riskLevel,
            'requires_human_approval' => true,
            'publication' => 'forbidden',
            'reason' => $reason,
            'checks' => $checks,
        ];

        return new AgentResult(
            data: ['policy' => $policy],
            summary: sprintf('Gouvernance : %s, risque %s.', $policy['decision'], $riskLevel),
        );
    }
}
