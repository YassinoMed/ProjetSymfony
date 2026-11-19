<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\BuilderAgent;
use App\Proposal\FeatureDefinitionShapeValidator;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class BuilderAgentTest extends TestCase
{
    public function testBuildsCoreCompatibleDefinition(): void
    {
        $result = (new BuilderAgent(new FeatureDefinitionShapeValidator()))
            ->execute(Fixtures::specification());

        $definition = $result->data['payload']['definition'];

        self::assertSame('object', $definition['schema']['type']);
        self::assertSame(['priorite', 'titre'], $definition['schema']['required']);
        self::assertSame('date', $definition['schema']['properties']['echeance']['format']);
        self::assertSame(['basse', 'normale', 'haute'], $definition['schema']['properties']['priorite']['enum']);
        self::assertSame('boolean', $definition['schema']['properties']['terminee']['type']);
        self::assertSame('brouillon', $definition['workflow']['initial']);
        self::assertContains('status', $definition['ui']['list_fields']);
    }

    public function testOutputIsDeterministic(): void
    {
        $agent = new BuilderAgent(new FeatureDefinitionShapeValidator());

        $first = $agent->execute(Fixtures::specification());
        $second = $agent->execute(Fixtures::specification());

        self::assertSame($first->data, $second->data);
    }

    public function testHumanizesMissingTitle(): void
    {
        $specification = Fixtures::specification();
        $specification['fields'][0] = [
            'name' => 'date_livraison',
            'type' => 'date',
            'required' => true,
        ];

        $result = (new BuilderAgent(new FeatureDefinitionShapeValidator()))->execute($specification);

        self::assertSame(
            'Date livraison',
            $result->data['payload']['definition']['schema']['properties']['date_livraison']['title'],
        );
    }
}
