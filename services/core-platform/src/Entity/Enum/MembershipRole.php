<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propriétaire',
            self::Admin => 'Administrateur',
            self::Member => 'Membre',
            self::Auditor => 'Auditeur',
        };
    }

    public function canManage(): bool
    {
        return self::Owner === $this || self::Admin === $this;
    }

    public function canAudit(): bool
    {
        return self::Member !== $this;
    }

    public function isOwner(): bool
    {
        return self::Owner === $this;
    }
}
