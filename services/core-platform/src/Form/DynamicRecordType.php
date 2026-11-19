<?php

declare(strict_types=1);

namespace App\Form;

use App\Feature\FeatureField;
use App\Feature\FeatureSchema;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire généré à partir du JSON Schema courant d'une fonctionnalité.
 *
 * Aucun fichier PHP n'est créé par fonctionnalité : le type de champ Symfony
 * est choisi à partir du type JSON Schema validé (primitives et tableaux de
 * primitives uniquement).
 */
final class DynamicRecordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var FeatureSchema $schema */
        $schema = $options['schema'];

        foreach ($schema->fields() as $name => $field) {
            $this->addField($builder, $field);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);

        $resolver->setRequired('schema');
        $resolver->setAllowedTypes('schema', FeatureSchema::class);
    }

    private function addField(FormBuilderInterface $builder, FeatureField $field): void
    {
        $common = [
            'label' => $field->label,
            'required' => false,
            'help' => $field->description,
        ];

        if ('array' === $field->type) {
            $help = 'Valeurs séparées par des virgules.';
            if (null !== $field->maxItems) {
                $help .= sprintf(' %d élément(s) maximum.', $field->maxItems);
            }
            $common['help'] = trim(($field->description ?? '').' '.$help);

            $builder->add($field->name, TextType::class, $common + [
                'attr' => ['placeholder' => 'valeur 1, valeur 2'],
            ]);

            return;
        }

        if ([] !== $field->enum) {
            $choices = [];
            foreach ($field->enum as $value) {
                $label = \is_string($value) ? ucfirst($value) : (string) $value;
                $choices[$label] = $value;
            }

            $builder->add($field->name, ChoiceType::class, $common + [
                'choices' => $choices,
                'placeholder' => $field->required ? false : '— Aucune valeur —',
            ]);

            return;
        }

        $builder->add($field->name, match ($field->type) {
            'integer' => IntegerType::class,
            'number' => NumberType::class,
            'boolean' => CheckboxType::class,
            default => TextType::class,
        }, $common + $this->constraints($field));
    }

    /**
     * @return array<string, mixed>
     */
    private function constraints(FeatureField $field): array
    {
        if ('string' !== $field->type) {
            $attr = [];

            if (null !== $field->minimum) {
                $attr['min'] = $field->minimum;
            }

            if (null !== $field->maximum) {
                $attr['max'] = $field->maximum;
            }

            return [] === $attr ? [] : ['attr' => $attr];
        }

        $attr = match ($field->format) {
            'date' => ['type' => 'date'],
            'email' => ['type' => 'email'],
            'uri' => ['type' => 'url'],
            default => [],
        };

        if (null !== $field->minLength) {
            $attr['minlength'] = $field->minLength;
        }

        if (null !== $field->maxLength) {
            $attr['maxlength'] = $field->maxLength;
        }

        return [] === $attr ? [] : ['attr' => $attr];
    }
}
