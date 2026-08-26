<?php

namespace App\Form;

use App\Entity\PatientProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('baseDose', IntegerType::class, [
                'label' => 'Dawka bazowa (j.)',
            ])
            ->add('insulinWwRatio', NumberType::class, [
                'label' => 'Przelicznik insulina/WW',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PatientProfile::class,
        ]);
    }
}
