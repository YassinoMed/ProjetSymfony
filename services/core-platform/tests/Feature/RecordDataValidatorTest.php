<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Feature\FeatureSchema;
use App\Feature\RecordDataNormalizer;
use App\Feature\RecordDataValidator;
use PHPUnit\Framework\TestCase;

final class RecordDataValidatorTest extends TestCase
{
    private RecordDataValidator $validator;

    private FeatureSchema $schema;

    protected function setUp(): void
    {
        $this->validator = new RecordDataValidator(new RecordDataNormalizer());
        $this->schema = FeatureSchema::fromArrays(
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['titre'],
                'properties' => [
                    'titre' => [
                        'type' => 'string',
                        'minLength' => 3,
                        'maxLength' => 20,
                    ],
                    'priorite' => [
                        'type' => 'string',
                        'enum' => ['basse', 'haute'],
                        'default' => 'basse',
                    ],
                    'montant' => [
                        'type' => 'number',
                        'minimum' => 0,
                        'maximum' => 100,
                    ],
                    'quantite' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'maximum' => 50,
                    ],
                    'urgent' => ['type' => 'boolean'],
                    'etiquettes' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'maxLength' => 5],
                        'minItems' => 1,
                        'maxItems' => 3,
                    ],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'echeance' => ['type' => 'string', 'format' => 'date'],
                ],
            ],
            [
                'list_fields' => ['titre', 'priorite', 'status'],
                'per_page' => 10,
            ],
        );
    }

    public function testValidPayloadIsNormalizedToSchemaTypes(): void
    {
        $result = $this->validator->validate($this->schema, [
            'titre' => '  Alpha  ',
            'priorite' => 'haute',
            'montant' => '12.5',
            'quantite' => ' 7 ',
            'urgent' => 'on',
            'etiquettes' => ' un, deux ',
            'email' => 'A@B.example ',
            'echeance' => '2026-11-19',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame([
            'titre' => 'Alpha',
            'priorite' => 'haute',
            'montant' => 12.5,
            'quantite' => 7,
            'urgent' => true,
            'etiquettes' => ['un', 'deux'],
            'email' => 'A@B.example',
            'echeance' => '2026-11-19',
        ], $result['data']);
    }

    public function testRequiredFieldAndEmptyOptionalFields(): void
    {
        $missing = $this->validator->validate($this->schema, []);
        self::assertSame('Ce champ est obligatoire.', $missing['errors']['titre']);

        $blank = $this->validator->validate($this->schema, ['titre' => '   ']);
        self::assertSame('Ce champ est obligatoire.', $blank['errors']['titre']);

        $emptyOptional = $this->validator->validate($this->schema, [
            'titre' => 'Alpha',
            'montant' => '',
            'email' => '',
            'etiquettes' => '',
        ]);
        self::assertSame([], $emptyOptional['errors']);
        self::assertSame(['titre' => 'Alpha'], $emptyOptional['data']);
    }

    public function testUnknownFieldsAreRejectedNotSilentlyIgnored(): void
    {
        $result = $this->validator->validate($this->schema, [
            'titre' => 'Alpha',
            'inconnu' => 'valeur',
            'status' => 'ouvert',
        ]);

        self::assertSame('Des champs non autorisés ont été fournis.', $result['errors']['_form']);
        self::assertSame('Alpha', $result['data']['titre']);
        self::assertArrayNotHasKey('inconnu', $result['data']);
    }

    public function testStringBoundsEnumAndNumberRanges(): void
    {
        $result = $this->validator->validate($this->schema, [
            'titre' => 'ab',
            'priorite' => 'moyenne',
            'montant' => -1,
            'quantite' => 51,
        ]);

        self::assertArrayHasKey('titre', $result['errors']);
        self::assertArrayHasKey('priorite', $result['errors']);
        self::assertArrayHasKey('montant', $result['errors']);
        self::assertArrayHasKey('quantite', $result['errors']);

        $tooLong = $this->validator->validate($this->schema, ['titre' => str_repeat('a', 21)]);
        self::assertArrayHasKey('titre', $tooLong['errors']);

        $tooHigh = $this->validator->validate($this->schema, ['titre' => 'Alpha', 'montant' => 100.5]);
        self::assertArrayHasKey('montant', $tooHigh['errors']);
    }

    public function testTypeMismatchIsReported(): void
    {
        $result = $this->validator->validate($this->schema, [
            'titre' => 'Alpha',
            'montant' => 'abc',
            'quantite' => '1.5',
        ]);

        self::assertArrayHasKey('montant', $result['errors']);
        self::assertArrayHasKey('quantite', $result['errors']);
    }

    public function testArrayItemsAndBoundsAreValidated(): void
    {
        $tooMany = $this->validator->validate($this->schema, [
            'titre' => 'Alpha',
            'etiquettes' => ['a', 'b', 'c', 'd'],
        ]);
        self::assertArrayHasKey('etiquettes', $tooMany['errors']);

        $tooShortItem = $this->validator->validate($this->schema, [
            'titre' => 'Alpha',
            'etiquettes' => ['abcdef'],
        ]);
        self::assertArrayHasKey('etiquettes', $tooShortItem['errors']);

        $emptyList = $this->validator->validate($this->schema, [
            'titre' => 'Alpha',
            'etiquettes' => '',
        ]);
        self::assertSame([], $emptyList['errors']);
        self::assertArrayNotHasKey('etiquettes', $emptyList['data']);
    }

    public function testFormatsAreValidated(): void
    {
        $badEmail = $this->validator->validate($this->schema, [
            'titre' => 'Alpha',
            'email' => 'pas-un-email',
        ]);
        self::assertArrayHasKey('email', $badEmail['errors']);

        $badDate = $this->validator->validate($this->schema, [
            'titre' => 'Alpha',
            'echeance' => '2026-13-40',
        ]);
        self::assertArrayHasKey('echeance', $badDate['errors']);
    }

    public function testBooleanNormalizationHandlesHtmlValues(): void
    {
        foreach (['1', 'true', 'on', 'yes', 'oui', 1] as $truthy) {
            $result = $this->validator->validate($this->schema, [
                'titre' => 'Alpha',
                'urgent' => $truthy,
            ]);
            self::assertSame([], $result['errors']);
            self::assertTrue($result['data']['urgent'], (string) $truthy);
        }

        foreach (['0', 'false', 'off', 'no', 'non', 0] as $falsy) {
            $result = $this->validator->validate($this->schema, [
                'titre' => 'Alpha',
                'urgent' => $falsy,
            ]);
            self::assertSame([], $result['errors']);
            self::assertFalse($result['data']['urgent'], (string) $falsy);
        }
    }

    public function testRequiredBooleanDefaultsToFalseWhenAbsent(): void
    {
        $schema = FeatureSchema::fromArrays(
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['urgent'],
                'properties' => ['urgent' => ['type' => 'boolean']],
            ],
            ['list_fields' => ['urgent', 'status'], 'per_page' => 10],
        );

        $result = $this->validator->validate($schema, []);

        self::assertSame([], $result['errors']);
        self::assertFalse($result['data']['urgent']);
    }
}
