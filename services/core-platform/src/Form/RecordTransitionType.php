<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Transition d'état : l'état source est renvoyé pour détecter une mise à jour
 * concurrente, et l'état cible est revalidé contre le workflow serveur.
 */
final class RecordTransitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', HiddenType::class, [
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('to', HiddenType::class, [
                'constraints' => [new Assert\NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'record_transition',
        ]);
    }
}
