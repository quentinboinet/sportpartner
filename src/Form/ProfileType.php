<?php

namespace App\Form;

use App\Entity\AthleteProfile;
use App\Service\Goals\WeeklyGoalReconciler;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
                'attr' => ['placeholder' => '-', 'min' => 10, 'max' => 100],
            ])
            ->add('weight', NumberType::class, [
                'required' => false,
                'scale' => 1,
                'constraints' => [new Range(['min' => 20.0, 'max' => 300.0])],
                'attr' => ['placeholder' => '-', 'min' => 20, 'max' => 300, 'step' => 0.1],
            ])
            ->add('restingHr', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 30, 'max' => 120])],
                'attr' => ['placeholder' => '-', 'min' => 30, 'max' => 120],
            ])
            ->add('maxHr', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 100, 'max' => 250])],
                'attr' => ['placeholder' => '-', 'min' => 100, 'max' => 250],
            ])
            ->add('vma', NumberType::class, [
                'required' => false,
                'scale' => 1,
                'constraints' => [new Range(['min' => 5.0, 'max' => 30.0])],
                'attr' => ['placeholder' => '-', 'min' => 5, 'max' => 30, 'step' => 0.1],
            ])
            ->add('ftp', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 50, 'max' => 600])],
                'attr' => ['placeholder' => '-', 'min' => 50, 'max' => 600],
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
            ])
            // Objectifs par sport (table weeklyGoal), portés par l'utilisateur : lignes ajoutées et
            // retirées dans la page (gabarit "prototype"), adder/remover de User (by_reference false).
            ->add('weeklyGoals', CollectionType::class, [
                'property_path'  => 'user.weeklyGoals',
                'entry_type'     => WeeklyGoalType::class,
                'allow_add'      => true,
                'allow_delete'   => true,
                'by_reference'   => false,
                'prototype'      => true,
                'label'          => false,
                'error_bubbling' => false,
                'constraints'    => [
                    new Valid(),
                    new Callback(static function (mixed $goals, ExecutionContextInterface $context): void {
                        if (!is_iterable($goals)) {
                            return;
                        }
                        foreach (WeeklyGoalReconciler::duplicateKeys($goals) as $key) {
                            $context->buildViolation('settings.goals.error.duplicate_sport')
                                ->setTranslationDomain('messages')
                                ->atPath('['.$key.']')
                                ->addViolation();
                        }
                    }),
                ],
            ]);

        $original = [];
        $builder->get('weeklyGoals')
            ->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use (&$original): void {
                $data     = $event->getForm()->getData();
                $original = is_iterable($data) ? [...$data] : [];
            })
            // Après ResizeFormListener (priorité 50), avant l'écriture dans User::weeklyGoals
            ->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event) use (&$original): void {
                $data = $event->getData();
                $event->setData(WeeklyGoalReconciler::reconcile($original, is_iterable($data) ? $data : []));
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AthleteProfile::class]);
    }
}
