<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WeeklyGoal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WeeklyGoal>
 */
class WeeklyGoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyGoal::class);
    }

    /**
     * Objectifs par sport de l'utilisateur, sport chargé d'avance, dans l'ordre des paramètres.
     *
     * @return list<WeeklyGoal>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('s')
            ->join('g.sport', 's')
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->orderBy('g.position', 'ASC')
            ->addOrderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
