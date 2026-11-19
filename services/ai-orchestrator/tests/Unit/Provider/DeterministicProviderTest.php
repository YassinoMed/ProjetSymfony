<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Proposal\ArchitectSpecificationValidator;
use App\Provider\DeterministicProvider;
use App\Provider\ProviderRequest;
use App\Provider\ProviderResponseException;
use PHPUnit\Framework\TestCase;

final class DeterministicProviderTest extends TestCase
{
    public function testProducesStableAndValidSpecification(): void
    {
        $provider = new DeterministicProvider(true);
        $request = new ProviderRequest('architect', 'system', 'Créer un suivi des demandes clients');

        $first = $provider->complete($request);
        $second = $provider->complete($request);

        self::assertSame($first->content, $second->content);
        self::assertSame('stub', $first->provider);

        $specification = (new ArchitectSpecificationValidator())->validate($first->json());
        self::assertSame('creer_un_suivi_des_demandes_clients', $specification['key']);
        self::assertNotEmpty($specification['fields']);
    }

    public function testIsDisabledWhenConfiguredOff(): void
    {
        self::assertFalse((new DeterministicProvider(false))->isConfigured());
    }

    public function testRejectsOtherAgents(): void
    {
        $this->expectException(ProviderResponseException::class);
        (new DeterministicProvider(true))->complete(new ProviderRequest('builder', 's', 'u'));
    }
}
