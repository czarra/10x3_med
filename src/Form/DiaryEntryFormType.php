<?php

namespace App\Form;

use App\Entity\ActivityIntensity;
use App\Entity\DiaryEntry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DiaryEntryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('glycemiaMgDl', IntegerType::class, [
                'label' => 'Poziom glikemii (mg/dL)',
            ])
            ->add('measuredAt', DateTimeType::class, [
                'label' => 'Data i godzina pomiaru',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('ww', NumberType::class, [
                'label' => 'Wymienniki węglowodanowe (WW)',
                'required' => false,
                'scale' => 1,
            ])
            ->add('insulinDose', NumberType::class, [
                'label' => 'Dawka insuliny (j.)',
                'required' => false,
                'scale' => 1,
            ])
            ->add('activityIntensity', EnumType::class, [
                'label' => 'Intensywność wysiłku',
                'class' => ActivityIntensity::class,
                'required' => false,
                'placeholder' => 'Brak',
            ])
            ->add('activityDurationMinutes', IntegerType::class, [
                'label' => 'Czas trwania wysiłku (min)',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DiaryEntry::class,
        ]);
    }
}
