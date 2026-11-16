<?php

namespace App\Service\Health;

use App\Entity\HealthCategory;
use App\Entity\HealthExercise;
use App\Entity\HealthExerciseUserPref;
use App\Entity\User;
use App\Repository\HealthExerciseRepository;
use App\Repository\HealthExerciseUserPrefRepository;

/**
 * Exercices visibles par un utilisateur, prêts à afficher dans sa langue : 2 requêtes (exercices + textes,
 * préférences). Ordre : catégorie (ordre de HealthCategory), exercices personnels d'abord (par nom), puis
 * exercices fournis (ordre de la migration).
 */
class HealthExerciseViewBuilder
{
    public function __construct(
        private readonly HealthExerciseRepository $exercises,
        private readonly HealthExerciseUserPrefRepository $prefs,
    ) {
    }

    /** @return list<HealthExerciseView> */
    public function forUser(User $user, string $locale): array
    {
        $prefs = $this->prefs->findIndexedByExercise($user);
        $views = [];
        foreach ($this->exercises->findVisibleWithTexts($user) as $exercise) {
            $views[] = self::build($exercise, $prefs[(int) $exercise->getId()] ?? null, $locale);
        }

        return self::sort($views);
    }

    public function one(HealthExercise $exercise, User $user, string $locale): HealthExerciseView
    {
        $pref = $exercise->isCustom() ? null : $this->prefs->findOneFor($user, $exercise);

        return self::build($exercise, $pref, $locale);
    }

    public static function build(HealthExercise $exercise, ?HealthExerciseUserPref $pref, string $locale): HealthExerciseView
    {
        $text = $exercise->textFor($locale);

        return new HealthExerciseView(
            id: (int) $exercise->getId(),
            slug: $exercise->getSlug(),
            custom: $exercise->isCustom(),
            textLocale: $text?->getLocale() ?? substr($locale, 0, 2),
            name: $text?->getName() ?? (string) $exercise->getSlug(),
            category: $exercise->getCategory(),
            level: $exercise->getLevel(),
            areas: $exercise->getBodyAreas(),
            equipment: $exercise->getEquipment(),
            unilateral: $exercise->isUnilateral(),
            mode: $exercise->getDosageMode(),
            sets: $exercise->getDefaultSets(),
            value: $exercise->getDefaultValue(),
            rest: $exercise->getDefaultRestSeconds(),
            targetMuscles: $text?->getTargetMuscles(),
            startPosition: $text?->getStartPosition(),
            steps: $text?->getSteps() ?? [],
            breathing: $text?->getBreathing(),
            mistakes: $text?->getMistakes() ?? [],
            easier: $text?->getEasier(),
            harder: $text?->getHarder(),
            videoUrl: $exercise->isCustom() ? $exercise->getVideoUrl() : $pref?->getVideoUrl(),
            hidden: !$exercise->isCustom() && $pref !== null && $pref->isHidden(),
        );
    }

    /**
     * @param list<HealthExerciseView> $views dans l'ordre du dépôt (position)
     *
     * @return list<HealthExerciseView>
     */
    public static function sort(array $views): array
    {
        $rank = array_flip(array_map(static fn (HealthCategory $c): string => $c->value, HealthCategory::cases()));
        // usort est stable : à clé égale, les exercices fournis gardent l'ordre de la migration
        usort($views, static fn (HealthExerciseView $a, HealthExerciseView $b): int => [
            $rank[$a->category->value], $a->custom ? 0 : 1, $a->custom ? TextFold::fold($a->name) : '',
        ] <=> [
            $rank[$b->category->value], $b->custom ? 0 : 1, $b->custom ? TextFold::fold($b->name) : '',
        ]);

        return $views;
    }
}
