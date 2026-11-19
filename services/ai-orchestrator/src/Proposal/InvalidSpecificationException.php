<?php

declare(strict_types=1);

namespace App\Proposal;

class InvalidSpecificationException extends \InvalidArgumentException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Spécification d’architecture invalide : '.implode(' | ', $errors));
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
