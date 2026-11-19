<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Feature\FeatureSchema;
use PHPUnit\Framework\TestCase;

final class FeatureSchemaTest extends TestCase
{
    public function testSchemaExposesFieldsRequiredListFieldsAndPerPage(): void
    {
        $schema = $this->schema();

        self::assertSame(['titre', 'priorite', 'montant', 'urgent', 'etiquettes', 'email'], $schema->fieldNames());
        self::assertSame(['titre', 'quantite'], $schema->requiredFields());
        self::assertTrue($schema->isRequired('titre'));
        self::assertFalse($schema->isRequired('priorite'));
        self::assertTrue($schema->hasField('montant'));
        self::assertFalse($schema->hasField('status'));
        self::assertSame('Titre personnalisé', $schema->getField('titre')->label);
        self::assertSame('Priorite', $schema->getField('priorite')->label);
        self::assertSame(['titre', 'priorite', 'status', 'created_at'], $schema->listFields());
        self::assertSame(10, $schema->perPage());
    }

    public function testSortableAndSearchableFieldsFollowAllowlist(): void
    {
        $schema = $this->schema();

        self::assertTrue($schema->isSortable('titre'));
        self::assertTrue($schema->isSortable('montant'));
        self::assertTrue($schema->isSortable('urgent'));
        self::assertTrue($schema->isSortable('status'));
        self::assertTrue($schema->isSortable('created_at'));
        self::assertTrue($schema->isSortable('updated_at'));
        self::assertFalse($schema->isSortable('etiquettes'));
        self::assertFalse($schema->isSortable('inconnu'));

        self::assertTrue($schema->isSearchable('titre'));
        self::assertTrue($schema->isSearchable('montant'));
        self::assertFalse($schema->isSearchable('urgent'));
        self::assertFalse($schema->isSearchable('etiquettes'));
        self::assertFalse($schema->isSearchable('inconnu'));

        self::assertSame(['titre', 'priorite', 'montant', 'email'], $schema->searchableFields());
        self::assertSame(['status', 'created_at', 'updated_at', 'titre', 'priorite', 'montant', 'urgent', 'email'], $schema->sortableFields());
    }

    public function testDefaultsAreDerivedFromSchema(): void
    {
        $defaults = $this->schema()->defaults();

        self::assertNull($defaults['titre']);
        self::assertSame('basse', $defaults['priorite']);
        self::assertFalse($defaults['urgent']);
        self::assertNull($defaults['etiquettes']);
    }

    public function testListFieldsFallBackToDefaultsAndAreCappedAtEight(): void
    {
        $default = $this->schema([
            'ui' => [],
        ]);
        self::assertSame(['titre', 'priorite', 'montant', 'urgent', 'etiquettes', 'status', 'created_at'], $default->listFields());

        $filtered = $this->schema([
            'ui' => ['list_fields' => ['titre', 'inconnu', 'titre', 'status', 'created_by']],
        ]);
        self::assertSame(['titre', 'status', 'created_by'], $filtered->listFields());

        $capped = $this->schema([
            'ui' => [
                'list_fields' => [
                    'titre', 'priorite', 'montant', 'urgent', 'etiquettes', 'email',
                    'status', 'created_at', 'updated_at', 'created_by',
                ],
            ],
        ]);
        self::assertCount(8, $capped->listFields());
    }

    public function testPerPageIsClamped(): void
    {
        self::assertSame(5, $this->schema(['ui' => ['per_page' => 3]])->perPage());
        self::assertSame(100, $this->schema(['ui' => ['per_page' => 250]])->perPage());
        self::assertSame(25, $this->schema(['ui' => ['per_page' => '25']])->perPage());
    }

    public function testJsonSchemaContainsOnlyValidationKeywords(): void
    {
        $jsonSchema = $this->schema()->toJsonSchema();

        self::assertSame('object', $jsonSchema['type']);
        self::assertFalse($jsonSchema['additionalProperties']);
        self::assertSame(['titre', 'quantite'], $jsonSchema['required']);
        self::assertArrayNotHasKey('title', $jsonSchema['properties']['titre']);
        self::assertArrayNotHasKey('default', $jsonSchema['properties']['priorite']);
        self::assertSame(3, $jsonSchema['properties']['titre']['minLength']);
        self::assertSame('email', $jsonSchema['properties']['email']['format']);
        self::assertSame(['basse', 'haute'], $jsonSchema['properties']['priorite']['enum']);
        self::assertSame(['type' => 'string', 'maxLength' => 5], $jsonSchema['properties']['etiquettes']['items']);
        self::assertSame(1, $jsonSchema['properties']['etiquettes']['minItems']);
        self::assertSame(3, $jsonSchema['properties']['etiquettes']['maxItems']);
    }

    public function testJsonSchemaObjectIsAnObjectForOpis(): void
    {
        $object = $this->schema()->jsonSchemaObject();

        self::assertInstanceOf(\stdClass::class, $object);
        self::assertInstanceOf(\stdClass::class, $object->properties);
        self::assertSame('object', $object->type);
    }

    public function testUnknownFieldAccessFailsClosed(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('inconnu');

        $this->schema()->getField('inconnu');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function schema(array $overrides = []): FeatureSchema
    {
        $definition = $overrides + [
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['titre', 'quantite'],
                'properties' => [
                    'titre' => [
                        'type' => 'string',
                        'title' => 'Titre personnalisé',
                        'minLength' => 3,
                    ],
                    'priorite' => [
                        'type' => 'string',
                        'enum' => ['basse', 'haute'],
                        'default' => 'basse',
                    ],
                    'montant' => [
                        'type' => 'number',
                        'minimum' => 0,
                        'maximum' => 1000,
                    ],
                    'urgent' => ['type' => 'boolean'],
                    'etiquettes' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'maxLength' => 5],
                        'minItems' => 1,
                        'maxItems' => 3,
                    ],
                    'email' => ['type' => 'string', 'format' => 'email'],
                ],
            ],
            'ui' => [
                'list_fields' => ['titre', 'priorite', 'status', 'created_at'],
                'per_page' => 10,
            ],
        ];

        return FeatureSchema::fromArrays($definition['schema'], $definition['ui']);
    }
}
