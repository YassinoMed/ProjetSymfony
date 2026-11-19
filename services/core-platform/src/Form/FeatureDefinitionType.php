<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class FeatureDefinitionType extends AbstractType
{
    public const string EXAMPLE_JSON = <<<'JSON'
{
    "schema": {
        "type": "object",
        "additionalProperties": false,
        "required": ["titre"],
        "properties": {
            "titre": {
                "type": "string",
                "title": "Titre",
                "minLength": 3,
                "maxLength": 160
            },
            "priorite": {
                "type": "string",
                "title": "Priorité",
                "enum": ["basse", "normale", "haute"],
                "default": "normale"
            },
            "montant": {
                "type": "number",
                "title": "Montant",
                "minimum": 0,
                "maximum": 1000000
            },
            "echeance": {
                "type": "string",
                "format": "date",
                "title": "Échéance"
            },
            "urgent": {
                "type": "boolean",
                "title": "Urgent"
            },
            "etiquettes": {
                "type": "array",
                "title": "Étiquettes",
                "items": {"type": "string", "maxLength": 30},
                "maxItems": 5
            }
        }
    },
    "ui": {
        "list_fields": ["titre", "priorite", "status", "echeance"],
        "per_page": 25
    },
    "workflow": {
        "initial": "brouillon",
        "states": {
            "brouillon": {"label": "Brouillon"},
            "ouvert": {"label": "Ouvert"},
            "ferme": {"label": "Fermé"}
        },
        "transitions": [
            {
                "from": "brouillon",
                "to": "ouvert",
                "label": "Ouvrir",
                "roles": ["owner", "admin", "member"]
            },
            {
                "from": "ouvert",
                "to": "ferme",
                "label": "Fermer",
                "roles": ["owner", "admin"]
            }
        ]
    }
}
JSON;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('key', TextType::class, [
                'label' => 'Clé technique',
                'disabled' => $options['lock_key'],
                'help' => 'Minuscules, chiffres et underscores. La clé est immuable après création.',
                'constraints' => [
                    new Assert\NotBlank(message: 'La clé est obligatoire.'),
                    new Assert\Regex(
                        pattern: '/^[a-z][a-z0-9_]{1,40}$/',
                        message: 'La clé doit commencer par une minuscule et contenir uniquement des minuscules, chiffres et underscores (2 à 41 caractères).',
                    ),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nom de la fonctionnalité',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nom est obligatoire.'),
                    new Assert\Length(min: 2, max: 120),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
                'constraints' => [
                    new Assert\Length(max: 500),
                ],
            ])
            ->add('definition', TextareaType::class, [
                'label' => 'Définition JSON (schema, ui, workflow)',
                'attr' => [
                    'rows' => 28,
                    'spellcheck' => 'false',
                    'class' => 'code-input',
                ],
                'help' => 'Allowlist stricte : primitives, tableaux de primitives, aucun $ref, pattern ni objet imbriqué.',
                'constraints' => [
                    new Assert\NotBlank(message: 'La définition JSON est obligatoire.'),
                    new Assert\Json(message: 'Le contenu n\'est pas un JSON valide.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'lock_key' => false,
            'definition' => self::EXAMPLE_JSON,
        ]);

        $resolver->setAllowedTypes('lock_key', 'bool');
        $resolver->setAllowedTypes('definition', 'string');
    }
}
