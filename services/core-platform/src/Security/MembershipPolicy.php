<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\MembershipRole;

/**
 * Politiques déterministes de gestion des membres d'un tenant.
 *
 * Règles (indépendantes de l'interface) :
 *  - seul un rôle gestionnaire (owner/admin) peut agir ;
 *  - un admin ne peut ni créer, ni promouvoir, ni modifier, ni retirer un owner ;
 *  - seul un owner peut attribuer le rôle owner ;
 *  - personne ne peut modifier ou retirer sa propre adhésion (anti-lockout) ;
 *  - le dernier owner actif ne peut être ni rétrogradé, ni retiré.
 */
final class MembershipPolicy
{
    public function canAdd(MembershipRole $actorRole, MembershipRole $targetRole): bool
    {
        if (!$actorRole->canManage()) {
            return false;
        }

        if ($targetRole->isOwner() && !$actorRole->isOwner()) {
            return false;
        }

        return true;
    }

    public function canChangeRole(
        MembershipRole $actorRole,
        bool $targetsSelf,
        MembershipRole $currentRole,
        MembershipRole $newRole,
    ): bool {
        if (!$actorRole->canManage() || $targetsSelf) {
            return false;
        }

        if (!$actorRole->isOwner() && ($currentRole->isOwner() || $newRole->isOwner())) {
            return false;
        }

        return true;
    }

    public function canRemove(
        MembershipRole $actorRole,
        bool $targetsSelf,
        MembershipRole $targetRole,
        int $activeOwnerCount,
    ): bool {
        if (!$actorRole->canManage() || $targetsSelf) {
            return false;
        }

        if (!$actorRole->isOwner() && $targetRole->isOwner()) {
            return false;
        }

        if ($targetRole->isOwner() && $activeOwnerCount <= 1) {
            return false;
        }

        return true;
    }

    /**
     * @return list<MembershipRole>
     */
    public function assignableRoles(MembershipRole $actorRole): array
    {
        if ($actorRole->isOwner()) {
            return MembershipRole::cases();
        }

        if (MembershipRole::Admin === $actorRole) {
            return [MembershipRole::Admin, MembershipRole::Member, MembershipRole::Auditor];
        }

        return [];
    }

    /**
     * Vérifie qu'un retrait/une rétrogradation ne supprime pas le dernier owner.
     */
    public function preservesAtLeastOneOwner(MembershipRole $targetRole, MembershipRole $newRole, int $activeOwnerCount): bool
    {
        if (!$targetRole->isOwner() || $newRole->isOwner()) {
            return true;
        }

        return $activeOwnerCount > 1;
    }
}
