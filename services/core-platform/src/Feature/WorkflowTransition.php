<?php

declare(strict_types=1);

namespace App\Feature;

use App\Entity\Enum\MembershipRole;

/**
 * Transition déterministe d'un workflow : aucun callback ni expression libre.
 */
final class WorkflowTransition
{
    /**
     * @param list<MembershipRole> $roles
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly array $roles,
    ) {
    }

    public function allows(MembershipRole $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    public function key(): string
    {
        return $this->from.'__'.$this->to;
    }
}
