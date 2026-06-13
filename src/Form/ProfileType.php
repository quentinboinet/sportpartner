<?php

namespace App\Form;

use App\Entity\AthleteProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;

class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('handle', TextType::class, [
                'required' => false,
                'constraints' => [new Length(['max' => 50])],
                'attr' => ['placeholder' => 'camrun', 'maxlength' => 50],
            ])
            ->add('age', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 10, 'max' => 100])],
                'attr' => ['placeholder' => '—', 'min' => 10, 'max' => 100],
            ])
            ->add('weight', NumberType::class, [
                'required' => false,
                'scale' => 1,
                'constraints' => [new Range(['min' => 20.0, 'max' => 300.0])],
                'attr' => ['placeholder' => '—', 'min' => 20, 'max' => 300, 'step' => 0.1],
            ])
            ->add('restingHr', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 30, 'max' => 120])],
                'attr' => ['placeholder' => '—', 'min' => 30, 'max' => 120],
            ])
            ->add('maxHr', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 100, 'max' => 250])],
                'attr' => ['placeholder' => '—', 'min' => 100, 'max' => 250],
            ])
            ->add('vma', NumberType::class, [
                'required' => false,
                'scale' => 1,
                'constraints' => [new Range(['min' => 5.0, 'max' => 30.0])],
                'attr' => ['placeholder' => '—', 'min' => 5, 'max' => 30, 'step' => 0.1],
            ])
            ->add('ftp', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 50, 'max' => 600])],
                'attr' => ['placeholder' => '—', 'min' => 50, 'max' => 600],
            ])
            ->add('weeklyDistanceGoalKm', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 1, 'max' => 1000])],
                'attr' => ['placeholder' => '150', 'min' => 1, 'max' => 1000],
            ])
            ->add('weeklySessionsGoal', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 1, 'max' => 30])],
                'attr' => ['placeholder' => '7', 'min' => 1, 'max' => 30],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AthleteProfile::class]);
    }
}
