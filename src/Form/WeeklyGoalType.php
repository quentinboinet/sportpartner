<?php

namespace App\Form;

use App\Entity\WeeklyGoal;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une ligne "objectif par sport" de ProfileType (entrée du CollectionType weeklyGoals).
 * Règles de validation : WeeklyGoal::validate() (au moins une cible, bornes).
 */
class WeeklyGoalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('distanceKm', NumberType::class, [
                'required' => false,
                // Pas d'option scale (elle afficherait "30.0") : WeeklyGoal::setDistanceKm() arrondit au dixième
                'html5'    => true,
                // step "any" : 12,25 n'est pas bloqué par le navigateur, l'entité arrondit à 12,3
                'attr'     => ['placeholder' => '-', 'min' => WeeklyGoal::DISTANCE_KM_MIN, 'max' => WeeklyGoal::DISTANCE_KM_MAX, 'step' => 'any'],
            ])
            ->add('sessions', IntegerType::class, [
                'required' => false,
                'attr'     => ['placeholder' => '-', 'min' => WeeklyGoal::SESSIONS_MIN, 'max' => WeeklyGoal::SESSIONS_MAX],
            ])
            ->add('durationMinutes', DurationHoursType::class, [
                'required' => false,
                'attr'     => ['placeholder' => 'h:mm', 'autocomplete' => 'off', 'maxlength' => 10],
            ])
            ->add('elevationGainM', IntegerType::class, [
                'required' => false,
                'attr'     => ['placeholder' => '-', 'min' => WeeklyGoal::ELEVATION_M_MIN, 'max' => WeeklyGoal::ELEVATION_M_MAX],
            ]);

        // Le sport d'un objectif enregistré ne change pas (on retire la ligne et on en ajoute une) :
        // son champ caché reste affiché et envoyé, mais n'est pas réécrit dans l'entité.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $goal    = $event->getData();
            $options = [];
            if ($goal instanceof WeeklyGoal && $goal->getId() !== null) {
                $options = ['mapped' => false, 'data' => $goal->getSport()];
            }

            $event->getForm()->add('sport', SportHiddenType::class, $options);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => WeeklyGoal::class,
            'label'          => false,
            // Les erreurs de la ligne (aucune cible, sport en double) restent sur la ligne
            'error_bubbling' => false,
        ]);
    }
}
