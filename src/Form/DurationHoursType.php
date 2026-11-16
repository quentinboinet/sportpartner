<?php

namespace App\Form;

use App\Form\DataTransformer\HoursToMinutesTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Champ texte d'une durée saisie en heures ("5", "5,5", "5.5", "5:30"), donnée en minutes (int|null).
 *
 * Le message d'erreur de saisie est traduit ici (domaine "messages") : le validateur ne
 * traduit invalid_message que dans le domaine "validators", absent du projet.
 */
class DurationHoursType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addViewTransformer(new HoursToMinutesTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invalid_message' => fn (Options $options): string => $this->translator->trans('settings.goals.error.duration_invalid'),
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
