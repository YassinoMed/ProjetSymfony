<?php

declare(strict_types=1);

namespace App\Proposal;

class InvalidDefinitionException extends \InvalidArgumentException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Définition de fonctionnalité invalide : '.implode(' | ', $errors));
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
