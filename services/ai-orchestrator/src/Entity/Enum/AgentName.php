<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum AgentName: string
{
    case Architect = 'architect';
    case Builder = 'builder';
    case Auditor = 'auditor';
    case Governor = 'governor';
    case Deployer = 'deployer';

    public function label(): string
    {
        return match ($this) {
            self::Architect => 'Architecte',
            self::Builder => 'Builder',
            self::Auditor => 'Auditeur',
            self::Governor => 'Gouverneur',
            self::Deployer => 'Déployeur',
        };
    }
}
