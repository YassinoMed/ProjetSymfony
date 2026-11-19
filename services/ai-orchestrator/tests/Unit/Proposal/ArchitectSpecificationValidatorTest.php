<?php

declare(strict_types=1);

namespace App\Tests\Unit\Proposal;

use App\Proposal\ArchitectSpecificationValidator;
use App\Proposal\InvalidSpecificationException;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ArchitectSpecificationValidatorTest extends TestCase
{
    private ArchitectSpecificationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ArchitectSpecificationValidator();
    }

    public function testAcceptsValidSpecification(): void
    {
        $specification = $this->validator->validate(Fixtures::specification());

        self::assertSame('gestion_des_taches', $specification['key']);
        self::assertCount(5, $specification['fields']);
    }

    public function testRejectsUnknownTopLevelKey(): void
    {
        $specification = Fixtures::specification();
        $specification['surprise'] = 'valeur';

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }

    public function testRejectsReservedFieldName(): void
    {
        $specification = Fixtures::specification();
        $specification['fields'][] = [
            'name' => 'status',
            'type' => 'string',
            'required' => false,
            'title' => 'Statut',
        ];

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }

    public function testRejectsDuplicateField(): void
    {
        $specification = Fixtures::specification();
        $specification['fields'][] = $specification['fields'][0];

        try {
            $this->validator->validate($specification);
            self::fail('Une spécification avec un champ dupliqué doit être refusée.');
        } catch (InvalidSpecificationException $exception) {
            self::assertStringContainsString('dupliqué', implode(' ', $exception->getErrors()));
        }
    }

    public function testRejectsSelectWithoutOptions(): void
    {
        $specification = Fixtures::specification();
        $specification['fields'][2]['options'] = [];

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }

    public function testRejectsOptionsOnNonSelectField(): void
    {
        $specification = Fixtures::specification();
        $specification['fields'][0]['options'] = ['a', 'b'];

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }

    public function testRejectsUnknownInitialState(): void
    {
        $specification = Fixtures::specification();
        $specification['workflow']['initial'] = 'inconnu';

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }

    public function testRejectsUnknownTransitionState(): void
    {
        $specification = Fixtures::specification();
        $specification['workflow']['transitions'][0]['to'] = 'inconnu';

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }

    public function testRejectsUnknownRole(): void
    {
        $specification = Fixtures::specification();
        $specification['workflow']['transitions'][0]['roles'] = ['owner', 'hacker'];

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }

    public function testRejectsReservedKey(): void
    {
        $specification = Fixtures::specification();
        $specification['key'] = 'new';

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }

    public function testRejectsMissingAssumptions(): void
    {
        $specification = Fixtures::specification();
        unset($specification['assumptions']);

        $this->expectException(InvalidSpecificationException::class);
        $this->validator->validate($specification);
    }
}
