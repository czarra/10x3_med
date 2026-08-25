<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email')
            ->add('plainPassword', PasswordType::class, [
                // instead of being set onto the object directly,
                // this is read and encoded in the controller
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(
                        message: 'Podaj hasło.',
                    ),
                    new Length(
                        min: 8,
                        minMessage: 'Hasło musi mieć co najmniej {{ limit }} znaków.',
                        max: PasswordHasherInterface::MAX_PASSWORD_LENGTH,
                    ),
                    new Regex(
                        pattern: '/\d/',
                        message: 'Hasło musi zawierać co najmniej jedną cyfrę.',
                    ),
                    new Regex(
                        pattern: '/[^a-zA-Z0-9]/',
                        message: 'Hasło musi zawierać co najmniej jeden znak specjalny.',
                    ),
                    new NotCompromisedPassword(),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
