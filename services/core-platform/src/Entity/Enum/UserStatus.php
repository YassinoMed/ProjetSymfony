<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
        };
    }
}
