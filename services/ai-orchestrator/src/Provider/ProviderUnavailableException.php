<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * Fournisseur indisponible ou en échec transitoire : le routeur peut basculer
 * vers le suivant et Messenger peut retenter la demande.
 */
class ProviderUnavailableException extends \RuntimeException
{
}
