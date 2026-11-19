<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * Le fournisseur a répondu, mais sa réponse n'est pas exploitable (JSON
 * invalide, contenu vide). Ce n'est pas une indisponibilité réseau.
 */
class ProviderResponseException extends \RuntimeException
{
}
