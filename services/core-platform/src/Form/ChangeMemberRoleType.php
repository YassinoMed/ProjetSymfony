<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\MembershipRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ChangeMemberRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('role', EnumType::class, [
            'class' => MembershipRole::class,
            'label' => false,
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
