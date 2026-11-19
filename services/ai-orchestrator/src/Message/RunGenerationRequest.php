<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message asynchrone déclenchant le pipeline pour une demande donnée.
 *
 * Seul l'identifiant circule : le worker recharge l'état en base, ce qui rend
 * le message rejouable sans dupliquer les données métier.
 */
final readonly class RunGenerationRequest
{
    public function __construct(public string $requestId)
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestId)) {
            throw new \InvalidArgumentException('Identifiant de demande invalide.');
        }
    }
}
