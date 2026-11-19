<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';

    public function isActive(): bool
    {
        return self::Active === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Invited => 'Invité',
            self::Suspended => 'Suspendu',
        };
    }
}
