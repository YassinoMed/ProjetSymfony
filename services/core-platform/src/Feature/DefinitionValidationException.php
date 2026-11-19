<?php

declare(strict_types=1);

namespace App\Feature;

/**
 * Erreurs de validation d'une définition de fonctionnalité.
 *
 * Chaque erreur est déjà formatée en français et pointe le chemin concerné
 * (par exemple `schema.properties.titre.pattern`).
 */
final class DefinitionValidationException extends \InvalidArgumentException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
