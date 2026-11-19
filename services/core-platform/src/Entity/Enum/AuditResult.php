<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum AuditResult: string
{
    case Success = 'success';
    case Denied = 'denied';
    case Failure = 'failure';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Succès',
            self::Denied => 'Refusé',
            self::Failure => 'Échec',
        };
    }

    public function isSuccess(): bool
    {
        return self::Success === $this;
    }
}
