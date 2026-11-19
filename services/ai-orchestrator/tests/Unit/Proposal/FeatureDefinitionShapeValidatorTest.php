<?php

declare(strict_types=1);

namespace App\Tests\Unit\Proposal;

use App\Proposal\FeatureDefinitionShapeValidator;
use App\Proposal\InvalidDefinitionException;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class FeatureDefinitionShapeValidatorTest extends TestCase
{
    private FeatureDefinitionShapeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FeatureDefinitionShapeValidator();
    }

    public function testAcceptsBuilderPayload(): void
    {
        $payload = $this->validator->validate(Fixtures::payload());

        self::assertSame('feature_definition', $payload['type']);
        self::assertSame('gestion_des_taches', $payload['key']);
        self::assertFalse($payload['definition']['schema']['additionalProperties']);
    }

    public function testRejectsUnknownTopLevelKey(): void
    {
        $payload = Fixtures::payload();
        $payload['extra'] = true;

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }

    public function testRejectsWrongType(): void
    {
        $payload = Fixtures::payload();
        $payload['type'] = 'script';

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }

    public function testRejectsReservedKey(): void
    {
        $payload = Fixtures::payload();
        $payload['key'] = 'new';

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }

    public function testRejectsInvalidFieldType(): void
    {
        $payload = Fixtures::payload();
        $payload['definition']['schema']['properties']['titre']['type'] = 'object';

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }

    public function testRejectsReservedFieldName(): void
    {
        $payload = Fixtures::payload();
        $payload['definition']['schema']['properties']['id'] = ['type' => 'string'];

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }

    public function testRejectsUnknownListField(): void
    {
        $payload = Fixtures::payload();
        $payload['definition']['ui']['list_fields'][] = 'inconnu';

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }

    public function testRejectsUnknownWorkflowState(): void
    {
        $payload = Fixtures::payload();
        $payload['definition']['workflow']['initial'] = 'inconnu';

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }

    public function testRejectsUnknownTransitionRole(): void
    {
        $payload = Fixtures::payload();
        $payload['definition']['workflow']['transitions'][0]['roles'] = ['root'];

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }

    public function testRejectsMissingDefinition(): void
    {
        $payload = Fixtures::payload();
        unset($payload['definition']);

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($payload);
    }
}
