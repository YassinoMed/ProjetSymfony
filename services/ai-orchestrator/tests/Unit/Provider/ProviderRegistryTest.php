<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Provider\AiProviderInterface;
use App\Provider\ProviderRegistry;
use App\Provider\ProviderRequest;
use App\Provider\ProviderResponse;
use App\Provider\ProviderUnavailableException;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function testFallsBackToNextProvider(): void
    {
        $failing = $this->provider('a', true, static function (): never {
            throw new ProviderUnavailableException('panne');
        });
        $working = $this->provider('b', true, static fn (): ProviderResponse => new ProviderResponse('b', 'm', '{}'));

        $registry = new ProviderRegistry([$failing, $working], 'a,b');

        self::assertSame('b', $registry->complete($this->request())->provider);
    }

    public function testSkipsUnconfiguredProvider(): void
    {
        $disabled = $this->provider('a', false, static fn (): ProviderResponse => new ProviderResponse('a', 'm', '{}'));
        $working = $this->provider('b', true, static fn (): ProviderResponse => new ProviderResponse('b', 'm', '{}'));

        $registry = new ProviderRegistry([$disabled, $working], 'a,b');

        self::assertSame('b', $registry->complete($this->request())->provider);
        self::assertSame(['b'], $registry->available());
    }

    public function testFailsWhenNoProviderIsAvailable(): void
    {
        $registry = new ProviderRegistry([
            $this->provider('a', false, static fn (): ProviderResponse => new ProviderResponse('a', 'm', '{}')),
            $this->provider('b', true, static function (): never {
                throw new ProviderUnavailableException('panne');
            }),
        ], 'a,b');

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('a: non configuré');
        $registry->complete($this->request());
    }

    private function request(): ProviderRequest
    {
        return new ProviderRequest('architect', 'system', 'user');
    }

    private function provider(string $name, bool $configured, callable $complete): AiProviderInterface
    {
        return new class($name, $configured, $complete) implements AiProviderInterface {
            public function __construct(
                private readonly string $providerName,
                private readonly bool $configured,
                private readonly mixed $callback,
            ) {
            }

            public function name(): string
            {
                return $this->providerName;
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function complete(ProviderRequest $request): ProviderResponse
            {
                return ($this->callback)($request);
            }
        };
    }
}
