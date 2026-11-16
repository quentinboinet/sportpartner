<?php

namespace App\Repository;

use App\Entity\HealthExercise;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Exercices de l'espace Santé visibles par un utilisateur : les exercices fournis (user NULL) et les siens.
 * Les textes (toutes langues) sont chargés dans la même requête.
 *
 * @extends ServiceEntityRepository<HealthExercise>
 */
class HealthExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HealthExercise::class);
    }

    /** @return list<HealthExercise> */
    public function findVisibleWithTexts(User $user): array
    {
        return $this->visible($user)->getQuery()->getResult();
    }

    public function findVisible(int $id, User $user): ?HealthExercise
    {
        $result = $this->visible($user)->andWhere('e.id = :id')->setParameter('id', $id)->getQuery()->getOneOrNullResult();

        return $result instanceof HealthExercise ? $result : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, HealthExercise> indexés par id ; les ids inconnus ou d'un autre utilisateur sont absents
     */
    public function findVisibleByIds(array $ids, User $user): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<HealthExercise> $rows */
        $rows = $this->visible($user)->andWhere('e.id IN (:ids)')->setParameter('ids', array_values(array_unique($ids)))->getQuery()->getResult();

        $out = [];
        foreach ($rows as $exercise) {
            $out[(int) $exercise->getId()] = $exercise;
        }

        return $out;
    }

    private function visible(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->addSelect('t')
            ->leftJoin('e.texts', 't')
            ->where('e.user IS NULL OR e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.position', 'ASC')
            ->addOrderBy('e.id', 'ASC');
    }
}
