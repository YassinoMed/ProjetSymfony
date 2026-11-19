<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\MembershipRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class AddMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse email du compte existant',
                'constraints' => [
                    new Assert\NotBlank(message: 'L\'email est obligatoire.'),
                    new Assert\Email(message: 'Adresse email invalide.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('role', EnumType::class, [
                'class' => MembershipRole::class,
                'label' => 'Rôle',
                'choices' => $options['allowed_roles'],
                'choice_label' => static fn (MembershipRole $role): string => $role->label(),
                'constraints' => [
                    new Assert\NotNull(message: 'Le rôle est obligatoire.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'allowed_roles' => MembershipRole::cases(),
            'data_class' => null,
        ]);
        $resolver->setAllowedTypes('allowed_roles', 'array');
    }
}
