<?php

namespace App\Repository;

use App\Entity\HealthExercise;
use App\Entity\HealthExerciseUserPref;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HealthExerciseUserPref>
 */
class HealthExerciseUserPrefRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HealthExerciseUserPref::class);
    }

    /**
     * Préférences de l'utilisateur indexées par id d'exercice, en une requête.
     *
     * @return array<int, HealthExerciseUserPref>
     */
    public function findIndexedByExercise(User $user): array
    {
        /** @var list<HealthExerciseUserPref> $prefs */
        $prefs = $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($prefs as $pref) {
            $out[(int) $pref->getExercise()->getId()] = $pref;
        }

        return $out;
    }

    public function findOneFor(User $user, HealthExercise $exercise): ?HealthExerciseUserPref
    {
        return $this->findOneBy(['user' => $user, 'exercise' => $exercise]);
    }
}
