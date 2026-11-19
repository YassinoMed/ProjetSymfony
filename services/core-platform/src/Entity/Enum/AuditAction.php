<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum AuditAction: string
{
    case MembershipAdd = 'membership.add';
    case MembershipRoleChange = 'membership.role_change';
    case MembershipRemove = 'membership.remove';
    case AuditViewed = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::MembershipAdd => 'Ajout d\'un membre',
            self::MembershipRoleChange => 'Changement de rôle',
            self::MembershipRemove => 'Retrait d\'un membre',
            self::AuditViewed => 'Consultation du journal d\'audit',
        };
    }
}
