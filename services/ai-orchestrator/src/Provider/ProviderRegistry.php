<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * Routeur de fournisseurs avec bascule contrôlée.
 *
 * L'ordre est défini par `AI_PROVIDER_CHAIN`. Un fournisseur non configuré est
 * ignoré ; un échec transitoire passe au suivant. Si aucun fournisseur ne
 * répond, l'exception remonte et Messenger applique sa politique de retry.
 */
final class ProviderRegistry
{
    /** @var array<string, AiProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<AiProviderInterface> $providers
     */
    public function __construct(iterable $providers, private readonly string $chain)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    public function complete(ProviderRequest $request): ProviderResponse
    {
        $errors = [];

        foreach ($this->chainNames() as $name) {
            $provider = $this->providers[$name] ?? null;
            if (null === $provider) {
                $errors[] = sprintf('%s: inconnu', $name);
                continue;
            }

            if (!$provider->isConfigured()) {
                $errors[] = sprintf('%s: non configuré', $name);
                continue;
            }

            try {
                return $provider->complete($request);
            } catch (ProviderUnavailableException|ProviderResponseException $exception) {
                $errors[] = sprintf('%s: %s', $name, $exception->getMessage());
            }
        }

        throw new ProviderUnavailableException(
            'Aucun fournisseur IA disponible. '.implode(' | ', $errors),
        );
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        $available = [];
        foreach ($this->providers as $name => $provider) {
            if ($provider->isConfigured()) {
                $available[] = $name;
            }
        }

        return $available;
    }

    /**
     * @return list<string>
     */
    private function chainNames(): array
    {
        $names = array_values(array_filter(
            array_map(static fn (string $name): string => trim($name), explode(',', $this->chain)),
            static fn (string $name): bool => '' !== $name,
        ));

        return [] === $names ? array_keys($this->providers) : $names;
    }
}
