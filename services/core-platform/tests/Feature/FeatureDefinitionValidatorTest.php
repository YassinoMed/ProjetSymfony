<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Feature\DefinitionValidationException;
use App\Feature\FeatureDefinitionValidator;
use PHPUnit\Framework\TestCase;

final class FeatureDefinitionValidatorTest extends TestCase
{
    private FeatureDefinitionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FeatureDefinitionValidator();
    }

    public function testValidDefinitionIsNormalizedAndDeterministicallyHashed(): void
    {
        $definition = $this->validDefinition();
        $draft = $this->validator->validate('incident', 'Incidents', 'Suivi des incidents', $definition);

        self::assertSame(['titre'], $draft->normalizedSchema['required']);
        self::assertSame(['brouillon', 'ouvert', 'ferme'], array_keys($draft->workflow->states()));
        self::assertSame(['titre', 'priorite', 'status', 'montant'], $draft->normalizedUi['list_fields']);
        self::assertSame(25, $draft->schema->perPage());
        self::assertCount(5, $draft->schema->fieldNames());
        self::assertSame($draft->hash, $draft->hash);

        // L'ordre des clés associatives ne doit jamais changer l'empreinte.
        $reordered = [
            'workflow' => $definition['workflow'],
            'schema' => [
                'properties' => array_reverse($definition['schema']['properties'], true),
                'required' => $definition['schema']['required'],
                'additionalProperties' => false,
                'type' => 'object',
            ],
            'ui' => $definition['ui'],
        ];

        $second = $this->validator->validate('incident', 'Incidents', 'Suivi des incidents', $reordered);
        self::assertSame($draft->hash, $second->hash);
    }

    public function testRejectsUnknownTopLevelKey(): void
    {
        $definition = $this->validDefinition();
        $definition['extra'] = true;

        $this->assertRejected($definition, 'definition.extra');
    }

    public function testRejectsRefPatternAndNestedObject(): void
    {
        $withRef = $this->validDefinition();
        $withRef['schema']['properties']['titre']['$ref'] = '#/definitions/x';
        $this->assertRejected($withRef, '$ref');

        $withPattern = $this->validDefinition();
        $withPattern['schema']['properties']['titre']['pattern'] = '^[a-z]+$';
        $this->assertRejected($withPattern, 'pattern');

        $withNested = $this->validDefinition();
        $withNested['schema']['properties']['meta'] = [
            'type' => 'object',
            'properties' => ['x' => ['type' => 'string']],
        ];
        $this->assertRejected($withNested, 'meta.type');
    }

    public function testRejectsAdditionalPropertiesAndReservedField(): void
    {
        $open = $this->validDefinition();
        $open['schema']['additionalProperties'] = true;
        $this->assertRejected($open, 'additionalProperties');

        $reserved = $this->validDefinition();
        $reserved['schema']['properties']['id'] = ['type' => 'string'];
        $this->assertRejected($reserved, 'réservé');
    }

    public function testRejectsRequiredFieldThatDoesNotExist(): void
    {
        $definition = $this->validDefinition();
        $definition['schema']['required'] = ['titre', 'inconnu'];

        $this->assertRejected($definition, 'inconnu');
    }

    public function testRejectsUnknownRoleAndUnreachableState(): void
    {
        $unknownRole = $this->validDefinition();
        $unknownRole['workflow']['transitions'][0]['roles'] = ['owner', 'hacker'];
        $this->assertRejected($unknownRole, 'hacker');

        $unreachable = $this->validDefinition();
        $unreachable['workflow']['states']['archive'] = ['label' => 'Archivé'];
        $this->assertRejected($unreachable, 'archive');
    }

    public function testRejectsDuplicateTransitionAndSelfTransition(): void
    {
        $duplicate = $this->validDefinition();
        $duplicate['workflow']['transitions'][] = $duplicate['workflow']['transitions'][0];
        $this->assertRejected($duplicate, 'dupliquée');

        $self = $this->validDefinition();
        $self['workflow']['transitions'][0]['to'] = 'brouillon';
        $this->assertRejected($self, 'changer d\'état');
    }

    public function testRejectsInvalidKeyAndReservedKey(): void
    {
        $definition = $this->validDefinition();

        try {
            $this->validator->validate('Incident', 'Incidents', null, $definition);
            self::fail('Une clé invalide aurait dû être refusée.');
        } catch (DefinitionValidationException $exception) {
            self::assertStringContainsString('key', implode("\n", $exception->getErrors()));
        }

        $this->expectException(DefinitionValidationException::class);
        $this->validator->validate('new', 'Incidents', null, $definition);
    }

    public function testRejectsInvalidUiListFieldAndPerPage(): void
    {
        $unknownField = $this->validDefinition();
        $unknownField['ui']['list_fields'] = ['titre', 'inconnu'];
        $this->assertRejected($unknownField, 'inconnu');

        $badPerPage = $this->validDefinition();
        $badPerPage['ui']['per_page'] = 3;
        $this->assertRejected($badPerPage, 'per_page');
    }

    public function testAcceptsArrayOfIntegersAndSortsRequired(): void
    {
        $definition = $this->validDefinition();
        $definition['schema']['required'] = ['titre', 'montant'];
        $definition['schema']['properties']['scores'] = [
            'type' => 'array',
            'title' => 'Scores',
            'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
            'minItems' => 1,
            'maxItems' => 3,
        ];

        $draft = $this->validator->validate('score', 'Scores', null, $definition);

        self::assertSame(['montant', 'titre'], $draft->normalizedSchema['required']);
        self::assertSame('integer', $draft->normalizedSchema['properties']['scores']['items']['type']);
        self::assertCount(6, $draft->schema->fieldNames());
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function assertRejected(array $definition, string $needle): void
    {
        try {
            $this->validator->validate('incident', 'Incidents', null, $definition);
            self::fail('La définition aurait dû être refusée.');
        } catch (DefinitionValidationException $exception) {
            self::assertNotEmpty($exception->getErrors());
            self::assertStringContainsString($needle, implode("\n", $exception->getErrors()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validDefinition(): array
    {
        return [
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['titre'],
                'properties' => [
                    'titre' => [
                        'type' => 'string',
                        'title' => 'Titre',
                        'minLength' => 3,
                        'maxLength' => 120,
                    ],
                    'priorite' => [
                        'type' => 'string',
                        'title' => 'Priorité',
                        'enum' => ['basse', 'normale', 'haute'],
                        'default' => 'normale',
                    ],
                    'montant' => [
                        'type' => 'number',
                        'title' => 'Montant',
                        'minimum' => 0,
                        'maximum' => 1000000,
                    ],
                    'urgent' => [
                        'type' => 'boolean',
                        'title' => 'Urgent',
                        'default' => false,
                    ],
                    'etiquettes' => [
                        'type' => 'array',
                        'title' => 'Étiquettes',
                        'items' => ['type' => 'string', 'maxLength' => 30],
                        'maxItems' => 5,
                    ],
                ],
            ],
            'ui' => [
                'list_fields' => ['titre', 'priorite', 'status', 'montant'],
                'per_page' => 25,
            ],
            'workflow' => [
                'initial' => 'brouillon',
                'states' => [
                    'brouillon' => ['label' => 'Brouillon'],
                    'ouvert' => ['label' => 'Ouvert'],
                    'ferme' => ['label' => 'Fermé'],
                ],
                'transitions' => [
                    [
                        'from' => 'brouillon',
                        'to' => 'ouvert',
                        'label' => 'Ouvrir',
                        'roles' => ['owner', 'admin', 'member'],
                    ],
                    [
                        'from' => 'ouvert',
                        'to' => 'ferme',
                        'label' => 'Fermer',
                        'roles' => ['owner', 'admin'],
                    ],
                ],
            ],
        ];
    }
}
