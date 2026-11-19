<?php

declare(strict_types=1);

namespace App\Feature;

use App\Entity\Enum\MembershipRole;

/**
 * Permissions déterministes du moteur dynamique.
 *
 *  - tous les membres actifs peuvent consulter les définitions et les données ;
 *  - owner, admin et member peuvent créer et modifier un enregistrement ;
 *  - owner et admin peuvent gérer les définitions et supprimer un enregistrement ;
 *  - les transitions sont limitées aux rôles déclarés dans le workflow.
 *
 * Les auditors sont en lecture seule.
 */
final class FeaturePolicy
{
    public function canView(MembershipRole $role): bool
    {
        return true;
    }

    public function canManageDefinitions(MembershipRole $role): bool
    {
        return $role->canManage();
    }

    public function canCreateRecord(MembershipRole $role): bool
    {
        return \in_array($role, [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member], true);
    }

    public function canUpdateRecord(MembershipRole $role): bool
    {
        return $this->canCreateRecord($role);
    }

    public function canDeleteRecord(MembershipRole $role): bool
    {
        return $role->canManage();
    }

    public function canTransition(MembershipRole $role, WorkflowTransition $transition): bool
    {
        return $transition->allows($role);
    }
}
