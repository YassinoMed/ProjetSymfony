<?php

declare(strict_types=1);

namespace App\Feature;

use App\Entity\Enum\MembershipRole;

/**
 * Workflow déterministe construit à partir de la définition JSON.
 *
 * Toutes les gardes sont évaluées côté serveur :
 *  - l'état courant et l'état cible doivent exister ;
 *  - la transition doit être déclarée ;
 *  - le rôle de l'acteur doit figurer dans la liste `roles` de la transition.
 */
final class WorkflowDefinition
{
    private string $initial;

    /** @var array<string, string> */
    private array $states = [];

    /** @var list<WorkflowTransition> */
    private array $transitions = [];

    /**
     * @param array<string, mixed> $workflow
     */
    public function __construct(array $workflow)
    {
        $initial = $workflow['initial'] ?? null;
        if (!\is_string($initial) || '' === $initial) {
            throw new \InvalidArgumentException('Le workflow doit définir un état initial.');
        }

        $states = $workflow['states'] ?? null;
        if (!\is_array($states) || [] === $states) {
            throw new \InvalidArgumentException('Le workflow doit définir au moins un état.');
        }

        foreach ($states as $state => $config) {
            if (!\is_string($state) || !\is_array($config)) {
                throw new \InvalidArgumentException('Chaque état du workflow doit être un objet.');
            }

            $label = $config['label'] ?? $state;
            if (!\is_string($label) || '' === trim($label)) {
                throw new \InvalidArgumentException(sprintf('L\'état "%s" doit avoir un libellé.', $state));
            }

            $this->states[$state] = trim($label);
        }

        if (!isset($this->states[$initial])) {
            throw new \InvalidArgumentException('L\'état initial doit exister dans le workflow.');
        }

        $this->initial = $initial;

        $transitions = $workflow['transitions'] ?? [];
        if (!\is_array($transitions)) {
            throw new \InvalidArgumentException('Les transitions du workflow doivent être une liste.');
        }

        foreach ($transitions as $transition) {
            if (!\is_array($transition)) {
                throw new \InvalidArgumentException('Chaque transition doit être un objet.');
            }

            $from = $transition['from'] ?? null;
            $to = $transition['to'] ?? null;
            $label = $transition['label'] ?? null;
            $roles = $transition['roles'] ?? null;

            if (!\is_string($from) || !\is_string($to) || !\is_string($label) || !\is_array($roles)) {
                throw new \InvalidArgumentException('Transition invalide : from, to, label et roles sont obligatoires.');
            }

            if (!isset($this->states[$from]) || !isset($this->states[$to])) {
                throw new \InvalidArgumentException(sprintf('Transition "%s" → "%s" : état inconnu.', $from, $to));
            }

            $membershipRoles = [];
            foreach ($roles as $role) {
                if (!\is_string($role)) {
                    throw new \InvalidArgumentException('Chaque rôle de transition doit être une chaîne.');
                }

                $membershipRole = MembershipRole::tryFrom($role);
                if (null === $membershipRole) {
                    throw new \InvalidArgumentException(sprintf('Rôle inconnu dans la transition "%s" → "%s" : %s.', $from, $to, $role));
                }

                $membershipRoles[] = $membershipRole;
            }

            if ([] === $membershipRoles) {
                throw new \InvalidArgumentException(sprintf('La transition "%s" → "%s" doit autoriser au moins un rôle.', $from, $to));
            }

            $this->transitions[] = new WorkflowTransition($from, $to, trim($label), $membershipRoles);
        }
    }

    public function initial(): string
    {
        return $this->initial;
    }

    /**
     * @return array<string, string>
     */
    public function states(): array
    {
        return $this->states;
    }

    public function hasState(string $state): bool
    {
        return isset($this->states[$state]);
    }

    public function stateLabel(string $state): string
    {
        return $this->states[$state] ?? $state;
    }

    /**
     * @return list<WorkflowTransition>
     */
    public function transitions(): array
    {
        return $this->transitions;
    }

    public function findTransition(string $from, string $to): ?WorkflowTransition
    {
        foreach ($this->transitions as $transition) {
            if ($transition->from === $from && $transition->to === $to) {
                return $transition;
            }
        }

        return null;
    }

    public function canTransition(string $from, string $to, MembershipRole $role): bool
    {
        if (!$this->hasState($from) || !$this->hasState($to)) {
            return false;
        }

        $transition = $this->findTransition($from, $to);

        return null !== $transition && $transition->allows($role);
    }

    /**
     * @return list<WorkflowTransition>
     */
    public function availableTransitions(string $from, MembershipRole $role): array
    {
        if (!$this->hasState($from)) {
            return [];
        }

        return array_values(array_filter(
            $this->transitions,
            static fn (WorkflowTransition $transition): bool => $transition->from === $from && $transition->allows($role),
        ));
    }
}
