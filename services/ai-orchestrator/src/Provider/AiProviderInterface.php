<?php

declare(strict_types=1);

namespace App\Provider;

interface AiProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @throws ProviderUnavailableException
     * @throws ProviderResponseException
     */
    public function complete(ProviderRequest $request): ProviderResponse;
}
