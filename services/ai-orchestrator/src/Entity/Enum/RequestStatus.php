<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Statut d'une demande de génération.
 *
 * La Phase 4 s'arrête volontairement à `awaiting_approval`, `approved` ou
 * `rejected` : aucun statut `published` n'existe, donc aucune publication
 * automatique n'est représentable.
 */
enum RequestStatus: string
{
    case Queued = 'queued';
    case Analyzing = 'analyzing';
    case Building = 'building';
    case Validating = 'validating';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return \in_array($this, [
            self::AwaitingApproval,
            self::Approved,
            self::Rejected,
            self::Failed,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En file',
            self::Analyzing => 'Analyse',
            self::Building => 'Construction',
            self::Validating => 'Validation',
            self::AwaitingApproval => 'En attente d’approbation',
            self::Approved => 'Approuvée',
            self::Rejected => 'Rejetée',
            self::Failed => 'Échec',
        };
    }
}
