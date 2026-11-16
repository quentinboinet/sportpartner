<?php

namespace App\Form;

use App\Entity\Sport;
use App\Repository\SportRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Champ caché portant l'id d'un sport (ligne d'objectif choisie dans le menu "Ajouter un sport").
 */
class SportHiddenType extends AbstractType
{
    public function __construct(
        private readonly SportRepository $sports,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?Sport $sport): string => $sport?->getId() === null ? '' : (string) $sport->getId(),
            function (mixed $id): ?Sport {
                if ($id === null || $id === '') {
                    return null;
                }
                if (!\is_string($id) || !ctype_digit($id)) {
                    throw new TransformationFailedException('Invalid sport id.');
                }

                return $this->sports->find((int) $id) ?? throw new TransformationFailedException('Unknown sport.');
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Sinon déduit de l'option "data" (un Sport) : la donnée de vue est l'id, une chaîne
            'data_class'      => null,
            'invalid_message' => fn (Options $options): string => $this->translator->trans('settings.goals.error.sport_invalid'),
        ]);
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }
}
