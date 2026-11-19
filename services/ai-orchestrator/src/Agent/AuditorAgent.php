<?php

declare(strict_types=1);

namespace App\Agent;

use App\Proposal\FeatureDefinitionShapeValidator;
use App\Proposal\InvalidDefinitionException;
use App\RAG\RagContext;

/**
 * Agent Auditeur : contrôle déterministe de la définition construite.
 *
 * Chaque constat porte une preuve. L'auditeur ne peut pas déclarer un PASS
 * sans contrôle exécuté : le verdict découle uniquement des statuts collectés.
 */
final class AuditorAgent
{
    private const array FORBIDDEN_KEYS = ['tenant_id', 'tenant', 'permissions', 'api_key', 'secret', 'password'];

    private const string SECRET_PATTERN = '/\b(sk-[a-zA-Z0-9]{16,}|AKIA[0-9A-Z]{16}|ghp_[a-zA-Z0-9]{20,}|Bearer\s+[a-zA-Z0-9._-]{20,})\b/';

    public function __construct(private readonly FeatureDefinitionShapeValidator $validator)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(array $payload, RagContext $context): AgentResult
    {
        $checks = [];
        $risks = [];

        try {
            $this->validator->validate($payload);
            $checks[] = $this->check('AUD-01', 'Structure de la définition', 'pass', 'Définition conforme au sous-ensemble Core.');
        } catch (InvalidDefinitionException $exception) {
            $checks[] = $this->check('AUD-01', 'Structure de la définition', 'fail', implode(' | ', $exception->getErrors()));
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $forbidden = $this->findForbiddenKeys($payload);
        if ([] === $forbidden) {
            $checks[] = $this->check('AUD-02', 'Absence de clés réservées ou de tenant', 'pass', 'Aucune clé interdite détectée.');
        } else {
            $checks[] = $this->check('AUD-02', 'Absence de clés réservées ou de tenant', 'fail', 'Clés interdites : '.implode(', ', $forbidden));
        }

        if (1 === preg_match(self::SECRET_PATTERN, $encoded)) {
            $checks[] = $this->check('AUD-03', 'Absence de secret', 'fail', 'Un secret potentiel a été détecté dans la définition.');
        } elseif (1 === preg_match('/\b(api[_-]?key|secret|password)\b/i', $encoded)) {
            $checks[] = $this->check('AUD-03', 'Absence de secret', 'warn', 'Le vocabulaire de la définition mentionne un secret ou un mot de passe.');
        } else {
            $checks[] = $this->check('AUD-03', 'Absence de secret', 'pass', 'Aucun secret détecté.');
        }

        $publicationKeys = $this->findKeys($payload, ['published', 'publish', 'deployed']);
        if ([] === $publicationKeys) {
            $checks[] = $this->check('AUD-04', 'Aucune instruction de publication', 'pass', 'Aucune clé de publication ou de déploiement.');
        } else {
            $checks[] = $this->check('AUD-04', 'Aucune instruction de publication', 'fail', 'Clés de publication détectées : '.implode(', ', $publicationKeys));
        }

        if ($context->hasHighSeverityInjection()) {
            $checks[] = $this->check(
                'AUD-05',
                'Contexte RAG non empoisonné',
                'fail',
                sprintf('%d fragment(s) RAG ont été exclus pour tentative d’injection.', \count($context->injectionFindings())),
            );
        } else {
            $checks[] = $this->check('AUD-05', 'Contexte RAG non empoisonné', 'pass', 'Aucune tentative d’injection détectée dans le contexte retenu.');
        }

        if (\strlen($encoded) <= 32768) {
            $checks[] = $this->check('AUD-06', 'Taille de la définition', 'pass', sprintf('%d octets (limite 32768).', \strlen($encoded)));
        } else {
            $checks[] = $this->check('AUD-06', 'Taille de la définition', 'fail', sprintf('%d octets (limite 32768).', \strlen($encoded)));
        }

        foreach ($checks as $check) {
            if ('fail' === $check['status']) {
                $risks[] = [
                    'id' => $check['id'],
                    'severity' => 'high',
                    'message' => $check['label'],
                    'evidence' => $check['evidence'],
                ];
            } elseif ('warn' === $check['status']) {
                $risks[] = [
                    'id' => $check['id'],
                    'severity' => 'medium',
                    'message' => $check['label'],
                    'evidence' => $check['evidence'],
                ];
            }
        }

        $failures = \count(array_filter($checks, static fn (array $check): bool => 'fail' === $check['status']));
        $warnings = \count(array_filter($checks, static fn (array $check): bool => 'warn' === $check['status']));

        return new AgentResult(
            data: [
                'checks' => $checks,
                'risks' => $risks,
                'passed' => 0 === $failures,
                'failures' => $failures,
                'warnings' => $warnings,
            ],
            summary: sprintf('Audit : %d contrôle(s), %d échec(s), %d avertissement(s).', \count($checks), $failures, $warnings),
        );
    }

    /**
     * @return array{id: string, label: string, status: string, evidence: string}
     */
    private function check(string $id, string $label, string $status, string $evidence): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'evidence' => mb_substr($evidence, 0, 2000),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private function findForbiddenKeys(array $data, string $path = ''): array
    {
        $found = [];

        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array($key, self::FORBIDDEN_KEYS, true)) {
                $found[] = '' === $path ? $key : $path.'.'.$key;
            }

            if (\is_array($value)) {
                foreach ($this->findForbiddenKeys($value, '' === $path ? (string) $key : $path.'.'.$key) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $keys
     *
     * @return list<string>
     */
    private function findKeys(array $data, array $keys, string $path = ''): array
    {
        $found = [];

        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array($key, $keys, true)) {
                $found[] = '' === $path ? $key : $path.'.'.$key;
            }

            if (\is_array($value)) {
                foreach ($this->findKeys($value, $keys, '' === $path ? (string) $key : $path.'.'.$key) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
