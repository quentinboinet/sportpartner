<?php

namespace App\Service\User;

use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Supprime un compte et toutes ses données, en une seule transaction (Paramètres > Compte).
 *
 * Une requête suffit, DELETE FROM users : chaque table qui dépend de l'utilisateur y est reliée par une
 * clé étrangère ON DELETE CASCADE, directement ou par sa table parente (audit dans
 * tests/Unit/Service/User/AccountDeletionCoverageTest.php, qui échoue si une nouvelle relation vers
 * User ou vers une donnée de l'utilisateur n'est ni CASCADE ni SET NULL) :
 *
 * - users : AthleteProfile, Meal, activities, race, gear, userAvatar, weeklyGoal, healthExercise
 *   (exercices personnels), healthExerciseUserPref, healthSession ;
 * - activities : activityFuel (healthSession.activity_id passe à NULL) ;
 * - race : raceRoute, raceCheckpoint, raceScenario, raceShare ; raceScenario : raceScenarioSplit,
 *   raceNutritionPlan (raceShare.scenario_id passe à NULL) ; raceCheckpoint : raceScenarioSplit,
 *   raceNutritionItem ; raceNutritionPlan : raceNutritionItem ;
 * - gear : activities.gear_id passe à NULL ;
 * - healthExercise : healthExerciseText, healthExerciseUserPref (healthSessionExercise.exercise_id
 *   passe à NULL) ; healthSession : healthSessionExercise.
 *
 * Jamais touchées : les tables de référence sports et gearBrand, les exercices Santé fournis
 * (user_id NULL) et leurs textes. Rien sur disque : photo (userAvatar) et tracés GPX (raceRoute)
 * sont en base. Une table sans cascade bloquerait la requête (clé étrangère) : la transaction est
 * alors annulée et rien n'est supprimé.
 */
final class AccountDeleter
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @throws \LogicException   utilisateur jamais enregistré
     * @throws \RuntimeException compte introuvable (déjà supprimé) : transaction annulée
     * @throws \Throwable        toute erreur de la base : transaction annulée, rien n'est supprimé
     */
    public function delete(User $user): void
    {
        $id = $user->getId() ?? throw new \LogicException('Cannot delete a user that was never saved.');
        $conn = $this->em->getConnection();

        $conn->beginTransaction();
        try {
            $deleted = (int) $conn->executeStatement('DELETE FROM users WHERE id = ?', [$id], [ParameterType::INTEGER]);
            if ($deleted !== 1) {
                throw new \RuntimeException(sprintf('Expected to delete 1 user, deleted %d.', $deleted));
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        // L'utilisateur supprimé ne doit plus être écrit par un flush ultérieur de la requête
        $this->em->detach($user);
    }
}
