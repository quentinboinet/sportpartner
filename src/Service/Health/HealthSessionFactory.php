<?php

namespace App\Service\Health;

use App\Entity\HealthExercise;
use App\Entity\HealthSession;
use App\Entity\HealthSessionExercise;
use App\Entity\User;

/**
 * Construit la séance à enregistrer à partir de la saisie validée (HealthSessionPayloadValidator) et des
 * exercices visibles par l'utilisateur. Calcul pur, rien n'est persisté ici.
 *
 * Chaque exercice doit être visible (fourni, ou personnel de l'utilisateur) et sa valeur prévue rester dans les
 * bornes de son mode (HealthDosageMode) ; sinon toute la séance est refusée. Le nom, la catégorie, le mode et
 * « chaque côté » sont recopiés depuis l'exercice (jamais depuis le navigateur), le nom dans la langue $locale.
 */
final class HealthSessionFactory
{
    /**
     * @param array<int, HealthExercise>   $exercises   HealthExerciseRepository::findVisibleByIds()
     * @param \Closure(string): string     $defaultName nom par défaut selon HealthActivityMapper::sessionKind()
     *
     * @throws InvalidHealthPayloadException
     */
    public static function create(User $user, HealthSessionPayload $payload, array $exercises, string $locale, \Closure $defaultName): HealthSession
    {
        $session = (new HealthSession($user))
            ->setStartedAt($payload->startedAt)
            ->setTimezone($payload->timezone)
            ->setDurationSeconds($payload->durationSeconds)
            ->setElapsedSeconds($payload->elapsedSeconds)
            ->setRpe($payload->rpe)
            ->setNotes($payload->notes);

        foreach ($payload->exercises as $position => $item) {
            $exercise = $exercises[$item->exerciseId] ?? null;
            if ($exercise === null) {
                throw new InvalidHealthPayloadException('unknown_exercise', sprintf('Exercise %d is not available.', $item->exerciseId));
            }
            $mode = $exercise->getDosageMode();
            if ($item->value < $mode->minValue() || $item->value > $mode->maxValue()) {
                throw new InvalidHealthPayloadException('invalid_payload', sprintf('exercises[%d].value must be from %d to %d.', $position, $mode->minValue(), $mode->maxValue()));
            }

            $session->addExercise((new HealthSessionExercise())
                ->setExercise($exercise)
                ->setPosition($position)
                ->setName(mb_substr($exercise->textFor($locale)?->getName() ?? (string) $exercise->getSlug(), 0, 80))
                ->setCategory($exercise->getCategory())
                ->setUnilateral($exercise->isUnilateral())
                ->setMode($mode)
                ->setSets($item->sets)
                ->setValue($item->value)
                ->setRestSeconds($item->rest)
                ->setPerformed($item->performed));
        }

        $session->setName($payload->name ?? mb_substr($defaultName(HealthActivityMapper::sessionKind(HealthActivityMapper::doneCategories($session))), 0, HealthLimits::SESSION_NAME_MAX));

        return $session;
    }
}
