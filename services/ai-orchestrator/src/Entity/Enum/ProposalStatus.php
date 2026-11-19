<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Statut d'une proposition.
 *
 * Aucun cas `published` n'existe en Phase 4 : l'approbation humaine ne
 * déclenche pas la publication, elle autorise seulement une phase ultérieure.
 */
enum ProposalStatus: string
{
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingApproval => 'En attente d’approbation humaine',
            self::Approved => 'Approuvée (non publiée)',
            self::Rejected => 'Rejetée',
        };
    }
}
